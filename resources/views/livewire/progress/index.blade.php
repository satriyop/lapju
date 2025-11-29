<?php

use App\Models\Office;
use App\Models\ProgressPhoto;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use WithFileUploads;

    public ?int $selectedProjectId = null;

    public ?int $activeTab = null;

    public array $expandedTasks = [];

    public array $progressData = [];

    public string $selectedDate;

    public string $maxDate;

    public array $photos = [];

    public array $existingPhotos = [];

    public array $photoCaptions = [];

    public bool $showSuccess = false;

    public string $successMessage = '';

    /**
     * Cached user office with level relationship.
     * Prevents redundant queries across mount(), saveProgress(), uploadPhoto(), and with().
     */
    private ?Office $cachedUserOffice = null;

    private bool $userOfficeLoaded = false;

    /**
     * Cached selected project with relationships.
     * Prevents redundant queries across with(), updatedSelectedDate(), saveProgress(), uploadPhoto().
     */
    private ?Project $cachedProject = null;

    private ?int $cachedProjectId = null;

    /**
     * Get user's office with level relationship (cached).
     */
    private function getUserOffice(): ?Office
    {
        $currentUser = auth()->user();

        if (! $currentUser->office_id) {
            return null;
        }

        if (! $this->userOfficeLoaded) {
            $this->cachedUserOffice = Office::with('level')->find($currentUser->office_id);
            $this->userOfficeLoaded = true;
        }

        return $this->cachedUserOffice;
    }

    /**
     * Get selected project with office relationship (cached).
     * Cache invalidates when selectedProjectId changes.
     */
    private function getSelectedProject(): ?Project
    {
        if (! $this->selectedProjectId) {
            return null;
        }

        // Invalidate cache if project ID changed
        if ($this->cachedProjectId !== $this->selectedProjectId) {
            $this->cachedProject = Project::with('office')->find($this->selectedProjectId);
            $this->cachedProjectId = $this->selectedProjectId;
        }

        return $this->cachedProject;
    }

    public function mount(): void
    {
        $this->selectedDate = now()->format('Y-m-d');
        $this->maxDate = now()->format('Y-m-d');

        // Auto-select first project if available (with Reporter and Manager coverage filtering)
        $currentUser = auth()->user();
        $projectQuery = Project::query();

        // Reporters can only see their assigned projects
        if ($currentUser->hasRole('Reporter')) {
            $projectQuery->whereHas('users', fn ($q) => $q->where('users.id', $currentUser->id));
        }
        // Kodim Admins can only see projects in Koramils under their Kodim
        elseif ($currentUser->hasRole('Kodim Admin') && $currentUser->office_id) {
            $userOffice = $this->getUserOffice();
            if ($userOffice && $userOffice->level->level === 3) {
                $projectQuery->whereHas('office', function ($q) use ($currentUser) {
                    $q->where('parent_id', $currentUser->office_id);
                });
            }
        }
        // Koramil Admins can only see projects in their own Koramil
        elseif ($currentUser->hasRole('Koramil Admin') && $currentUser->office_id) {
            $projectQuery->where('office_id', $currentUser->office_id);
        }

        $firstProject = $projectQuery->first();
        if ($firstProject) {
            $this->selectedProjectId = $firstProject->id;
            $this->loadProgressData();
            $this->loadExistingPhotos();
            // Note: activeTab will be set in with() to avoid duplicate query
        }
    }

    public function loadProgressData(): void
    {
        if (! $this->selectedProjectId) {
            return;
        }

        // Load progress for selected date for all tasks in this project
        // Use whereDate for proper date comparison in SQLite
        $dateProgress = TaskProgress::where('project_id', $this->selectedProjectId)
            ->whereDate('progress_date', $this->selectedDate)
            ->where('user_id', Auth::id())
            ->get()
            ->keyBy('task_id');

        $this->progressData = $dateProgress->map(function ($progress) {
            return [
                'percentage' => (float) $progress->percentage,
                'notes' => $progress->notes,
            ];
        })->toArray();
    }

    /**
     * Get latest progress for all tasks in the selected project up to the selected date.
     *
     * Optimized: Uses GROUP BY + in-memory filtering instead of correlated subquery
     * for better performance with large datasets (16,000+ progress entries).
     *
     * @return array<int, array{percentage: float, progress_date: \Carbon\Carbon, notes: ?string}>
     */
    private function getLatestProgressMap(): array
    {
        if (! $this->selectedProjectId) {
            return [];
        }

        // Query 1: Get latest date per task (single GROUP BY - fast with index)
        $latestDates = TaskProgress::where('project_id', $this->selectedProjectId)
            ->whereDate('progress_date', '<=', $this->selectedDate)
            ->selectRaw('task_id, MAX(progress_date) as max_date')
            ->groupBy('task_id')
            ->get()
            ->keyBy('task_id');

        if ($latestDates->isEmpty()) {
            return [];
        }

        // Query 2: Fetch all progress entries and filter in memory
        // This avoids the expensive correlated subquery
        $allProgress = TaskProgress::where('project_id', $this->selectedProjectId)
            ->whereDate('progress_date', '<=', $this->selectedDate)
            ->get();

        $progressMap = [];
        foreach ($allProgress as $entry) {
            $maxDateEntry = $latestDates[$entry->task_id] ?? null;
            if (! $maxDateEntry) {
                continue;
            }

            // Compare dates (handle both Carbon and string formats)
            $entryDate = $entry->progress_date instanceof Carbon
                ? $entry->progress_date->format('Y-m-d')
                : Carbon::parse($entry->progress_date)->format('Y-m-d');

            $maxDate = Carbon::parse($maxDateEntry->max_date)->format('Y-m-d');

            if ($entryDate === $maxDate) {
                $progressMap[$entry->task_id] = [
                    'percentage' => (float) $entry->percentage,
                    'progress_date' => $entry->progress_date,
                    'notes' => $entry->notes,
                ];
            }
        }

        return $progressMap;
    }

    /**
     * Calculate average progress for root tasks only by averaging their leaf descendants.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Task>  $allTasks
     * @param  array<int, array>  $latestProgressMap
     * @return array<int, array{percentage: float, task_count: int}>
     */
    private function calculateParentProgress($allTasks, array $latestProgressMap): array
    {
        $parentProgress = [];

        // Get all leaf tasks (no children)
        $leafTasks = $allTasks->filter(fn ($task) => $task->children->count() === 0);

        // Only calculate average for root tasks (parent_id = null)
        foreach ($allTasks as $task) {
            if ($task->children->count() > 0 && $task->parent_id === null) {
                // Get all leaf descendants of this root task
                $leafDescendants = $leafTasks->filter(function ($leaf) use ($task) {
                    return $leaf->_lft > $task->_lft && $leaf->_rgt < $task->_rgt;
                });

                if ($leafDescendants->isNotEmpty()) {
                    $totalPercentage = 0;
                    $taskCount = 0;

                    foreach ($leafDescendants as $leaf) {
                        if (isset($latestProgressMap[$leaf->id])) {
                            $totalPercentage += $latestProgressMap[$leaf->id]['percentage'];
                        }
                        $taskCount++;
                    }

                    $parentProgress[$task->id] = [
                        'percentage' => $taskCount > 0 ? round($totalPercentage / $taskCount, 2) : 0,
                        'task_count' => $taskCount,
                    ];
                }
            }
        }

        return $parentProgress;
    }

    public function updatedSelectedProjectId(): void
    {
        $this->loadProgressData();
        $this->loadExistingPhotos();

        // Reset activeTab so with() will set it to first tab of new project
        $this->activeTab = null;
    }

    public function updatedSelectedDate(): void
    {
        // Get project to validate date range (cached)
        $project = $this->getSelectedProject();

        // Validate that date is not in the future
        if ($this->selectedDate > $this->maxDate) {
            $this->selectedDate = $this->maxDate;
            $this->addError('selectedDate', 'Cannot enter progress for future dates.');

            return;
        }

        // Validate date is within project date range (if project dates are set)
        if ($project && $project->start_date && $project->end_date) {
            $selectedDate = Carbon::parse($this->selectedDate);
            $projectStart = Carbon::parse($project->start_date)->startOfDay();
            $projectEnd = Carbon::parse($project->end_date)->endOfDay();

            if ($selectedDate->lt($projectStart)) {
                $this->selectedDate = $projectStart->format('Y-m-d');
                $this->addError(
                    'selectedDate',
                    'Progress date adjusted to project start date ('.$projectStart->format('M d, Y').').'
                );
            } elseif ($selectedDate->gt($projectEnd)) {
                $this->selectedDate = $projectEnd->format('Y-m-d');
                $this->addError(
                    'selectedDate',
                    'Progress date adjusted to project end date ('.$projectEnd->format('M d, Y').').'
                );
            }
        }

        $this->loadProgressData();
        $this->loadExistingPhotos();
    }

    public function setActiveTab(int $taskId): void
    {
        $this->activeTab = $taskId;

        // Auto-expand all descendant parent tasks (not leaf tasks) under this root task
        $rootTask = Task::find($taskId);
        if ($rootTask) {
            $descendantParentTasks = Task::where('project_id', $rootTask->project_id)
                ->where('_lft', '>', $rootTask->_lft)
                ->where('_rgt', '<', $rootTask->_rgt)
                ->whereHas('children') // Only parent tasks (have children)
                ->pluck('id')
                ->toArray();

            $this->expandedTasks = array_unique(array_merge($this->expandedTasks, $descendantParentTasks));
        }
    }

    public function toggleExpand(int $taskId): void
    {
        if (in_array($taskId, $this->expandedTasks)) {
            $this->expandedTasks = array_values(array_diff($this->expandedTasks, [$taskId]));
        } else {
            $this->expandedTasks[] = $taskId;
        }
    }

    public function saveProgress(int $taskId): void
    {
        if (! $this->selectedProjectId) {
            return;
        }

        // Get project to validate date range and authorization (cached)
        $project = $this->getSelectedProject();

        if (! $project) {
            $this->addError('project', 'Project not found.');

            return;
        }

        // AUTHORIZATION: Verify user has access to this project
        $currentUser = auth()->user();

        // Admins have full access
        if (! $currentUser->isAdmin()) {
            // Reporters can only save progress for projects they're assigned to
            if ($currentUser->hasRole('Reporter')) {
                if (! $project->users()->where('users.id', $currentUser->id)->exists()) {
                    $this->addError('project', 'You are not assigned to this project.');

                    return;
                }
            }
            // Kodim Admins can only save for projects in Koramils under their Kodim
            elseif ($currentUser->hasRole('Kodim Admin') && $currentUser->office_id) {
                $userOffice = $this->getUserOffice();

                if (! $userOffice || $userOffice->level->level !== 3) {
                    $this->addError('project', 'Invalid office assignment.');

                    return;
                }

                // Check if project's office is a child of the Kodim Admin's office
                if (! $project->office || $project->office->parent_id !== $currentUser->office_id) {
                    $this->addError('project', 'You do not have access to this project.');

                    return;
                }
            }
            // Koramil Admins can only save for projects in their own Koramil
            elseif ($currentUser->hasRole('Koramil Admin') && $currentUser->office_id) {
                if (! $project->office || $project->office_id !== $currentUser->office_id) {
                    $this->addError('project', 'You do not have access to this project.');

                    return;
                }
            }
            // Other roles: deny access unless explicitly handled
            else {
                $this->addError('project', 'You do not have permission to update progress.');

                return;
            }
        }

        // Validate date is not in the future
        if ($this->selectedDate > $this->maxDate) {
            $this->addError('selectedDate', 'Cannot save progress for future dates.');

            return;
        }

        // Validate date is within project date range (if project dates are set)
        if ($project && $project->start_date && $project->end_date) {
            $selectedDate = Carbon::parse($this->selectedDate);
            $projectStart = Carbon::parse($project->start_date)->startOfDay();
            $projectEnd = Carbon::parse($project->end_date)->endOfDay();

            if ($selectedDate->lt($projectStart)) {
                $this->addError(
                    'selectedDate',
                    'Progress date must be on or after project start date ('.$projectStart->format('M d, Y').').'
                );

                return;
            }

            if ($selectedDate->gt($projectEnd)) {
                $this->addError(
                    'selectedDate',
                    'Progress date must be on or before project end date ('.$projectEnd->format('M d, Y').').'
                );

                return;
            }
        }

        $data = $this->progressData[$taskId] ?? ['percentage' => 0, 'notes' => ''];
        $percentage = (float) ($data['percentage'] ?? 0);
        $notes = $data['notes'] ?? '';

        // Validate percentage
        if ($percentage < 0 || $percentage > 100) {
            $this->addError('percentage', 'Percentage must be between 0 and 100.');

            return;
        }

        // Update or create progress entry
        // Use Carbon date for proper comparison in SQLite
        $progressDate = Carbon::parse($this->selectedDate)->startOfDay();

        TaskProgress::updateOrCreate(
            [
                'task_id' => $taskId,
                'project_id' => $this->selectedProjectId,
                'progress_date' => $progressDate,
            ],
            [
                'user_id' => Auth::id(),
                'percentage' => $percentage,
                'notes' => $notes,
            ]
        );

        // Show success message
        $this->showSuccess = true;
        $this->successMessage = 'Progres berhasil disimpan!';

        $this->dispatch('progress-updated');
    }

    public function hideSuccess(): void
    {
        $this->showSuccess = false;
    }

    public function loadExistingPhotos(): void
    {
        if (! $this->selectedProjectId) {
            return;
        }

        $photos = ProgressPhoto::where('project_id', $this->selectedProjectId)
            ->whereDate('photo_date', $this->selectedDate)
            ->with('rootTask:id,name')
            ->get();

        $this->existingPhotos = $photos->keyBy('root_task_id')->toArray();
    }

    public function uploadPhoto(int $rootTaskId): void
    {
        if (! isset($this->photos[$rootTaskId])) {
            return;
        }

        // Get project to validate date range and authorization (cached)
        $project = $this->getSelectedProject();

        if (! $project) {
            $this->addError("photos.{$rootTaskId}", 'Project not found.');

            return;
        }

        // AUTHORIZATION: Verify user has access to this project
        $currentUser = auth()->user();

        // Admins have full access
        if (! $currentUser->isAdmin()) {
            // Reporters can only upload photos for projects they're assigned to
            if ($currentUser->hasRole('Reporter')) {
                if (! $project->users()->where('users.id', $currentUser->id)->exists()) {
                    $this->addError("photos.{$rootTaskId}", 'You are not assigned to this project.');

                    return;
                }
            }
            // Kodim Admins can only upload for projects in Koramils under their Kodim
            elseif ($currentUser->hasRole('Kodim Admin') && $currentUser->office_id) {
                $userOffice = $this->getUserOffice();

                if (! $userOffice || $userOffice->level->level !== 3) {
                    $this->addError("photos.{$rootTaskId}", 'Invalid office assignment.');

                    return;
                }

                // Check if project's office is a child of the Kodim Admin's office
                if (! $project->office || $project->office->parent_id !== $currentUser->office_id) {
                    $this->addError("photos.{$rootTaskId}", 'You do not have access to this project.');

                    return;
                }
            }
            // Koramil Admins can only upload for projects in their own Koramil
            elseif ($currentUser->hasRole('Koramil Admin') && $currentUser->office_id) {
                if (! $project->office || $project->office_id !== $currentUser->office_id) {
                    $this->addError("photos.{$rootTaskId}", 'You do not have access to this project.');

                    return;
                }
            }
            // Other roles: deny access unless explicitly handled
            else {
                $this->addError("photos.{$rootTaskId}", 'You do not have permission to upload photos.');

                return;
            }
        }

        // Validate date is within project date range (if project dates are set)
        if ($project && $project->start_date && $project->end_date) {
            $selectedDate = Carbon::parse($this->selectedDate);
            $projectStart = Carbon::parse($project->start_date)->startOfDay();
            $projectEnd = Carbon::parse($project->end_date)->endOfDay();

            if ($selectedDate->lt($projectStart) || $selectedDate->gt($projectEnd)) {
                $this->addError(
                    "photos.{$rootTaskId}",
                    'Photo date must be within project date range ('.$projectStart->format('M d, Y').' to '.$projectEnd->format('M d, Y').').'
                );

                return;
            }
        }

        // Validate that the root task has descendant progress
        $rootTask = Task::find($rootTaskId);
        if (! $rootTask || ! $rootTask->hasAnyDescendantProgress()) {
            $this->addError("photos.{$rootTaskId}", 'Cannot upload photo. Please enter progress for this task first.');

            return;
        }

        $this->validate([
            "photos.{$rootTaskId}" => 'required|image|max:2048|mimes:jpeg,jpg,png,webp',
        ], [
            "photos.{$rootTaskId}.required" => 'Please select an image.',
            "photos.{$rootTaskId}.image" => 'File must be an image.',
            "photos.{$rootTaskId}.max" => 'Image must not exceed 2MB. Photos are automatically compressed.',
            "photos.{$rootTaskId}.mimes" => 'Image must be jpeg, jpg, png, or webp format.',
        ]);

        $photo = $this->photos[$rootTaskId];

        if ($photo instanceof TemporaryUploadedFile) {
            // Get image dimensions
            [$width, $height] = getimagesize($photo->getRealPath());

            // Generate file path
            $date = Carbon::parse($this->selectedDate);
            $hash = md5($this->selectedProjectId.$rootTaskId.$date->format('Y-m-d').time());
            $extension = $photo->extension();
            $fileName = "{$this->selectedProjectId}_{$rootTaskId}_{$date->format('Y-m-d')}_{$hash}.{$extension}";
            $directory = "progress/{$this->selectedProjectId}/{$date->format('Y-m-d')}";
            $filePath = "{$directory}/{$fileName}";

            // Store the file
            $photo->storeAs($directory, $fileName, 'public');

            // Save to database
            ProgressPhoto::updateOrCreate(
                [
                    'project_id' => $this->selectedProjectId,
                    'root_task_id' => $rootTaskId,
                    'photo_date' => $date,
                ],
                [
                    'user_id' => Auth::id(),
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'file_size' => $photo->getSize(),
                    'mime_type' => $photo->getMimeType(),
                    'width' => $width,
                    'height' => $height,
                    'caption' => $this->photoCaptions[$rootTaskId] ?? null,
                ]
            );

            // Clear the upload
            unset($this->photos[$rootTaskId]);
            unset($this->photoCaptions[$rootTaskId]);

            // Reload photos
            $this->loadExistingPhotos();

            // Show success message
            $this->showSuccess = true;
            $this->successMessage = 'Foto berhasil diunggah!';

            $this->dispatch('photo-uploaded');
        }
    }

    public function removePhotoPreview(int $rootTaskId): void
    {
        unset($this->photos[$rootTaskId]);
        unset($this->photoCaptions[$rootTaskId]);
    }

    public function deletePhoto(int $photoId): void
    {
        $photo = ProgressPhoto::find($photoId);

        if (! $photo) {
            return;
        }

        // Check if user can edit (same day and own photo)
        if (! $photo->canEdit()) {
            $this->addError('photo', 'You can only delete photos uploaded today.');

            return;
        }

        // Delete file from storage
        $photo->deleteFile();

        // Delete from database
        $photo->delete();

        // Reload photos
        $this->loadExistingPhotos();

        $this->dispatch('photo-deleted');
    }

    public function with(): array
    {
        $currentUser = auth()->user();
        $projectsQuery = Project::with('location', 'partner');

        // Reporters can only see their assigned projects
        if ($currentUser->hasRole('Reporter')) {
            $projectsQuery->whereHas('users', fn ($q) => $q->where('users.id', $currentUser->id));
        }
        // Managers at Kodim level can only see projects in Koramils under their Kodim
        elseif ($currentUser->hasRole('Manager') && $currentUser->office_id) {
            $userOffice = $this->getUserOffice();
            if ($userOffice && $userOffice->level->level === 3) {
                $projectsQuery->whereHas('office', function ($q) use ($currentUser) {
                    $q->where('parent_id', $currentUser->office_id);
                });
            }
        }

        $projects = $projectsQuery->orderBy('name')->get();

        // Filter tasks by selected project
        $rootTasks = collect();
        $allTasks = collect();

        if ($this->selectedProjectId) {
            // Load all tasks once, then filter for root tasks (avoids duplicate query)
            $allTasks = Task::where('project_id', $this->selectedProjectId)
                ->with(['parent:id,name', 'children:id,parent_id'])
                ->orderBy('_lft')
                ->get();

            // Derive rootTasks from allTasks (no extra query)
            $rootTasks = $allTasks->whereNull('parent_id')->values();

            // Auto-select first tab if not set (centralizes logic, avoids duplicate queries)
            if ($this->activeTab === null && $rootTasks->isNotEmpty()) {
                $this->activeTab = $rootTasks->first()->id;
            }
        }

        // Get latest progress for all tasks
        $latestProgressMap = $this->getLatestProgressMap();

        // Calculate parent task progress (including root tasks)
        $parentProgressMap = $this->calculateParentProgress($allTasks, $latestProgressMap);

        // Get selected project for date range display (cached)
        $selectedProject = $this->getSelectedProject();

        // Pre-compute task depths and parent chains using indexed lookup (O(1) per parent)
        // This replaces Blade's firstWhere() which is O(n) per lookup
        $tasksById = $allTasks->keyBy('id');
        $taskDepths = [];
        $parentChains = [];

        foreach ($allTasks as $task) {
            $depth = 0;
            $chain = [];
            $currentId = $task->parent_id;

            while ($currentId !== null) {
                $depth++;
                $chain[] = $currentId;
                $parent = $tasksById[$currentId] ?? null;
                $currentId = $parent?->parent_id;
            }

            $taskDepths[$task->id] = $depth;
            $parentChains[$task->id] = $chain;
        }

        // Pre-compute which root tasks have descendant progress (avoids N+1 in Blade)
        // Note: Original hasAnyDescendantProgress() checks ALL TIME (no date filter)
        $rootTasksWithProgress = [];
        if ($this->selectedProjectId && $rootTasks->isNotEmpty()) {
            $taskIdsWithProgressAllTime = TaskProgress::where('project_id', $this->selectedProjectId)
                ->distinct()
                ->pluck('task_id')
                ->toArray();

            foreach ($rootTasks as $rootTask) {
                $leafDescendants = $allTasks
                    ->filter(fn ($t) => $t->_lft > $rootTask->_lft && $t->_rgt < $rootTask->_rgt)
                    ->filter(fn ($t) => $t->children->isEmpty());

                $leafTaskIds = $leafDescendants->pluck('id')->toArray();
                $rootTasksWithProgress[$rootTask->id] = ! empty(array_intersect($leafTaskIds, $taskIdsWithProgressAllTime));
            }
        }

        return [
            'projects' => $projects,
            'rootTasks' => $rootTasks,
            'allTasks' => $allTasks,
            'latestProgressMap' => $latestProgressMap,
            'parentProgressMap' => $parentProgressMap,
            'selectedProject' => $selectedProject,
            'taskDepths' => $taskDepths,
            'parentChains' => $parentChains,
            'rootTasksWithProgress' => $rootTasksWithProgress,
        ];
    }
}; ?>

<div class="progress-mobile-page flex h-full w-full flex-1 flex-col bg-neutral-50 dark:bg-zinc-900">
    {{-- Success Toast --}}
    @if($showSuccess)
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => { show = false; $wire.hideSuccess() }, 2500)"
            x-show="show"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-[-20px]"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed left-4 right-4 top-4 z-50 mx-auto max-w-md"
        >
            <div class="flex items-center gap-3 rounded-2xl bg-emerald-500 px-5 py-4 text-white shadow-lg">
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-white/20">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <span class="text-base font-semibold">{{ $successMessage }}</span>
            </div>
        </div>
    @endif

    {{-- Mobile Header - Clean & Simple --}}
    <div class="sticky top-0 z-20 bg-white px-4 pb-3 pt-4 shadow-sm dark:bg-zinc-800">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-neutral-800 dark:text-white">{{ __('Input Progres') }}</h1>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ now()->translatedFormat('l, j M Y') }}</p>
            </div>
            {{-- Date Badge --}}
            <div class="rounded-xl bg-blue-50 px-3 py-2 dark:bg-blue-900/30">
                <input
                    wire:model.live="selectedDate"
                    type="date"
                    max="{{ $maxDate }}"
                    class="w-full border-0 bg-transparent p-0 text-center text-sm font-semibold text-blue-700 focus:ring-0 dark:text-blue-300"
                />
            </div>
        </div>

        {{-- Project Selector - Big Touch Target --}}
        <div class="mt-4">
            <select
                wire:model.live="selectedProjectId"
                class="w-full rounded-xl border-2 border-neutral-200 bg-white px-4 py-3.5 text-base font-medium text-neutral-800 focus:border-blue-500 focus:ring-blue-500 dark:border-neutral-600 dark:bg-zinc-700 dark:text-white"
            >
                <option value="">{{ __('Pilih Proyek...') }}</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}">{{ $project->name }}</option>
                @endforeach
            </select>
        </div>

        @error('selectedDate')
            <div class="mt-2 rounded-lg bg-red-50 p-2 text-sm text-red-600 dark:bg-red-900/30 dark:text-red-400">
                {{ $message }}
            </div>
        @enderror
    </div>

    @if($selectedProjectId)
        {{-- Category Tabs - Balanced for Desktop, Scrollable on Mobile --}}
        <div class="sticky top-[140px] z-10 bg-neutral-100 px-3 py-2 dark:bg-zinc-800/50">
            {{-- Mobile: Horizontal scroll / Desktop: Flex wrap with equal width --}}
            <div class="flex gap-2 overflow-x-auto pb-1 md:flex-wrap md:overflow-x-visible" style="-webkit-overflow-scrolling: touch; scrollbar-width: none;">
                @foreach($rootTasks as $rootTask)
                    @php
                        $rootProgress = $parentProgressMap[$rootTask->id] ?? ['percentage' => 0, 'task_count' => 0];
                        $progressColor = $rootProgress['percentage'] >= 100 ? 'emerald' :
                                        ($rootProgress['percentage'] >= 50 ? 'blue' :
                                        ($rootProgress['percentage'] > 0 ? 'amber' : 'neutral'));
                    @endphp
                    <button
                        wire:click="setActiveTab({{ $rootTask->id }})"
                        class="flex-shrink-0 rounded-xl px-4 py-3 transition-all active:scale-95 md:min-w-0 md:flex-1
                            @if($activeTab === $rootTask->id)
                                bg-blue-600 text-white shadow-lg
                            @else
                                bg-white text-neutral-700 shadow hover:bg-neutral-50 dark:bg-zinc-700 dark:text-neutral-200 dark:hover:bg-zinc-600
                            @endif"
                    >
                        <div class="whitespace-nowrap text-sm font-bold md:truncate md:whitespace-normal">{{ Str::limit(str_replace(['PEKERJAAN ', 'Pekerjaan '], '', $rootTask->name), 18) }}</div>
                        <div class="mt-1.5 flex items-center gap-2">
                            {{-- Progress Bar --}}
                            <div class="h-2 w-14 overflow-hidden rounded-full md:flex-1 {{ $activeTab === $rootTask->id ? 'bg-blue-400' : 'bg-neutral-200 dark:bg-neutral-600' }}">
                                <div
                                    class="h-full rounded-full {{ $activeTab === $rootTask->id ? 'bg-white' : 'bg-'.$progressColor.'-500' }}"
                                    style="width: {{ min($rootProgress['percentage'], 100) }}%"
                                ></div>
                            </div>
                            <span class="text-xs font-bold {{ $activeTab === $rootTask->id ? 'text-blue-100' : 'text-'.$progressColor.'-600 dark:text-'.$progressColor.'-400' }}">
                                {{ number_format($rootProgress['percentage'], 0) }}%
                            </span>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Task List - Mobile Optimized --}}
        <div class="flex-1 overflow-y-auto px-3 py-4 pb-24">
            @foreach($rootTasks as $rootTask)
                @if($activeTab === $rootTask->id)
                    <div class="space-y-3">
                        @php
                            $descendants = $allTasks->filter(function($task) use ($rootTask) {
                                if ($task->id === $rootTask->id) return true;
                                return $task->_lft > $rootTask->_lft && $task->_rgt < $rootTask->_rgt;
                            });
                        @endphp

                        @foreach($descendants as $task)
                            @php
                                $depth = $taskDepths[$task->id] ?? 0;
                                $task->has_children = $task->children->count() > 0;
                                $parentChain = $parentChains[$task->id] ?? [];
                                $isVisible = $depth == 0 || empty($parentChain) || count(array_intersect($parentChain, $expandedTasks)) === count($parentChain);
                                $currentProgress = $progressData[$task->id] ?? ['percentage' => 0, 'notes' => ''];
                                $latestProgress = $latestProgressMap[$task->id] ?? null;
                                $parentProgress = $parentProgressMap[$task->id] ?? null;
                            @endphp

                            @if($isVisible)
                                {{-- Task Card --}}
                                <div
                                    class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-800"
                                    style="margin-left: {{ min($depth * 16, 32) }}px"
                                >
                                    {{-- Task Header --}}
                                    <div class="p-4">
                                        <div class="flex items-start gap-3">
                                            {{-- Expand Button for Parent Tasks --}}
                                            @if($task->has_children)
                                                <button
                                                    wire:click="toggleExpand({{ $task->id }})"
                                                    class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-neutral-100 text-neutral-600 active:bg-neutral-200 dark:bg-zinc-700 dark:text-neutral-300"
                                                >
                                                    <svg class="h-5 w-5 transition-transform {{ in_array($task->id, $expandedTasks) ? 'rotate-90' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                                    </svg>
                                                </button>
                                            @endif

                                            {{-- Task Info --}}
                                            <div class="min-w-0 flex-1">
                                                <h3 class="text-base font-bold text-neutral-800 dark:text-white">
                                                    {{ $task->name }}
                                                </h3>

                                                @if($parentProgress)
                                                    {{-- Parent Task Progress Display --}}
                                                    <div class="mt-2">
                                                        <div class="flex items-center justify-between text-sm">
                                                            <span class="text-neutral-500">{{ $parentProgress['task_count'] }} {{ __('sub-pekerjaan') }}</span>
                                                            <span class="text-lg font-bold
                                                                @if($parentProgress['percentage'] >= 100) text-emerald-600 dark:text-emerald-400
                                                                @elseif($parentProgress['percentage'] >= 50) text-blue-600 dark:text-blue-400
                                                                @else text-amber-600 dark:text-amber-400
                                                                @endif">
                                                                {{ number_format($parentProgress['percentage'], 0) }}%
                                                            </span>
                                                        </div>
                                                        <div class="mt-1.5 h-2.5 w-full overflow-hidden rounded-full bg-neutral-200 dark:bg-zinc-600">
                                                            <div
                                                                class="h-full rounded-full transition-all
                                                                    @if($parentProgress['percentage'] >= 100) bg-emerald-500
                                                                    @elseif($parentProgress['percentage'] >= 50) bg-blue-500
                                                                    @else bg-amber-500
                                                                    @endif"
                                                                style="width: {{ min($parentProgress['percentage'], 100) }}%"
                                                            ></div>
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- Leaf Task: Progress Input --}}
                                                @if(!$task->has_children)
                                                    {{-- Current Progress Badge --}}
                                                    @if($latestProgress)
                                                        <div class="mt-3 flex items-center gap-2">
                                                            <div class="flex h-8 w-8 items-center justify-center rounded-lg
                                                                @if($latestProgress['percentage'] >= 100) bg-emerald-100 dark:bg-emerald-900/30
                                                                @elseif($latestProgress['percentage'] >= 50) bg-blue-100 dark:bg-blue-900/30
                                                                @else bg-amber-100 dark:bg-amber-900/30
                                                                @endif">
                                                                @if($latestProgress['percentage'] >= 100)
                                                                    <svg class="h-5 w-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                                    </svg>
                                                                @else
                                                                    <span class="text-xs font-bold
                                                                        @if($latestProgress['percentage'] >= 50) text-blue-600 dark:text-blue-400
                                                                        @else text-amber-600 dark:text-amber-400
                                                                        @endif">
                                                                        {{ number_format($latestProgress['percentage'], 0) }}%
                                                                    </span>
                                                                @endif
                                                            </div>
                                                            <div class="text-sm">
                                                                <span class="font-medium text-neutral-600 dark:text-neutral-300">{{ __('Progres Terakhir') }}</span>
                                                                <span class="ml-1 text-neutral-400">{{ $latestProgress['progress_date']->translatedFormat('j M') }}</span>
                                                            </div>
                                                        </div>
                                                    @else
                                                        <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2 dark:bg-amber-900/20">
                                                            <span class="text-sm text-amber-700 dark:text-amber-300">{{ __('Belum ada progres') }}</span>
                                                        </div>
                                                    @endif

                                                    {{-- Progress Input Form - Simple & Large --}}
                                                    <div class="mt-4 space-y-3">
                                                        {{-- Percentage Input - BIG --}}
                                                        <div>
                                                            <label class="mb-1.5 block text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                                                                {{ __('Progres (%)') }}
                                                            </label>
                                                            <div class="flex items-center gap-2">
                                                                <input
                                                                    wire:model="progressData.{{ $task->id }}.percentage"
                                                                    type="number"
                                                                    inputmode="decimal"
                                                                    step="1"
                                                                    min="0"
                                                                    max="100"
                                                                    placeholder="{{ $latestProgress ? number_format($latestProgress['percentage'], 0) : '0' }}"
                                                                    class="h-14 w-full rounded-xl border-2 border-neutral-200 bg-white px-4 text-center text-2xl font-bold text-neutral-800 focus:border-blue-500 focus:ring-blue-500 dark:border-neutral-600 dark:bg-zinc-700 dark:text-white"
                                                                />
                                                                <span class="text-2xl font-bold text-neutral-400">%</span>
                                                            </div>
                                                        </div>

                                                        {{-- Quick Progress Buttons --}}
                                                        <div class="flex gap-2">
                                                            @foreach([25, 50, 75, 100] as $quickValue)
                                                                <button
                                                                    type="button"
                                                                    wire:click="$set('progressData.{{ $task->id }}.percentage', {{ $quickValue }})"
                                                                    class="flex-1 rounded-xl border-2 border-neutral-200 bg-white py-2.5 text-sm font-bold text-neutral-600 active:bg-neutral-100 dark:border-neutral-600 dark:bg-zinc-700 dark:text-neutral-300"
                                                                >
                                                                    {{ $quickValue }}%
                                                                </button>
                                                            @endforeach
                                                        </div>

                                                        {{-- Notes Input --}}
                                                        <div>
                                                            <label class="mb-1.5 block text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                                                                {{ __('Catatan') }} <span class="font-normal text-neutral-400">({{ __('opsional') }})</span>
                                                            </label>
                                                            <input
                                                                wire:model="progressData.{{ $task->id }}.notes"
                                                                type="text"
                                                                placeholder="{{ __('Tulis catatan...') }}"
                                                                class="h-12 w-full rounded-xl border-2 border-neutral-200 bg-white px-4 text-base text-neutral-800 focus:border-blue-500 focus:ring-blue-500 dark:border-neutral-600 dark:bg-zinc-700 dark:text-white dark:placeholder-neutral-400"
                                                            />
                                                        </div>

                                                        {{-- Save Button - BIG & Prominent --}}
                                                        <button
                                                            wire:click="saveProgress({{ $task->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="saveProgress({{ $task->id }})"
                                                            class="flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 font-bold text-white shadow-lg active:bg-blue-700 disabled:opacity-50"
                                                        >
                                                            <span wire:loading.remove wire:target="saveProgress({{ $task->id }})">
                                                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                                </svg>
                                                            </span>
                                                            <span wire:loading.remove wire:target="saveProgress({{ $task->id }})">{{ __('SIMPAN PROGRES') }}</span>
                                                            <span wire:loading wire:target="saveProgress({{ $task->id }})">{{ __('Menyimpan...') }}</span>
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        {{-- Photo Upload Section --}}
                        @php
                            $existingPhoto = $existingPhotos[$rootTask->id] ?? null;
                            $hasPreview = isset($photos[$rootTask->id]);
                            $hasDescendantProgress = $rootTasksWithProgress[$rootTask->id] ?? false;
                        @endphp

                        <div class="mt-4 overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-800">
                            <div class="border-b border-neutral-100 px-4 py-3 dark:border-zinc-700">
                                <div class="flex items-center gap-2">
                                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/30">
                                        <svg class="h-5 w-5 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                    </div>
                                    <span class="text-base font-bold text-neutral-800 dark:text-white">{{ __('Foto Progres') }}</span>
                                </div>
                            </div>

                            <div class="p-4">
                                @if(!$hasDescendantProgress)
                                    {{-- No Progress Warning --}}
                                    <div class="rounded-xl bg-amber-50 p-4 text-center dark:bg-amber-900/20">
                                        <div class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-800/50">
                                            <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                            </svg>
                                        </div>
                                        <p class="font-semibold text-amber-800 dark:text-amber-300">{{ __('Input Progres Dulu') }}</p>
                                        <p class="mt-1 text-sm text-amber-600 dark:text-amber-400">{{ __('Masukkan progres pekerjaan sebelum upload foto') }}</p>
                                    </div>
                                @elseif($existingPhoto && !$hasPreview)
                                    {{-- Existing Photo --}}
                                    <div class="space-y-3">
                                        <div class="relative overflow-hidden rounded-xl">
                                            <img
                                                src="{{ Storage::url($existingPhoto['file_path']) }}"
                                                alt="Foto progres"
                                                class="w-full cursor-pointer object-cover"
                                                onclick="document.getElementById('lightbox-{{ $rootTask->id }}').classList.remove('hidden')"
                                                style="max-height: 250px"
                                            />
                                            @if($existingPhoto['caption'])
                                                <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-3">
                                                    <p class="text-sm text-white">{{ $existingPhoto['caption'] }}</p>
                                                </div>
                                            @endif
                                        </div>

                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-neutral-500">{{ \Carbon\Carbon::parse($existingPhoto['created_at'])->translatedFormat('j M Y, H:i') }}</span>
                                            @if(\Carbon\Carbon::parse($existingPhoto['created_at'])->isToday() && $existingPhoto['user_id'] === auth()->id())
                                                <button
                                                    wire:click="deletePhoto({{ $existingPhoto['id'] }})"
                                                    wire:confirm="{{ __('Hapus foto ini?') }}"
                                                    class="rounded-lg bg-red-100 px-3 py-1.5 text-sm font-semibold text-red-600 active:bg-red-200 dark:bg-red-900/30 dark:text-red-400"
                                                >
                                                    {{ __('Hapus') }}
                                                </button>
                                            @endif
                                        </div>

                                        {{-- Lightbox --}}
                                        <div id="lightbox-{{ $rootTask->id }}" class="fixed inset-0 z-50 hidden bg-black/95 p-4" onclick="this.classList.add('hidden')">
                                            <button class="absolute right-4 top-4 rounded-full bg-white/20 p-2 text-white" onclick="document.getElementById('lightbox-{{ $rootTask->id }}').classList.add('hidden')">
                                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                            <div class="flex h-full w-full items-center justify-center">
                                                <img src="{{ Storage::url($existingPhoto['file_path']) }}" alt="Foto progres" class="max-h-full max-w-full rounded-lg"/>
                                            </div>
                                        </div>
                                    </div>
                                @elseif($hasPreview)
                                    {{-- Photo Preview --}}
                                    <div class="space-y-3">
                                        <div class="relative overflow-hidden rounded-xl">
                                            <img src="{{ $photos[$rootTask->id]->temporaryUrl() }}" alt="Preview" class="w-full object-cover" style="max-height: 250px"/>
                                            <button
                                                wire:click="removePhotoPreview({{ $rootTask->id }})"
                                                class="absolute right-2 top-2 rounded-full bg-red-500 p-2 text-white shadow-lg"
                                            >
                                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>

                                        <input
                                            wire:model="photoCaptions.{{ $rootTask->id }}"
                                            type="text"
                                            placeholder="{{ __('Tambah keterangan (opsional)...') }}"
                                            class="h-12 w-full rounded-xl border-2 border-neutral-200 bg-white px-4 text-base dark:border-neutral-600 dark:bg-zinc-700 dark:text-white"
                                        />

                                        <button
                                            wire:click="uploadPhoto({{ $rootTask->id }})"
                                            wire:loading.attr="disabled"
                                            class="flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-violet-600 font-bold text-white shadow-lg active:bg-violet-700 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="uploadPhoto">{{ __('UPLOAD FOTO') }}</span>
                                            <span wire:loading wire:target="uploadPhoto">{{ __('Mengupload...') }}</span>
                                        </button>

                                        @error("photos.{$rootTask->id}")
                                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @else
                                    {{-- Upload Button --}}
                                    <label for="photo-{{ $rootTask->id }}" class="block cursor-pointer">
                                        <div class="rounded-xl border-2 border-dashed border-neutral-300 p-6 text-center transition-colors active:border-violet-500 active:bg-violet-50 dark:border-neutral-600 dark:active:bg-violet-900/20">
                                            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-violet-100 dark:bg-violet-900/30">
                                                <svg class="h-7 w-7 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                </svg>
                                            </div>
                                            <p class="text-base font-bold text-neutral-700 dark:text-neutral-200">{{ __('Ambil Foto') }}</p>
                                            <p class="mt-1 text-sm text-neutral-500">{{ __('Ketuk untuk mengambil foto') }}</p>
                                        </div>
                                    </label>
                                    <input type="file" id="photo-{{ $rootTask->id }}" data-task-id="{{ $rootTask->id }}" onchange="handlePhotoSelection(this)" accept="image/*" capture="camera" class="hidden"/>
                                    <input type="file" id="compressed-photo-{{ $rootTask->id }}" wire:model="photos.{{ $rootTask->id }}" class="hidden"/>
                                    <div id="upload-status-{{ $rootTask->id }}" class="mt-2 text-center text-sm"></div>
                                    <div wire:loading wire:target="photos.{{ $rootTask->id }}" class="mt-2 text-center text-sm text-blue-600">{{ __('Memproses...') }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @else
        {{-- Empty State --}}
        <div class="flex flex-1 items-center justify-center p-8">
            <div class="text-center">
                <div class="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-neutral-100 dark:bg-zinc-800">
                    <svg class="h-10 w-10 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-neutral-800 dark:text-white">{{ __('Pilih Proyek') }}</h3>
                <p class="mt-2 text-neutral-500 dark:text-neutral-400">{{ __('Pilih proyek di atas untuk mulai input progres') }}</p>
            </div>
        </div>
    @endif
</div>

{{-- Image Compression --}}
<script src="https://cdn.jsdelivr.net/npm/browser-image-compression@2.0.2/dist/browser-image-compression.js"></script>
<script>
async function handlePhotoSelection(input) {
    const file = input.files[0];
    if (!file) return;

    const taskId = input.dataset.taskId;
    const statusDiv = document.getElementById(`upload-status-${taskId}`);
    const compressedInput = document.getElementById(`compressed-photo-${taskId}`);

    statusDiv.innerHTML = '<span class="text-blue-600 font-medium">Memproses foto...</span>';

    try {
        if (!file.type.startsWith('image/')) {
            throw new Error('Pilih file gambar (PNG, JPG, WEBP)');
        }

        if (file.size > 20 * 1024 * 1024) {
            throw new Error('Foto terlalu besar (max 20MB)');
        }

        const options = {
            maxSizeMB: 2,
            maxWidthOrHeight: 1920,
            useWebWorker: true,
            fileType: file.type,
            initialQuality: 0.8,
        };

        statusDiv.innerHTML = '<span class="text-blue-600 font-medium">Mengompres foto...</span>';

        let compressedFile = await imageCompression(file, options);

        if (compressedFile.size > 2 * 1024 * 1024) {
            options.maxSizeMB = 1.5;
            options.initialQuality = 0.7;
            compressedFile = await imageCompression(file, options);
        }

        const compressedFileToUpload = new File([compressedFile], file.name, { type: compressedFile.type });
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(compressedFileToUpload);
        compressedInput.files = dataTransfer.files;

        const event = new Event('change', { bubbles: true });
        compressedInput.dispatchEvent(event);

        statusDiv.innerHTML = '<span class="text-green-600 font-medium">Foto siap!</span>';
        input.value = '';

    } catch (error) {
        statusDiv.innerHTML = `<span class="text-red-600 font-medium">${error.message}</span>`;
        input.value = '';
        compressedInput.value = '';
    }
}

document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 413) {
                preventDefault();
                alert('Foto terlalu besar untuk server. Coba foto lain.');
            }
        });
    });
});
</script>

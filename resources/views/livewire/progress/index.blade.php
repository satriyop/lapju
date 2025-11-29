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

        $this->dispatch('progress-updated');
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

<div class="flex h-full w-full flex-1 flex-col">
    <!-- Mobile Header - Sticky -->
    <div class="sticky top-0 z-10 border-b border-neutral-200 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <h1 class="text-lg font-bold text-neutral-900 dark:text-neutral-100">{{ __('Progress Tracking') }}</h1>
                <p class="text-xs text-neutral-600 dark:text-neutral-400">{{ now()->format('D, M j, Y') }}</p>
            </div>
        </div>

        <!-- Project Selector and Date Picker -->
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-700 dark:text-neutral-300">{{ __('Project') }}</label>
                <flux:select wire:model.live="selectedProjectId" class="w-full">
                    <option value="">{{ __('Select Project...') }}</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}">
                            {{ $project->name }} - {{ Str::limit($project->partner->name, 30) }}
                        </option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('Progress Date') }}
                    @if($selectedProject && $selectedProject->start_date && $selectedProject->end_date)
                        <span class="text-neutral-500">({{ \Carbon\Carbon::parse($selectedProject->start_date)->format('M d, Y') }} - {{ \Carbon\Carbon::parse($selectedProject->end_date)->format('M d, Y') }})</span>
                    @else
                        <span class="text-neutral-500">({{ __('no future dates') }})</span>
                    @endif
                </label>
                @php
                    $maxAllowedDate = $maxDate;
                    if ($selectedProject && $selectedProject->end_date) {
                        $projectEnd = \Carbon\Carbon::parse($selectedProject->end_date)->format('Y-m-d');
                        if ($projectEnd < $maxDate) {
                            $maxAllowedDate = $projectEnd;
                        }
                    }

                    $minAllowedDate = '';
                    if ($selectedProject && $selectedProject->start_date) {
                        $minAllowedDate = \Carbon\Carbon::parse($selectedProject->start_date)->format('Y-m-d');
                    }
                @endphp
                <flux:input
                    wire:model.live="selectedDate"
                    type="date"
                    min="{{ $minAllowedDate }}"
                    max="{{ $maxAllowedDate }}"
                    class="w-full"
                />
                @error('selectedDate')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <!-- Date Indicator -->
        @if($selectedDate !== $maxDate)
            <div class="mt-2 rounded-lg bg-amber-50 p-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>
                        {{ __('Entering progress for:') }}
                        <strong>{{ \Carbon\Carbon::parse($selectedDate)->format('D, M j, Y') }}</strong>
                        ({{ \Carbon\Carbon::parse($selectedDate)->diffForHumans() }})
                    </span>
                </div>
            </div>
        @endif
    </div>

    @if($selectedProjectId)
        <!-- Tabs - Horizontal Scroll -->
        <div class="sticky top-[120px] z-10 border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
            <div class="flex gap-1 overflow-x-auto px-2 py-2" style="scrollbar-width: none; -ms-overflow-style: none;">
                @foreach($rootTasks as $rootTask)
                    @php
                        $rootProgress = $parentProgressMap[$rootTask->id] ?? ['percentage' => 0, 'task_count' => 0];
                    @endphp
                    <button
                        wire:click="setActiveTab({{ $rootTask->id }})"
                        class="flex-shrink-0 rounded-lg px-4 py-2 transition-all
                            @if($activeTab === $rootTask->id)
                                bg-blue-600 text-white shadow-md dark:bg-blue-500
                            @else
                                bg-white text-neutral-700 hover:bg-neutral-100 dark:bg-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-600
                            @endif"
                    >
                        <div class="text-sm font-medium">{{ Str::limit($rootTask->name, 20) }}</div>
                        <div class="mt-1 flex items-center gap-2">
                            <div class="h-1.5 w-16 overflow-hidden rounded-full
                                @if($activeTab === $rootTask->id)
                                    bg-blue-400
                                @else
                                    bg-neutral-200 dark:bg-neutral-600
                                @endif">
                                <div
                                    class="h-full rounded-full transition-all
                                        @if($activeTab === $rootTask->id)
                                            bg-white
                                        @else
                                            @if($rootProgress['percentage'] >= 100) bg-green-500
                                            @elseif($rootProgress['percentage'] >= 75) bg-blue-500
                                            @elseif($rootProgress['percentage'] >= 50) bg-yellow-500
                                            @else bg-red-500
                                            @endif
                                        @endif"
                                    style="width: {{ min($rootProgress['percentage'], 100) }}%"
                                ></div>
                            </div>
                            <span class="text-xs font-medium">{{ number_format($rootProgress['percentage'], 1) }}%</span>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        <!-- Task List - Scrollable Content -->
        <div class="flex-1 overflow-y-auto px-2 py-3 pb-20 sm:px-4">
            @foreach($rootTasks as $rootTask)
                @if($activeTab === $rootTask->id)
                    <div class="space-y-2">
                        @php
                            // Get all descendants of this root task
                            $descendants = $allTasks->filter(function($task) use ($rootTask) {
                                if ($task->id === $rootTask->id) {
                                    return true;
                                }
                                return $task->_lft > $rootTask->_lft && $task->_rgt < $rootTask->_rgt;
                            });
                        @endphp

                        @foreach($descendants as $task)
                            @php
                                // Use pre-computed depth and parent chain (avoids N+1 queries)
                                $depth = $taskDepths[$task->id] ?? 0;
                                $task->depth = $depth;
                                $task->has_children = $task->children->count() > 0;

                                // Use pre-computed parent chain (O(1) lookup instead of O(n) traversal)
                                $parentChain = $parentChains[$task->id] ?? [];

                                // Check visibility
                                $isVisible = $depth == 0 || empty($parentChain) || count(array_intersect($parentChain, $expandedTasks)) === count($parentChain);

                                // Get current progress
                                $currentProgress = $progressData[$task->id] ?? ['percentage' => 0, 'notes' => ''];
                                $latestProgress = $latestProgressMap[$task->id] ?? null;
                                $parentProgress = $parentProgressMap[$task->id] ?? null;
                            @endphp

                            @if($isVisible)
                                <!-- Task Card - Mobile Optimized -->
                                <div class="rounded-xl border border-neutral-300 bg-white shadow-sm dark:border-neutral-600 dark:bg-neutral-800"
                                     style="margin-left: {{ $depth * 12 }}px">
                                    <!-- Task Header -->
                                    <div class="p-3">
                                        <div class="flex items-start gap-2">
                                            <!-- Expand/Collapse -->
                                            @if($task->has_children)
                                                <button
                                                    wire:click="toggleExpand({{ $task->id }})"
                                                    class="mt-1 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-700 active:bg-neutral-200 dark:bg-neutral-700 dark:text-neutral-300 dark:active:bg-neutral-600"
                                                >
                                                    @if(in_array($task->id, $expandedTasks))
                                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                        </svg>
                                                    @else
                                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                                        </svg>
                                                    @endif
                                                </button>
                                            @endif

                                            <!-- Task Info -->
                                            <div class="flex-1">
                                                <div class="flex items-start justify-between">
                                                    <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">
                                                        {{ $task->name }}
                                                    </h3>
                                                    @if($parentProgress)
                                                        <div class="text-right">
                                                            <div class="text-lg font-bold
                                                                @if($parentProgress['percentage'] >= 100) text-green-600 dark:text-green-400
                                                                @elseif($parentProgress['percentage'] >= 75) text-blue-600 dark:text-blue-400
                                                                @elseif($parentProgress['percentage'] >= 50) text-yellow-600 dark:text-yellow-400
                                                                @else text-red-600 dark:text-red-400
                                                                @endif">
                                                                {{ number_format($parentProgress['percentage'], 1) }}%
                                                            </div>
                                                            <div class="text-xs text-neutral-500">avg of {{ $parentProgress['task_count'] }} tasks</div>
                                                        </div>
                                                    @endif
                                                </div>

                                                @if(!$task->has_children)
                                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-neutral-600 dark:text-neutral-400">
                                                        <span>Vol: {{ number_format($task->volume, 2) }} {{ $task->unit ?? '' }}</span>
                                                        <span>•</span>
                                                        <span>Weight: {{ number_format($task->weight, 2) }}</span>
                                                        <span>•</span>
                                                        <span>Price: {{ number_format($task->price, 0) }}</span>
                                                    </div>
                                                @endif

                                                @if($parentProgress)
                                                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-600">
                                                        <div
                                                            class="h-full rounded-full transition-all
                                                                @if($parentProgress['percentage'] >= 100) bg-green-500
                                                                @elseif($parentProgress['percentage'] >= 75) bg-blue-500
                                                                @elseif($parentProgress['percentage'] >= 50) bg-yellow-500
                                                                @else bg-red-500
                                                                @endif"
                                                            style="width: {{ min($parentProgress['percentage'], 100) }}%"
                                                        ></div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <!-- Progress Inputs - Only for Leaf Tasks (No Children) -->
                                        @if(!$task->has_children)
                                            @if($latestProgress)
                                                <div class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-2 dark:border-blue-800 dark:bg-blue-900/30">
                                                    <div class="flex items-center justify-between text-sm">
                                                        <span class="font-medium text-blue-800 dark:text-blue-200">{{ __('Latest Progress:') }}</span>
                                                        <span class="font-bold
                                                            @if($latestProgress['percentage'] >= 100) text-green-600 dark:text-green-400
                                                            @elseif($latestProgress['percentage'] >= 75) text-blue-600 dark:text-blue-400
                                                            @elseif($latestProgress['percentage'] >= 50) text-yellow-600 dark:text-yellow-400
                                                            @else text-red-600 dark:text-red-400
                                                            @endif">
                                                            {{ number_format($latestProgress['percentage'], 1) }}%
                                                        </span>
                                                    </div>
                                                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-blue-200 dark:bg-blue-700">
                                                        <div
                                                            class="h-full rounded-full transition-all
                                                                @if($latestProgress['percentage'] >= 100) bg-green-500
                                                                @elseif($latestProgress['percentage'] >= 75) bg-blue-500
                                                                @elseif($latestProgress['percentage'] >= 50) bg-yellow-500
                                                                @else bg-red-500
                                                                @endif"
                                                            style="width: {{ min($latestProgress['percentage'], 100) }}%"
                                                        ></div>
                                                    </div>
                                                    <div class="mt-1 text-xs text-blue-700 dark:text-blue-300">
                                                        {{ __('Updated:') }} {{ $latestProgress['progress_date']->format('M d, Y') }}
                                                        @if($latestProgress['notes'])
                                                            <br>{{ $latestProgress['notes'] }}
                                                        @endif
                                                    </div>
                                                </div>
                                            @else
                                                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                                                    {{ __('No progress recorded yet for this task') }}
                                                </div>
                                            @endif

                                            <div class="mt-3 space-y-2">
                                                <!-- Percentage Input -->
                                                <div class="flex items-center gap-2">
                                                    <label class="w-16 text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                                        {{ __('Progress') }}
                                                    </label>
                                                    <div class="flex flex-1 items-center gap-2">
                                                        <flux:input
                                                            wire:model="progressData.{{ $task->id }}.percentage"
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            max="100"
                                                            placeholder="{{ $latestProgress ? number_format($latestProgress['percentage'], 2) : '0.00' }}"
                                                            class="flex-1"
                                                        />
                                                        <span class="text-sm font-medium text-neutral-700 dark:text-neutral-300">%</span>
                                                    </div>
                                                </div>

                                                <!-- Notes Input -->
                                                <div class="flex items-start gap-2">
                                                    <label class="w-16 pt-2 text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                                        {{ __('Notes') }}
                                                    </label>
                                                    <flux:input
                                                        wire:model="progressData.{{ $task->id }}.notes"
                                                        type="text"
                                                        :placeholder="__('Optional notes...')"
                                                        class="flex-1"
                                                    />
                                                </div>

                                                <!-- Save Button - Full Width -->
                                                <flux:button
                                                    wire:click="saveProgress({{ $task->id }})"
                                                    variant="primary"
                                                    class="w-full"
                                                >
                                                    {{ __('Save Progress') }}
                                                </flux:button>
                                            </div>
                                        @else
                                            <!-- Parent Task Indicator -->
                                            <div class="mt-2 text-xs italic text-neutral-500 dark:text-neutral-400">
                                                {{ __('Parent task') }} ({{ $parentProgress['task_count'] ?? 0 }} {{ __('leaf tasks') }}) - {{ __('expand to track child tasks') }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        <!-- Photo Upload Section (Only for Root Tasks) -->
                        <div class="mt-4 rounded-xl border border-neutral-300 bg-white p-4 shadow-sm dark:border-neutral-600 dark:bg-neutral-800">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">
                                    {{ __('Progress Photo') }}
                                </h3>
                                <svg class="h-5 w-5 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>

                            @php
                                $existingPhoto = $existingPhotos[$rootTask->id] ?? null;
                                $hasPreview = isset($photos[$rootTask->id]);
                                $hasDescendantProgress = $rootTasksWithProgress[$rootTask->id] ?? false;
                            @endphp

                            @if(!$hasDescendantProgress)
                                <!-- No Progress Message -->
                                <div class="rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-center dark:border-yellow-600 dark:bg-yellow-900/20">
                                    <svg class="mx-auto h-10 w-10 text-yellow-600 dark:text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                    </svg>
                                    <p class="mt-2 text-sm font-medium text-yellow-800 dark:text-yellow-300">
                                        {{ __('Progress Required') }}
                                    </p>
                                    <p class="mt-1 text-xs text-yellow-700 dark:text-yellow-400">
                                        {{ __('Please enter progress for this task\'s child tasks before uploading a photo.') }}
                                    </p>
                                </div>
                            @else

                            <!-- Existing Photo Display -->
                            @if($existingPhoto && !$hasPreview)
                                <div class="space-y-3">
                                    <div class="relative overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                                        <img
                                            src="{{ Storage::url($existingPhoto['file_path']) }}"
                                            alt="Progress photo"
                                            class="w-full cursor-pointer object-cover"
                                            onclick="document.getElementById('lightbox-{{ $rootTask->id }}').classList.remove('hidden')"
                                            style="max-height: 300px"
                                        />
                                        @if($existingPhoto['caption'])
                                            <div class="bg-black/60 p-2 text-xs text-white">
                                                {{ $existingPhoto['caption'] }}
                                            </div>
                                        @endif
                                    </div>

                                    <div class="flex items-center justify-between text-xs text-neutral-600 dark:text-neutral-400">
                                        <span>
                                            {{ __('Uploaded:') }} {{ \Carbon\Carbon::parse($existingPhoto['created_at'])->format('M d, Y g:i A') }}
                                        </span>
                                        @if(\Carbon\Carbon::parse($existingPhoto['created_at'])->isToday() && $existingPhoto['user_id'] === auth()->id())
                                            <flux:button
                                                wire:click="deletePhoto({{ $existingPhoto['id'] }})"
                                                wire:confirm="{{ __('Delete this photo?') }}"
                                                size="sm"
                                                variant="danger"
                                            >
                                                {{ __('Delete') }}
                                            </flux:button>
                                        @endif
                                    </div>

                                    <!-- Lightbox Modal -->
                                    <div id="lightbox-{{ $rootTask->id }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/90 p-4" onclick="this.classList.add('hidden')">
                                        <div class="relative max-h-full max-w-full">
                                            <img
                                                src="{{ Storage::url($existingPhoto['file_path']) }}"
                                                alt="Progress photo full size"
                                                class="max-h-screen max-w-full rounded-lg"
                                            />
                                            <button
                                                class="absolute right-4 top-4 rounded-full bg-white/20 p-2 text-white hover:bg-white/30"
                                                onclick="document.getElementById('lightbox-{{ $rootTask->id }}').classList.add('hidden')"
                                            >
                                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <!-- Photo Preview (Before Upload) -->
                            @if($hasPreview)
                                <div class="space-y-3">
                                    <div class="relative overflow-hidden rounded-lg border border-blue-300 dark:border-blue-700">
                                        @if($photos[$rootTask->id])
                                            <img
                                                src="{{ $photos[$rootTask->id]->temporaryUrl() }}"
                                                alt="Preview"
                                                class="w-full object-cover"
                                                style="max-height: 300px"
                                            />
                                        @endif
                                        <button
                                            wire:click="removePhotoPreview({{ $rootTask->id }})"
                                            class="absolute right-2 top-2 rounded-full bg-red-500 p-1.5 text-white hover:bg-red-600"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- Caption Input -->
                                    <flux:input
                                        wire:model="photoCaptions.{{ $rootTask->id }}"
                                        type="text"
                                        :placeholder="__('Add a caption (optional)...')"
                                        class="w-full"
                                    />

                                    <!-- Upload Button -->
                                    <flux:button
                                        wire:click="uploadPhoto({{ $rootTask->id }})"
                                        variant="primary"
                                        class="w-full"
                                        wire:loading.attr="disabled"
                                        wire:target="uploadPhoto"
                                    >
                                        <span wire:loading.remove wire:target="uploadPhoto">{{ __('Upload Photo') }}</span>
                                        <span wire:loading wire:target="uploadPhoto">{{ __('Uploading...') }}</span>
                                    </flux:button>

                                    @error("photos.{$rootTask->id}")
                                        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            <!-- Upload Button (No Photo) -->
                            @if(!$existingPhoto && !$hasPreview)
                                <div class="text-center">
                                    <label for="photo-{{ $rootTask->id }}" class="block cursor-pointer">
                                        <div class="rounded-lg border-2 border-dashed border-neutral-300 p-6 transition-colors hover:border-blue-500 hover:bg-blue-50 dark:border-neutral-600 dark:hover:border-blue-500 dark:hover:bg-blue-900/20">
                                            <svg class="mx-auto h-12 w-12 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            </svg>
                                            <p class="mt-2 text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                                {{ __('Tap to add photo') }}
                                            </p>
                                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                                {{ __('PNG, JPG, WEBP up to 2MB') }}
                                            </p>
                                            <p class="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                                                {{ __('Large photos will be compressed automatically') }}
                                            </p>
                                        </div>
                                    </label>
                                    <input
                                        type="file"
                                        id="photo-{{ $rootTask->id }}"
                                        data-task-id="{{ $rootTask->id }}"
                                        onchange="handlePhotoSelection(this)"
                                        accept="image/*"
                                        capture="camera"
                                        class="hidden"
                                    />
                                    <input
                                        type="file"
                                        id="compressed-photo-{{ $rootTask->id }}"
                                        wire:model="photos.{{ $rootTask->id }}"
                                        class="hidden"
                                    />
                                    <div id="upload-status-{{ $rootTask->id }}" class="mt-2 text-sm"></div>
                                    <div wire:loading wire:target="photos.{{ $rootTask->id }}" class="mt-2 text-sm text-blue-600">
                                        {{ __('Uploading...') }}
                                    </div>
                                </div>
                            @endif
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @else
        <!-- Empty State -->
        <div class="flex flex-1 items-center justify-center p-8">
            <div class="text-center">
                <svg class="mx-auto h-16 w-16 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <h3 class="mt-4 text-lg font-medium text-neutral-900 dark:text-neutral-100">
                    {{ __('No Project Selected') }}
                </h3>
                <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                    {{ __('Please select a project to start tracking progress') }}
                </p>
            </div>
        </div>
    @endif
</div>

{{-- Image Compression Library --}}
<script src="https://cdn.jsdelivr.net/npm/browser-image-compression@2.0.2/dist/browser-image-compression.js"></script>

<script>
/**
 * Handle photo selection with automatic compression
 * Prevents large file uploads that cause server overload
 */
async function handlePhotoSelection(input) {
    const file = input.files[0];
    if (!file) return;

    const taskId = input.dataset.taskId;
    const statusDiv = document.getElementById(`upload-status-${taskId}`);
    const compressedInput = document.getElementById(`compressed-photo-${taskId}`);

    // Show initial status
    statusDiv.innerHTML = '<span class="text-blue-600">📸 Preparing photo...</span>';

    try {
        // Validate file type
        if (!file.type.startsWith('image/')) {
            throw new Error('Please select an image file (PNG, JPG, WEBP)');
        }

        // Validate file size (max 20MB - hard limit before compression)
        const maxSizeBeforeCompression = 20 * 1024 * 1024; // 20MB
        if (file.size > maxSizeBeforeCompression) {
            throw new Error('Photo is too large (max 20MB). Please use a smaller photo.');
        }

        // Show original size
        const originalSizeMB = (file.size / 1024 / 1024).toFixed(2);
        console.log(`Original photo size: ${originalSizeMB}MB`);

        // Compression options
        const options = {
            maxSizeMB: 2,              // Maximum file size after compression
            maxWidthOrHeight: 1920,    // Maximum dimension (good for mobile viewing)
            useWebWorker: true,        // Use web worker for better performance
            fileType: file.type,       // Preserve original format
            initialQuality: 0.8,       // Initial quality (0-1)
        };

        // Show compression status
        statusDiv.innerHTML = `<span class="text-blue-600">🔄 Compressing ${originalSizeMB}MB photo...</span>`;

        // Compress the image
        const compressedFile = await imageCompression(file, options);

        // Show compressed size
        const compressedSizeMB = (compressedFile.size / 1024 / 1024).toFixed(2);
        const savings = ((1 - compressedFile.size / file.size) * 100).toFixed(0);
        console.log(`Compressed to: ${compressedSizeMB}MB (${savings}% smaller)`);

        // Validate compressed file doesn't exceed 2MB
        if (compressedFile.size > 2 * 1024 * 1024) {
            statusDiv.innerHTML = `<span class="text-amber-600">⚠️ Photo is ${compressedSizeMB}MB after compression. Trying higher compression...</span>`;

            // Try more aggressive compression
            options.maxSizeMB = 1.5;
            options.initialQuality = 0.7;
            const reCompressed = await imageCompression(file, options);

            if (reCompressed.size > 2 * 1024 * 1024) {
                throw new Error(`Photo is still too large (${(reCompressed.size / 1024 / 1024).toFixed(2)}MB) even after compression. Please use a different photo.`);
            }

            // Use the more compressed version
            compressedFile = reCompressed;
        }

        // Create a new File object with the compressed data
        const compressedFileToUpload = new File(
            [compressedFile],
            file.name,
            { type: compressedFile.type }
        );

        // Create a DataTransfer object to set the file input value
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(compressedFileToUpload);
        compressedInput.files = dataTransfer.files;

        // Trigger Livewire upload
        const event = new Event('change', { bubbles: true });
        compressedInput.dispatchEvent(event);

        // Show success status
        statusDiv.innerHTML = `<span class="text-green-600">✅ Ready to upload (${compressedSizeMB}MB, saved ${savings}%)</span>`;

        // Clear original input
        input.value = '';

    } catch (error) {
        console.error('Photo compression error:', error);

        // Show user-friendly error
        statusDiv.innerHTML = `<span class="text-red-600">❌ ${error.message}</span>`;

        // Clear both inputs
        input.value = '';
        compressedInput.value = '';

        // Alert user for critical errors
        if (error.message.includes('too large') || error.message.includes('different photo')) {
            alert(error.message);
        }
    }
}

/**
 * Handle Livewire upload errors (413 Payload Too Large, etc.)
 */
document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, content, preventDefault }) => {
            // Handle 413 Payload Too Large error
            if (status === 413) {
                preventDefault();
                alert('Photo upload failed: File too large for server.\n\nThis usually means the server configuration needs adjustment.\n\nPlease contact your administrator.');
                console.error('413 Payload Too Large - Server rejected upload');
            }

            // Handle 500 errors
            if (status === 500) {
                console.error('500 Server Error during upload:', content);
            }

            // Handle timeout
            if (status === 504 || status === 408) {
                alert('Upload timeout: Please check your internet connection and try again.');
            }
        });
    });
});
</script>

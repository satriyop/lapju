<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public ?int $selectedProjectId = null;

    public ?int $activeTab = null;

    public array $expandedTasks = [];

    public array $progressData = [];

    public string $selectedDate;

    public string $maxDate;

    public function mount(): void
    {
        $this->selectedDate = now()->format('Y-m-d');
        $this->maxDate = now()->format('Y-m-d');

        // Auto-select first project if available
        $firstProject = Project::first();
        if ($firstProject) {
            $this->selectedProjectId = $firstProject->id;
            $this->loadProgressData();

            // Auto-select first root task tab
            $rootTasks = Task::whereNull('parent_id')->orderBy('_lft')->get();
            if ($rootTasks->isNotEmpty()) {
                $this->activeTab = $rootTasks->first()->id;
            }
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
     * @return array<int, array{percentage: float, progress_date: \Carbon\Carbon, notes: ?string}>
     */
    private function getLatestProgressMap(): array
    {
        if (! $this->selectedProjectId) {
            return [];
        }

        // Get the latest progress UP TO the selected date (not all time)
        $latestProgress = TaskProgress::where('project_id', $this->selectedProjectId)
            ->whereDate('progress_date', '<=', $this->selectedDate)
            ->select('task_id')
            ->selectRaw('MAX(progress_date) as latest_date')
            ->groupBy('task_id')
            ->get();

        $progressMap = [];

        foreach ($latestProgress as $progress) {
            $latestEntry = TaskProgress::where('project_id', $this->selectedProjectId)
                ->where('task_id', $progress->task_id)
                ->where('progress_date', $progress->latest_date)
                ->first();

            if ($latestEntry) {
                $progressMap[$progress->task_id] = [
                    'percentage' => (float) $latestEntry->percentage,
                    'progress_date' => $latestEntry->progress_date,
                    'notes' => $latestEntry->notes,
                ];
            }
        }

        return $progressMap;
    }

    /**
     * Calculate recursive average progress for parent tasks.
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

        // For each parent task, calculate average of its descendants
        foreach ($allTasks as $task) {
            if ($task->children->count() > 0) {
                // Get all leaf descendants of this parent
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

        // Reset to first tab when project changes
        $rootTasks = Task::whereNull('parent_id')->orderBy('_lft')->get();
        if ($rootTasks->isNotEmpty()) {
            $this->activeTab = $rootTasks->first()->id;
        }
    }

    public function updatedSelectedDate(): void
    {
        // Validate that date is not in the future
        if ($this->selectedDate > $this->maxDate) {
            $this->selectedDate = $this->maxDate;
            $this->addError('selectedDate', 'Cannot enter progress for future dates.');

            return;
        }

        $this->loadProgressData();
    }

    public function setActiveTab(int $taskId): void
    {
        $this->activeTab = $taskId;
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

        // Validate date is not in the future
        if ($this->selectedDate > $this->maxDate) {
            $this->addError('selectedDate', 'Cannot save progress for future dates.');

            return;
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

    public function with(): array
    {
        $projects = Project::with('location', 'partner')->orderBy('name')->get();
        $rootTasks = Task::whereNull('parent_id')->orderBy('_lft')->get();

        $allTasks = Task::with(['parent:id,name', 'children:id,parent_id'])
            ->orderBy('_lft')
            ->get();

        // Get latest progress for all tasks
        $latestProgressMap = $this->getLatestProgressMap();

        // Calculate parent task progress (including root tasks)
        $parentProgressMap = $this->calculateParentProgress($allTasks, $latestProgressMap);

        return [
            'projects' => $projects,
            'rootTasks' => $rootTasks,
            'allTasks' => $allTasks,
            'latestProgressMap' => $latestProgressMap,
            'parentProgressMap' => $parentProgressMap,
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col">
    <!-- Mobile Header - Sticky -->
    <div class="sticky top-0 z-10 border-b border-neutral-200 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <h1 class="text-lg font-bold text-neutral-900 dark:text-neutral-100">Progress Tracking</h1>
                <p class="text-xs text-neutral-600 dark:text-neutral-400">{{ now()->format('D, M j, Y') }}</p>
            </div>
        </div>

        <!-- Project Selector and Date Picker -->
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-700 dark:text-neutral-300">Project</label>
                <flux:select wire:model.live="selectedProjectId" class="w-full">
                    <option value="">Select Project...</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}">
                            {{ $project->location->city_name }} - {{ Str::limit($project->partner->name, 30) }}
                        </option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                    Progress Date
                    <span class="text-neutral-500">(no future dates)</span>
                </label>
                <flux:input
                    wire:model.live="selectedDate"
                    type="date"
                    max="{{ $maxDate }}"
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
                        Entering progress for:
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
                                // Calculate depth
                                $depth = 0;
                                $current = $task;
                                $parentChain = [];

                                while ($current->parent_id) {
                                    $depth++;
                                    $parent = Task::find($current->parent_id);
                                    if (!$parent) break;
                                    $parentChain[] = $parent->id;
                                    $current = $parent;
                                }

                                $task->depth = $depth;
                                $task->has_children = $task->children->count() > 0;

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
                                                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-neutral-600 dark:text-neutral-400">
                                                    <span>Vol: {{ number_format($task->volume, 2) }} {{ $task->unit ?? '' }}</span>
                                                    <span>•</span>
                                                    <span>Weight: {{ number_format($task->weight, 2) }}</span>
                                                    <span>•</span>
                                                    <span>Price: {{ number_format($task->price, 0) }}</span>
                                                </div>

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
                                                        <span class="font-medium text-blue-800 dark:text-blue-200">Latest Progress:</span>
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
                                                        Updated: {{ $latestProgress['progress_date']->format('M d, Y') }}
                                                        @if($latestProgress['notes'])
                                                            <br>{{ $latestProgress['notes'] }}
                                                        @endif
                                                    </div>
                                                </div>
                                            @else
                                                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                                                    No progress recorded yet for this task
                                                </div>
                                            @endif

                                            <div class="mt-3 space-y-2">
                                                <!-- Percentage Input -->
                                                <div class="flex items-center gap-2">
                                                    <label class="w-16 text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                                        Progress
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
                                                        Notes
                                                    </label>
                                                    <flux:input
                                                        wire:model="progressData.{{ $task->id }}.notes"
                                                        type="text"
                                                        placeholder="Optional notes..."
                                                        class="flex-1"
                                                    />
                                                </div>

                                                <!-- Save Button - Full Width -->
                                                <flux:button
                                                    wire:click="saveProgress({{ $task->id }})"
                                                    variant="primary"
                                                    class="w-full"
                                                >
                                                    Save Progress
                                                </flux:button>
                                            </div>
                                        @else
                                            <!-- Parent Task Indicator -->
                                            <div class="mt-2 text-xs italic text-neutral-500 dark:text-neutral-400">
                                                Parent task ({{ $parentProgress['task_count'] ?? 0 }} leaf tasks) - expand to track child tasks
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endforeach
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
                    No Project Selected
                </h3>
                <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                    Please select a project to start tracking progress
                </p>
            </div>
        </div>
    @endif
</div>

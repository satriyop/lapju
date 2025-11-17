<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public float $volume = 0.00;

    public string $unit = '';

    public float $weight = 0.00;

    public float $price = 0.00;

    public ?int $parentId = null;

    public bool $showModal = false;

    public array $expandedTasks = [];

    public array $validationResults = [];

    public bool $showValidationModal = false;

    public ?int $selectedProjectId = null;

    public function mount(): void
    {
        // Expand all root-level tasks by default
        $this->expandedTasks = Task::whereNull('parent_id')->pluck('id')->toArray();

        // Auto-select first project
        $firstProject = Project::first();
        if ($firstProject) {
            $this->selectedProjectId = $firstProject->id;
        }
    }

    public function toggleExpand(int $taskId): void
    {
        if (in_array($taskId, $this->expandedTasks)) {
            // Collapse: remove from expanded list
            $this->expandedTasks = array_values(array_diff($this->expandedTasks, [$taskId]));
        } else {
            // Expand: add to expanded list
            $this->expandedTasks[] = $taskId;
        }
    }

    public function with(): array
    {
        // Get all projects for the selector
        $projects = Project::with('location', 'customer')->orderBy('name')->get();

        // Get all tasks that can be parents (exclude current task if editing)
        $parentTasks = Task::query()
            ->when($this->editingId, function ($query) {
                // Exclude the task being edited and its descendants
                $editingTask = Task::find($this->editingId);
                if ($editingTask) {
                    $query->where(function ($q) use ($editingTask) {
                        $q->where('id', '!=', $this->editingId)
                            ->where(function ($q2) use ($editingTask) {
                                // Exclude descendants using nested set model
                                $q2->where('_lft', '<', $editingTask->_lft)
                                    ->orWhere('_rgt', '>', $editingTask->_rgt);
                            });
                    });
                }
            })
            ->orderBy('_lft') // Order by nested set left value to maintain hierarchy
            ->get()
            ->map(function ($task) {
                // Calculate depth for indentation
                $depth = 0;
                $current = $task;
                while ($current->parent_id) {
                    $depth++;
                    $current = Task::find($current->parent_id);
                    if (! $current) {
                        break;
                    }
                }
                $task->depth = $depth;
                $task->indented_name = str_repeat('— ', $depth).$task->name;

                return $task;
            });

        // Calculate weight validation
        $leafTaskWeightSum = Task::whereDoesntHave('children')->sum('weight');
        $leafTaskCount = Task::whereDoesntHave('children')->count();
        $weightIsValid = abs($leafTaskWeightSum - 100) < 0.01; // Allow tiny rounding error

        // Calculate total price sum
        $totalPriceSum = Task::whereDoesntHave('children')->sum('total_price');

        // Get progress data for selected project
        $taskProgressMap = [];
        if ($this->selectedProjectId) {
            $latestProgress = TaskProgress::where('project_id', $this->selectedProjectId)
                ->select('task_id')
                ->selectRaw('MAX(progress_date) as latest_date')
                ->groupBy('task_id')
                ->get();

            foreach ($latestProgress as $progress) {
                $latestEntry = TaskProgress::where('project_id', $this->selectedProjectId)
                    ->where('task_id', $progress->task_id)
                    ->where('progress_date', $progress->latest_date)
                    ->first();

                if ($latestEntry) {
                    $taskProgressMap[$progress->task_id] = [
                        'percentage' => (float) $latestEntry->percentage,
                        'progress_date' => $latestEntry->progress_date,
                        'notes' => $latestEntry->notes,
                    ];
                }
            }
        }

        $allTasks = Task::query()
            ->with(['parent:id,name', 'children:id,parent_id'])
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy('_lft')
            ->get();

        // Calculate depth and visibility for each task
        $visibleTasks = collect();
        foreach ($allTasks as $task) {
            $depth = 0;
            $current = $task;
            $parentChain = [];

            while ($current->parent_id) {
                $depth++;
                $parent = Task::find($current->parent_id);
                if (! $parent) {
                    break;
                }
                $parentChain[] = $parent->id;
                $current = $parent;
            }

            $task->depth = $depth;
            $task->has_children = $task->children->count() > 0;

            // Attach progress data if available
            $task->progress_data = $taskProgressMap[$task->id] ?? null;

            // Show task if:
            // 1. It's a root task (no parent), OR
            // 2. All its parents are expanded, OR
            // 3. Search is active (show all matching tasks)
            if ($this->search) {
                $visibleTasks->push($task);
            } elseif ($depth == 0) {
                $visibleTasks->push($task);
            } else {
                // Check if all parents in chain are expanded
                $allParentsExpanded = empty($parentChain) || count(array_intersect($parentChain, $this->expandedTasks)) === count($parentChain);
                if ($allParentsExpanded) {
                    $visibleTasks->push($task);
                }
            }
        }

        return [
            'projects' => $projects,
            'tasks' => $visibleTasks,
            'parentTasks' => $parentTasks,
            'leafTaskWeightSum' => round($leafTaskWeightSum, 4),
            'leafTaskCount' => $leafTaskCount,
            'weightIsValid' => $weightIsValid,
            'totalPriceSum' => $totalPriceSum,
        ];
    }

    public function validateLeafTasks(): void
    {
        $leafTasks = Task::whereDoesntHave('children')->get();

        $errors = [];
        $warnings = [];
        $missingData = [];

        foreach ($leafTasks as $task) {
            $taskErrors = [];

            if (empty($task->volume) || $task->volume <= 0) {
                $taskErrors[] = 'Missing volume';
            }

            if (empty($task->unit)) {
                $taskErrors[] = 'Missing unit';
            }

            if (empty($task->price) || $task->price <= 0) {
                $taskErrors[] = 'Missing price';
            }

            if (empty($task->weight) || $task->weight <= 0) {
                $taskErrors[] = 'Missing weight';
            }

            if (! empty($taskErrors)) {
                $missingData[] = [
                    'id' => $task->id,
                    'name' => $task->name,
                    'errors' => $taskErrors,
                ];
            }
        }

        $weightSum = $leafTasks->sum('weight');
        $weightValid = abs($weightSum - 100) < 0.01;

        if (! $weightValid) {
            $errors[] = "Sum of weights is {$weightSum}, should be 100";
        }

        if (empty($missingData) && $weightValid) {
            $this->validationResults = [
                'status' => 'success',
                'message' => 'All leaf tasks have complete data and weights sum to 100%',
                'missingData' => [],
                'weightSum' => round($weightSum, 4),
            ];
        } else {
            $this->validationResults = [
                'status' => 'error',
                'message' => count($missingData).' leaf tasks have incomplete data',
                'missingData' => $missingData,
                'weightSum' => round($weightSum, 4),
                'weightValid' => $weightValid,
            ];
        }

        $this->showValidationModal = true;
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'volume', 'unit', 'weight', 'price', 'parentId']);
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $task = Task::findOrFail($id);
        $this->editingId = $task->id;
        $this->name = $task->name;
        $this->volume = (float) $task->volume;
        $this->unit = $task->unit ?? '';
        $this->weight = (float) $task->weight;
        $this->price = (float) $task->price;
        $this->parentId = $task->parent_id;
        $this->showModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'volume' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'unit' => ['nullable', 'string', 'max:255'],
            'weight' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'parentId' => ['nullable', 'exists:tasks,id'],
        ]);

        $data = [
            'name' => $validated['name'],
            'volume' => $validated['volume'],
            'unit' => $validated['unit'] ?: null,
            'weight' => $validated['weight'],
            'price' => $validated['price'],
            'parent_id' => $validated['parentId'],
        ];

        if ($this->editingId) {
            Task::findOrFail($this->editingId)->update($data);
        } else {
            Task::create($data);
        }

        $this->reset(['showModal', 'editingId', 'name', 'volume', 'unit', 'weight', 'price', 'parentId']);
        $this->dispatch('task-saved');
    }

    public function delete(int $id): void
    {
        Task::findOrFail($id)->delete();
        $this->dispatch('task-deleted');
    }

    public function cancelEdit(): void
    {
        $this->reset(['showModal', 'editingId', 'name', 'volume', 'unit', 'weight', 'price', 'parentId']);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
            <div class="flex items-center justify-between">
                <flux:heading size="xl">Tasks</flux:heading>
                <flux:button wire:click="create" variant="primary">
                    Create Task
                </flux:button>
            </div>

            <div class="flex flex-wrap items-end gap-4">
                <div class="w-full max-w-md">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search tasks..."
                        type="search"
                    />
                </div>
                <div class="w-full max-w-sm">
                    <flux:select wire:model.live="selectedProjectId" label="View Progress for Project">
                        <option value="">Select a project...</option>
                        @foreach($projects as $project)
                            <option value="{{ $project->id }}">
                                {{ $project->name }} - {{ $project->location->city_name }}
                            </option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <!-- Weight Validation Summary -->
            <div class="rounded-xl border p-4
                @if($weightIsValid)
                    border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950
                @else
                    border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950
                @endif">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        @if($weightIsValid)
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                                <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                        @else
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900">
                                <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                        @endif
                        <div>
                            <h3 class="font-semibold
                                @if($weightIsValid)
                                    text-green-900 dark:text-green-100
                                @else
                                    text-amber-900 dark:text-amber-100
                                @endif">
                                Weight Validation
                            </h3>
                            <p class="text-sm
                                @if($weightIsValid)
                                    text-green-700 dark:text-green-300
                                @else
                                    text-amber-700 dark:text-amber-300
                                @endif">
                                {{ $leafTaskCount }} leaf tasks with total weight:
                                <span class="font-bold">{{ number_format($leafTaskWeightSum, 4) }}%</span>
                                @if(!$weightIsValid)
                                    <span class="font-medium">(should be 100%)</span>
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold
                            @if($weightIsValid)
                                text-green-600 dark:text-green-400
                            @else
                                text-amber-600 dark:text-amber-400
                            @endif">
                            {{ number_format($leafTaskWeightSum, 2) }}%
                        </div>
                        <div class="text-xs
                            @if($weightIsValid)
                                text-green-600 dark:text-green-400
                            @else
                                text-amber-600 dark:text-amber-400
                            @endif">
                            @if($weightIsValid)
                                Valid
                            @else
                                {{ $leafTaskWeightSum > 100 ? 'Over' : 'Under' }} by {{ number_format(abs($leafTaskWeightSum - 100), 4) }}%
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
                <table class="w-full">
                    <thead class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Name</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Progress</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Volume</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Unit</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Weight</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Price</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Total Price</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Parent</th>
                        <th class="px-4 py-3 text-right text-sm font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse($tasks as $task)
                        @php
                            $depth = $task->depth ?? 0;
                            $bgOpacity = min(5 + ($depth * 2), 15); // Subtle background shading
                        @endphp
                        <tr wire:key="task-{{ $task->id }}"
                            class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                            style="background-color: rgba(59, 130, 246, {{ $bgOpacity / 100 }});">
                            <td class="px-4 py-3 text-sm">
                                <div class="flex items-center gap-1" style="padding-left: {{ $depth * 24 }}px">
                                    @if($task->has_children)
                                        <button
                                            type="button"
                                            wire:click="toggleExpand({{ $task->id }})"
                                            class="flex-shrink-0 w-5 h-5 flex items-center justify-center hover:bg-neutral-200 dark:hover:bg-neutral-700 rounded transition-colors"
                                        >
                                            @if(in_array($task->id, $expandedTasks))
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                            @else
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                                </svg>
                                            @endif
                                        </button>
                                    @else
                                        <span class="w-5"></span>
                                    @endif

                                    @if($depth > 0)
                                        <span class="text-neutral-400 dark:text-neutral-600">
                                            @for($i = 0; $i < $depth; $i++)
                                                @if($i == $depth - 1)
                                                    └─
                                                @else
                                                    │&nbsp;&nbsp;
                                                @endif
                                            @endfor
                                        </span>
                                    @endif
                                    <span class="font-medium" style="color: {{ $depth == 0 ? 'rgb(59, 130, 246)' : 'inherit' }}">
                                        {{ $task->name }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @if($task->progress_data)
                                    <div class="flex flex-col gap-1">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-16 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                                                <div
                                                    class="h-full rounded-full transition-all
                                                        @if($task->progress_data['percentage'] >= 100) bg-green-500
                                                        @elseif($task->progress_data['percentage'] >= 75) bg-blue-500
                                                        @elseif($task->progress_data['percentage'] >= 50) bg-yellow-500
                                                        @else bg-red-500
                                                        @endif"
                                                    style="width: {{ min($task->progress_data['percentage'], 100) }}%"
                                                ></div>
                                            </div>
                                            <span class="font-medium
                                                @if($task->progress_data['percentage'] >= 100) text-green-600 dark:text-green-400
                                                @elseif($task->progress_data['percentage'] >= 75) text-blue-600 dark:text-blue-400
                                                @elseif($task->progress_data['percentage'] >= 50) text-yellow-600 dark:text-yellow-400
                                                @else text-red-600 dark:text-red-400
                                                @endif">
                                                {{ number_format($task->progress_data['percentage'], 1) }}%
                                            </span>
                                        </div>
                                        <div class="text-xs text-neutral-500" title="{{ $task->progress_data['notes'] ?? 'No notes' }}">
                                            {{ $task->progress_data['progress_date']->format('M d, Y') }}
                                        </div>
                                    </div>
                                @else
                                    <span class="text-neutral-400 dark:text-neutral-600">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm">{{ number_format($task->volume, 2) }}</td>
                            <td class="px-4 py-3 text-sm">{{ $task->unit ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm">{{ number_format($task->weight, 2) }}</td>
                            <td class="px-4 py-3 text-sm">{{ number_format($task->price, 2) }}</td>
                            <td class="px-4 py-3 text-sm">{{ number_format($task->total_price, 2) }}</td>
                            <td class="px-4 py-3 text-sm">{{ $task->parent?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-right text-sm">
                                <div class="flex items-center justify-end gap-2">
                                    <flux:button wire:click="edit({{ $task->id }})" size="sm" variant="ghost">
                                        Edit
                                    </flux:button>
                                    <flux:button
                                        wire:click="delete({{ $task->id }})"
                                        wire:confirm="Are you sure you want to delete this task?"
                                        size="sm"
                                        variant="ghost"
                                        class="text-red-600 hover:text-red-700"
                                    >
                                        Delete
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-sm text-neutral-500">
                                No tasks found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

            <!-- Task Summary and Validation Card -->
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                <flux:heading size="lg" class="mb-4">Task Summary & Validation</flux:heading>
                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <!-- Total Weight -->
                    <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Total Weight (Leaf Tasks)</div>
                        <div class="mt-2 text-2xl font-bold
                            @if($weightIsValid)
                                text-green-600 dark:text-green-400
                            @else
                                text-amber-600 dark:text-amber-400
                            @endif">
                            {{ number_format($leafTaskWeightSum, 4) }}%
                        </div>
                        <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                            {{ $leafTaskCount }} leaf tasks
                        </div>
                    </div>

                    <!-- Sum of Total Price -->
                    <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Sum of Total Price</div>
                        <div class="mt-2 text-2xl font-bold text-blue-600 dark:text-blue-400">
                            Rp {{ number_format($totalPriceSum, 0, ',', '.') }}
                        </div>
                        <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                            Combined value of all leaf tasks
                        </div>
                    </div>

                    <!-- Validation Action -->
                    <div class="flex flex-col justify-center rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Data Validation</div>
                        <div class="mt-3">
                            <flux:button wire:click="validateLeafTasks" variant="primary" class="w-full">
                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Validate Leaf Tasks
                            </flux:button>
                        </div>
                        <div class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                            Check volume, unit, price, weight & sum = 100%
                        </div>
                    </div>
                </div>
            </div>

    <flux:modal wire:model="showModal" class="min-w-[600px]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Task' : 'Create Task' }}</flux:heading>

            <flux:input
                wire:model="name"
                label="Name"
                type="text"
                required
                autofocus
            />

            <div class="grid grid-cols-2 gap-4">
                <flux:input
                    wire:model="volume"
                    label="Volume"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                />

                <div>
                    <flux:select wire:model="unit" label="Unit" placeholder="Select or type custom unit">
                        <option value="">Select unit...</option>
                        <option value="m">m</option>
                        <option value="m²">m²</option>
                        <option value="m³">m³</option>
                        <option value="kg">kg</option>
                        <option value="unit">unit</option>
                        <option value="lot">lot</option>
                    </flux:select>
                    <flux:input
                        wire:model="unit"
                        type="text"
                        placeholder="Or type custom unit"
                        class="mt-2"
                    />
                </div>
            </div>

            <flux:input
                wire:model="weight"
                label="Weight"
                type="number"
                step="0.01"
                min="0"
                required
            />

            <flux:input
                wire:model="price"
                label="Price"
                type="number"
                step="0.01"
                min="0"
                required
            />

            <flux:select wire:model="parentId" label="Parent Task" placeholder="Select parent task (optional)">
                <option value="">None (Root Level Task)</option>
                @foreach($parentTasks as $parentTask)
                    <option value="{{ $parentTask->id }}">{{ $parentTask->indented_name }}</option>
                @endforeach
            </flux:select>

            <div class="flex items-center justify-end gap-3">
                <flux:button type="button" wire:click="cancelEdit" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $editingId ? 'Update' : 'Create' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <!-- Validation Results Modal -->
    <flux:modal wire:model="showValidationModal" class="min-w-[700px]">
        <div class="space-y-6">
            <div class="flex items-center gap-3">
                @if(!empty($validationResults) && $validationResults['status'] === 'success')
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                        <svg class="h-7 w-7 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <flux:heading size="lg" class="text-green-900 dark:text-green-100">Validation Passed</flux:heading>
                @else
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-900">
                        <svg class="h-7 w-7 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <flux:heading size="lg" class="text-red-900 dark:text-red-100">Validation Failed</flux:heading>
                @endif
            </div>

            @if(!empty($validationResults))
                <div class="rounded-lg border p-4
                    @if($validationResults['status'] === 'success')
                        border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/30
                    @else
                        border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/30
                    @endif">
                    <p class="font-medium
                        @if($validationResults['status'] === 'success')
                            text-green-800 dark:text-green-200
                        @else
                            text-red-800 dark:text-red-200
                        @endif">
                        {{ $validationResults['message'] }}
                    </p>
                    <p class="mt-2 text-sm
                        @if($validationResults['status'] === 'success')
                            text-green-700 dark:text-green-300
                        @else
                            text-red-700 dark:text-red-300
                        @endif">
                        Weight Sum: <span class="font-bold">{{ $validationResults['weightSum'] }}%</span>
                        @if(isset($validationResults['weightValid']) && !$validationResults['weightValid'])
                            <span class="ml-2 rounded bg-red-200 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-800 dark:text-red-200">
                                Should be 100%
                            </span>
                        @endif
                    </p>
                </div>

                @if(!empty($validationResults['missingData']))
                    <div class="max-h-96 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                        <div class="border-b border-neutral-200 bg-neutral-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800">
                            <h4 class="font-semibold text-neutral-900 dark:text-neutral-100">
                                Tasks with Missing Data ({{ count($validationResults['missingData']) }})
                            </h4>
                        </div>
                        <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach($validationResults['missingData'] as $task)
                                <div class="p-4">
                                    <div class="font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ $task['name'] }}
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach($task['errors'] as $error)
                                            <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-200">
                                                {{ $error }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif

            <div class="flex justify-end">
                <flux:button wire:click="$set('showValidationModal', false)" variant="primary">
                    Close
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>

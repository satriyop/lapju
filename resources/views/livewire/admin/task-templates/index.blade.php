<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use Illuminate\Support\Facades\Cache;
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

    public array $expandedTemplates = [];

    // Warning modal properties
    public int $affectedProjectsCount = 0;

    public int $tasksWithProgressCount = 0;

    public bool $showWarningModal = false;

    public ?int $pendingEditId = null;

    // Sync modal properties
    public bool $showSyncModal = false;

    public ?int $syncTemplateId = null;

    public int $syncTasksCount = 0;

    public int $syncProjectsCount = 0;

    public function mount(): void
    {
        // Expand all root-level templates by default
        $this->expandedTemplates = TaskTemplate::whereNull('parent_id')->pluck('id')->toArray();
    }

    public function toggleExpand(int $templateId): void
    {
        if (in_array($templateId, $this->expandedTemplates)) {
            // Collapse: remove from expanded list
            $this->expandedTemplates = array_values(array_diff($this->expandedTemplates, [$templateId]));
        } else {
            // Expand: add to expanded list
            $this->expandedTemplates[] = $templateId;
        }
    }

    public function with(): array
    {
        // Get all templates that can be parents (exclude current template if editing)
        $parentTemplates = TaskTemplate::query()
            ->when($this->editingId, function ($query) {
                // Exclude the template being edited and its descendants
                $editingTemplate = TaskTemplate::find($this->editingId);
                if ($editingTemplate) {
                    $query->where(function ($q) use ($editingTemplate) {
                        $q->where('id', '!=', $this->editingId)
                            ->where(function ($q2) use ($editingTemplate) {
                                // Exclude descendants using nested set model
                                $q2->where('_lft', '<', $editingTemplate->_lft)
                                    ->orWhere('_rgt', '>', $editingTemplate->_rgt);
                            });
                    });
                }
            })
            ->orderBy('_lft')
            ->get()
            ->keyBy('id'); // Index by ID for O(1) lookup

        // Calculate depth in memory using parent lookup map
        foreach ($parentTemplates as $template) {
            $depth = 0;
            $currentId = $template->parent_id;

            // Traverse ancestors using in-memory lookup instead of database queries
            while ($currentId && isset($parentTemplates[$currentId])) {
                $depth++;
                $currentId = $parentTemplates[$currentId]->parent_id;

                // Prevent infinite loops
                if ($depth > 100) {
                    break;
                }
            }

            $template->depth = $depth;
            $template->indented_name = str_repeat('— ', $depth).$template->name;
        }

        // Get statistics using single optimized query with caching
        $stats = Cache::remember('task_template_stats', 3600, function () {
            return \Illuminate\Support\Facades\DB::table('task_templates')
                ->selectRaw('
                    SUM(price * volume) as total_price,
                    SUM(weight) as total_weight,
                    COUNT(CASE WHEN NOT EXISTS (
                        SELECT 1 FROM task_templates children
                        WHERE children.parent_id = task_templates.id
                    ) THEN 1 END) as total_leaf_tasks,
                    COUNT(CASE WHEN volume > 0 OR price > 0 THEN 1 END) as templates_with_data
                ')
                ->first();
        });

        $totalPrice = $stats->total_price ?? 0;
        $totalWeight = $stats->total_weight ?? 0;
        $totalLeafTasks = $stats->total_leaf_tasks ?? 0;
        $templatesWithData = $stats->templates_with_data ?? 0;

        $allTemplates = TaskTemplate::query()
            ->with(['parent:id,name', 'children:id,parent_id'])
            ->withCount('tasks')
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy('_lft')
            ->get();

        // Build lookup map for O(1) access
        $templatesById = $allTemplates->keyBy('id');

        // Calculate depth and visibility for each template
        $visibleTemplates = collect();
        foreach ($allTemplates as $template) {
            $depth = 0;
            $parentChain = [];
            $currentId = $template->parent_id;

            // Traverse ancestors using in-memory lookup instead of database queries
            while ($currentId && isset($templatesById[$currentId])) {
                $depth++;
                $parentChain[] = $currentId;
                $currentId = $templatesById[$currentId]->parent_id;

                // Prevent infinite loops
                if ($depth > 100) {
                    break;
                }
            }

            $template->depth = $depth;
            $template->has_children = $template->children->count() > 0;

            // Show template if:
            // 1. It's a root template (no parent), OR
            // 2. All its parents are expanded, OR
            // 3. Search is active (show all matching templates)
            if ($this->search) {
                $visibleTemplates->push($template);
            } elseif ($depth == 0) {
                $visibleTemplates->push($template);
            } else {
                // Check if all parents in chain are expanded
                $allParentsExpanded = empty($parentChain) || count(array_intersect($parentChain, $this->expandedTemplates)) === count($parentChain);
                if ($allParentsExpanded) {
                    $visibleTemplates->push($template);
                }
            }
        }

        return [
            'templates' => $visibleTemplates,
            'parentTemplates' => $parentTemplates,
            'totalPrice' => $totalPrice,
            'totalWeight' => $totalWeight,
            'totalLeafTasks' => $totalLeafTasks,
            'templatesWithData' => $templatesWithData,
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'volume', 'unit', 'weight', 'price', 'parentId']);
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        // Check for affected projects/tasks with caching (5 minutes)
        $this->affectedProjectsCount = Cache::remember(
            "template_{$id}_affected_projects",
            300,
            fn () => Project::whereHas('tasks', fn ($q) => $q->where('template_task_id', $id))->count()
        );

        $this->tasksWithProgressCount = Cache::remember(
            "template_{$id}_tasks_with_progress",
            300,
            fn () => Task::where('template_task_id', $id)->whereHas('progress')->count()
        );

        // If there are affected items, show warning first
        if ($this->affectedProjectsCount > 0 || $this->tasksWithProgressCount > 0) {
            $this->pendingEditId = $id;
            $this->showWarningModal = true;
        } else {
            $this->proceedWithEdit($id);
        }
    }

    public function proceedWithEdit(int $id): void
    {
        $template = TaskTemplate::findOrFail($id);
        $this->editingId = $template->id;
        $this->name = $template->name;
        $this->volume = (float) $template->volume;
        $this->unit = $template->unit ?? '';
        $this->weight = (float) $template->weight;
        $this->price = (float) $template->price;
        $this->parentId = $template->parent_id;
        $this->showWarningModal = false;
        $this->showModal = true;
    }

    public function confirmEdit(): void
    {
        if ($this->pendingEditId) {
            $this->proceedWithEdit($this->pendingEditId);
            $this->pendingEditId = null;
        }
    }

    public function cancelWarning(): void
    {
        $this->showWarningModal = false;
        $this->pendingEditId = null;
        $this->affectedProjectsCount = 0;
        $this->tasksWithProgressCount = 0;
    }

    public function showSyncConfirmation(int $id): void
    {
        $this->syncTemplateId = $id;
        $this->syncProjectsCount = Project::whereHas('tasks', fn ($q) => $q->where('template_task_id', $id))->count();
        $this->syncTasksCount = Task::where('template_task_id', $id)->count();
        $this->showSyncModal = true;
    }

    public function syncToProjects(): void
    {
        if (! $this->syncTemplateId) {
            return;
        }

        $template = TaskTemplate::findOrFail($this->syncTemplateId);

        // Update all tasks from this template in a single query
        Task::where('template_task_id', $this->syncTemplateId)
            ->update([
                'name' => $template->name,
                'volume' => $template->volume,
                'unit' => $template->unit,
                'weight' => $template->weight,
                'price' => $template->price,
            ]);

        // Recalculate total_price for each task (trigger model event)
        Task::where('template_task_id', $this->syncTemplateId)
            ->each(fn ($task) => $task->save());

        $this->showSyncModal = false;
        $this->syncTemplateId = null;
        $this->syncTasksCount = 0;
        $this->syncProjectsCount = 0;
        $this->dispatch('tasks-synced');
    }

    public function cancelSync(): void
    {
        $this->showSyncModal = false;
        $this->syncTemplateId = null;
        $this->syncTasksCount = 0;
        $this->syncProjectsCount = 0;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'volume' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'unit' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'parentId' => ['nullable', 'exists:task_templates,id'],
        ]);

        $data = [
            'name' => $validated['name'],
            'volume' => $validated['volume'] ?? 0,
            'unit' => $validated['unit'] ?: null,
            'weight' => $validated['weight'] ?? 0,
            'price' => $validated['price'] ?? 0,
            'parent_id' => $validated['parentId'],
        ];

        if ($this->editingId) {
            TaskTemplate::findOrFail($this->editingId)->update($data);
        } else {
            // Calculate nested set values for new template
            $counter = TaskTemplate::max('_rgt') ?? 0;
            $data['_lft'] = $counter + 1;
            $data['_rgt'] = $counter + 2;
            TaskTemplate::create($data);
        }

        // Clear statistics cache when templates change
        Cache::forget('task_template_stats');

        // Clear edit warning caches for this template
        if ($this->editingId) {
            Cache::forget("template_{$this->editingId}_affected_projects");
            Cache::forget("template_{$this->editingId}_tasks_with_progress");
        }

        $this->reset(['showModal', 'editingId', 'name', 'volume', 'unit', 'weight', 'price', 'parentId']);
        $this->dispatch('template-saved');
    }

    public function delete(int $id): void
    {
        TaskTemplate::findOrFail($id)->delete();

        // Clear statistics cache when templates change
        Cache::forget('task_template_stats');

        // Clear edit warning caches for this template
        Cache::forget("template_{$id}_affected_projects");
        Cache::forget("template_{$id}_tasks_with_progress");

        $this->dispatch('template-deleted');
    }

    public function cancelEdit(): void
    {
        $this->reset(['showModal', 'editingId', 'name', 'volume', 'unit', 'weight', 'price', 'parentId']);
    }

    public function expandAll(): void
    {
        $this->expandedTemplates = TaskTemplate::whereHas('children')->pluck('id')->toArray();
    }

    public function collapseAll(): void
    {
        $this->expandedTemplates = TaskTemplate::whereNull('parent_id')->pluck('id')->toArray();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Task Templates</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button wire:click="expandAll" variant="ghost" size="sm">
                <svg class="mr-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                </svg>
                Expand All
            </flux:button>
            <flux:button wire:click="collapseAll" variant="ghost" size="sm">
                <svg class="mr-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25"></path>
                </svg>
                Collapse All
            </flux:button>
            <flux:button wire:click="create" variant="primary">
                Create Template
            </flux:button>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-md">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="Search templates..."
                type="search"
            />
        </div>
    </div>

    <!-- Statistics Summary -->
    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Total Price</div>
            <div class="mt-2 text-2xl font-bold text-blue-600 dark:text-blue-400">Rp {{ number_format($totalPrice, 0, ',', '.') }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Total Weight</div>
            <div class="mt-2 text-2xl font-bold text-green-600 dark:text-green-400">{{ number_format($totalWeight, 2) }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Task</div>
            <div class="mt-2 text-2xl font-bold text-purple-600 dark:text-purple-400">{{ $totalLeafTasks }}</div>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">With Data</div>
            <div class="mt-2 text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $templatesWithData }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full">
            <thead class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
            <tr>
                <th class="px-4 py-3 text-left text-sm font-semibold">Name</th>
                <th class="px-4 py-3 text-left text-sm font-semibold">Volume</th>
                <th class="px-4 py-3 text-left text-sm font-semibold">Unit</th>
                <th class="px-4 py-3 text-left text-sm font-semibold">Weight</th>
                <th class="px-4 py-3 text-left text-sm font-semibold">Price</th>
                <th class="px-4 py-3 text-right text-sm font-semibold">Actions</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($templates as $template)
                    @php
                        $depth = $template->depth ?? 0;
                        $bgOpacity = min(5 + ($depth * 2), 15);
                        $isContainer = $template->has_children && $template->volume == 0 && $template->price == 0;
                    @endphp
                    <tr wire:key="template-{{ $template->id }}"
                        class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                        style="background-color: rgba({{ $isContainer ? '147, 51, 234' : '59, 130, 246' }}, {{ $bgOpacity / 100 }});">
                        <td class="px-4 py-3 text-sm">
                            <div class="flex items-center gap-1" style="padding-left: {{ $depth * 24 }}px">
                                @if($template->has_children)
                                    <button
                                        type="button"
                                        wire:click="toggleExpand({{ $template->id }})"
                                        class="flex-shrink-0 w-5 h-5 flex items-center justify-center hover:bg-neutral-200 dark:hover:bg-neutral-700 rounded transition-colors"
                                    >
                                        @if(in_array($template->id, $expandedTemplates))
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
                                <span class="font-medium {{ $isContainer ? 'text-purple-600 dark:text-purple-400' : '' }}" style="color: {{ $depth == 0 && !$isContainer ? 'rgb(59, 130, 246)' : 'inherit' }}">
                                    {{ $template->name }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">{{ $template->volume > 0 ? number_format($template->volume, 2) : '-' }}</td>
                        <td class="px-4 py-3 text-sm">{{ $template->unit ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm">{{ $template->weight > 0 ? number_format($template->weight, 2) : '-' }}</td>
                        <td class="px-4 py-3 text-sm">{{ $template->price > 0 ? number_format($template->price, 2) : '-' }}</td>
                        <td class="px-4 py-3 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                @if($template->tasks_count > 0 && !$template->has_children)
                                    <flux:button
                                        wire:click="showSyncConfirmation({{ $template->id }})"
                                        size="sm"
                                        variant="ghost"
                                        class="text-blue-600 hover:text-blue-700"
                                    >
                                        Sync
                                    </flux:button>
                                @endif
                                <flux:button wire:click="edit({{ $template->id }})" size="sm" variant="ghost">
                                    Edit
                                </flux:button>
                                <flux:button
                                    wire:click="delete({{ $template->id }})"
                                    wire:confirm="Are you sure you want to delete this template?"
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
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-neutral-500">
                            No templates found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal wire:model="showModal" class="min-w-[600px]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Template' : 'Create Template' }}</flux:heading>

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
                    placeholder="0 for containers"
                />

                <div>
                    <flux:select wire:model="unit" label="Unit" placeholder="Select or type custom unit">
                        <option value="">Select unit...</option>
                        <option value="m">m</option>
                        <option value="m'">m'</option>
                        <option value="m²">m²</option>
                        <option value="m³">m³</option>
                        <option value="kg">kg</option>
                        <option value="unit">unit</option>
                        <option value="bh">bh</option>
                        <option value="titik">titik</option>
                        <option value="ls">ls</option>
                        <option value="lot">lot</option>
                        <option value="set">set</option>
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
                placeholder="0 for containers"
            />

            <flux:input
                wire:model="price"
                label="Price"
                type="number"
                step="0.01"
                min="0"
                placeholder="0 for containers"
            />

            <flux:select wire:model="parentId" label="Parent Template" placeholder="Select parent template (optional)">
                <option value="">None (Root Level Template)</option>
                @foreach($parentTemplates as $parentTemplate)
                    <option value="{{ $parentTemplate->id }}">{{ $parentTemplate->indented_name }}</option>
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

    <!-- Warning Modal for editing templates with active projects -->
    <flux:modal wire:model="showWarningModal" class="max-w-md">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                    <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <flux:heading size="lg">Edit Warning</flux:heading>
            </div>

            <flux:text class="text-neutral-600 dark:text-neutral-400">
                This template has been used in existing projects. Editing will NOT automatically update tasks in those projects.
            </flux:text>

            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                <ul class="space-y-1 text-sm text-amber-800 dark:text-amber-200">
                    @if($affectedProjectsCount > 0)
                        <li>{{ $affectedProjectsCount }} project(s) have tasks from this template</li>
                    @endif
                    @if($tasksWithProgressCount > 0)
                        <li>{{ $tasksWithProgressCount }} task(s) already have progress entries</li>
                    @endif
                </ul>
            </div>

            <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">
                Existing task values and progress data will remain unchanged.
            </flux:text>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="cancelWarning" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button wire:click="confirmEdit" variant="primary">
                    Continue Editing
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Sync Confirmation Modal -->
    <flux:modal wire:model="showSyncModal" class="max-w-md">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                    <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </div>
                <flux:heading size="lg">Sync to Projects</flux:heading>
            </div>

            <flux:text class="text-neutral-600 dark:text-neutral-400">
                This will update task values in all existing projects to match the current template values.
            </flux:text>

            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-900/20">
                <ul class="space-y-1 text-sm text-blue-800 dark:text-blue-200">
                    <li>{{ $syncProjectsCount }} project(s) will be updated</li>
                    <li>{{ $syncTasksCount }} task(s) will be synced</li>
                </ul>
            </div>

            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                <p class="text-sm text-amber-800 dark:text-amber-200">
                    <strong>Note:</strong> Progress percentages will NOT change. Only task values (volume, price, weight, unit) will be updated.
                </p>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="cancelSync" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button wire:click="syncToProjects" variant="primary">
                    Sync All
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>

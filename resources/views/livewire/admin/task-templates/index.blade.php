<?php

use App\Models\TaskTemplate;
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
            ->map(function ($template) {
                // Calculate depth for indentation
                $depth = 0;
                $current = $template;
                while ($current->parent_id) {
                    $depth++;
                    $current = TaskTemplate::find($current->parent_id);
                    if (! $current) {
                        break;
                    }
                }
                $template->depth = $depth;
                $template->indented_name = str_repeat('— ', $depth).$template->name;

                return $template;
            });

        // Get statistics
        $totalPrice = TaskTemplate::all()->sum(function ($template) {
            return $template->price * $template->volume;
        });
        $totalWeight = TaskTemplate::sum('weight');
        $totalLeafTasks = TaskTemplate::whereDoesntHave('children')->count();
        $templatesWithData = TaskTemplate::where(function ($q) {
            $q->where('volume', '>', 0)->orWhere('price', '>', 0);
        })->count();

        $allTemplates = TaskTemplate::query()
            ->with(['parent:id,name', 'children:id,parent_id'])
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy('_lft')
            ->get();

        // Calculate depth and visibility for each template
        $visibleTemplates = collect();
        foreach ($allTemplates as $template) {
            $depth = 0;
            $current = $template;
            $parentChain = [];

            while ($current->parent_id) {
                $depth++;
                $parent = TaskTemplate::find($current->parent_id);
                if (! $parent) {
                    break;
                }
                $parentChain[] = $parent->id;
                $current = $parent;
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
        $template = TaskTemplate::findOrFail($id);
        $this->editingId = $template->id;
        $this->name = $template->name;
        $this->volume = (float) $template->volume;
        $this->unit = $template->unit ?? '';
        $this->weight = (float) $template->weight;
        $this->price = (float) $template->price;
        $this->parentId = $template->parent_id;
        $this->showModal = true;
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

        $this->reset(['showModal', 'editingId', 'name', 'volume', 'unit', 'weight', 'price', 'parentId']);
        $this->dispatch('template-saved');
    }

    public function delete(int $id): void
    {
        TaskTemplate::findOrFail($id)->delete();
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
</div>

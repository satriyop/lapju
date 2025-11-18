<?php

use App\Models\Partner;
use App\Models\Location;
use App\Models\Project;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout};

layout('components.layouts.app');

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public ?int $partnerId = null;

    public ?int $locationId = null;

    public ?string $startDate = null;

    public ?string $endDate = null;

    public string $status = 'planning';

    public bool $showModal = false;

    public function with(): array
    {
        $projects = Project::query()
            ->with(['partner', 'location'])
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate(20);

        $partners = Partner::orderBy('name')->get();
        $locations = Location::orderBy('province_name')->orderBy('city_name')->orderBy('village_name')->get();

        return [
            'projects' => $projects,
            'partners' => $partners,
            'locations' => $locations,
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'description', 'partnerId', 'locationId', 'startDate', 'endDate', 'status']);
        $this->status = 'planning';
        $this->startDate = '2025-11-01';
        $this->endDate = '2026-01-31';
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $project = Project::findOrFail($id);
        $this->editingId = $project->id;
        $this->name = $project->name;
        $this->description = $project->description ?? '';
        $this->partnerId = $project->partner_id;
        $this->locationId = $project->location_id;
        $this->startDate = $project->start_date?->format('Y-m-d');
        $this->endDate = $project->end_date?->format('Y-m-d');
        $this->status = $project->status;
        $this->showModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'partnerId' => ['required', 'exists:partners,id'],
            'locationId' => ['required', 'exists:locations,id'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'status' => ['required', 'in:planning,active,completed,on_hold'],
        ]);

        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'partner_id' => $validated['partnerId'],
            'location_id' => $validated['locationId'],
            'start_date' => $validated['startDate'],
            'end_date' => $validated['endDate'],
            'status' => $validated['status'],
        ];

        if ($this->editingId) {
            Project::findOrFail($this->editingId)->update($data);
        } else {
            Project::create($data);
        }

        $this->reset(['showModal', 'editingId', 'name', 'description', 'partnerId', 'locationId', 'startDate', 'endDate', 'status']);
        $this->dispatch('project-saved');
    }

    public function delete(int $id): void
    {
        Project::findOrFail($id)->delete();
        $this->dispatch('project-deleted');
    }

    public function cancelEdit(): void
    {
        $this->reset(['showModal', 'editingId', 'name', 'description', 'partnerId', 'locationId', 'startDate', 'endDate', 'status']);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Projects</flux:heading>
        <flux:button wire:click="create" variant="primary">
            Create Project
        </flux:button>
    </div>

    <div class="w-full max-w-md">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search projects..."
            type="search"
        />
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full">
            <thead class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Name</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Partner</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Location</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Status</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">Start Date</th>
                    <th class="px-4 py-3 text-left text-sm font-semibold">End Date</th>
                    <th class="px-4 py-3 text-right text-sm font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($projects as $project)
                    <tr wire:key="project-{{ $project->id }}"
                        class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3 text-sm font-medium">{{ $project->name }}</td>
                        <td class="px-4 py-3 text-sm">{{ $project->partner->name }}</td>
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium">{{ $project->location->village_name }}</div>
                            <div class="text-xs text-neutral-500">{{ $project->location->city_name }}, {{ $project->location->province_name }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium
                                @if($project->status === 'planning') bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300
                                @elseif($project->status === 'active') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300
                                @elseif($project->status === 'completed') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300
                                @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300
                                @endif">
                                {{ ucfirst(str_replace('_', ' ', $project->status)) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm">{{ $project->start_date?->format('M d, Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm">{{ $project->end_date?->format('M d, Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                <flux:button wire:click="edit({{ $project->id }})" size="sm" variant="ghost">
                                    Edit
                                </flux:button>
                                <flux:button
                                    wire:click="delete({{ $project->id }})"
                                    wire:confirm="Are you sure you want to delete this project?"
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
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-neutral-500">
                            No projects found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $projects->links() }}
    </div>

    <flux:modal wire:model="showModal" class="min-w-[600px]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Project' : 'Create Project' }}</flux:heading>

            <flux:input
                wire:model="name"
                label="Project Name"
                type="text"
                required
                autofocus
            />

            <flux:textarea
                wire:model="description"
                label="Description"
                rows="3"
            />

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="partnerId" label="Partner" required>
                    <option value="">Select partner...</option>
                    @foreach($partners as $partner)
                        <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="locationId" label="Location" required>
                    <option value="">Select location...</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}">
                            {{ $location->village_name }} ({{ $location->city_name }})
                        </option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:input
                    wire:model="startDate"
                    label="Start Date"
                    type="date"
                />

                <flux:input
                    wire:model="endDate"
                    label="End Date"
                    type="date"
                />
            </div>

            <flux:select wire:model="status" label="Status" required>
                <option value="planning">Planning</option>
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="on_hold">On Hold</option>
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

<?php

use App\Models\Location;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public string $search = '';

    public ?int $editingId = null;

    public string $villageName = '';

    public string $cityName = '';

    public string $provinceName = '';

    public string $notes = '';

    public bool $showModal = false;

    public function create(): void
    {
        $this->reset(['editingId', 'villageName', 'cityName', 'provinceName', 'notes']);
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $location = Location::findOrFail($id);
        $this->editingId = $location->id;
        $this->villageName = $location->village_name;
        $this->cityName = $location->city_name;
        $this->provinceName = $location->province_name;
        $this->notes = $location->notes ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'villageName' => 'required|string|max:255',
            'cityName' => 'required|string|max:255',
            'provinceName' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($this->editingId) {
            $location = Location::findOrFail($this->editingId);
            $location->update([
                'village_name' => $this->villageName,
                'city_name' => $this->cityName,
                'province_name' => $this->provinceName,
                'notes' => $this->notes ?: null,
            ]);
        } else {
            Location::create([
                'village_name' => $this->villageName,
                'city_name' => $this->cityName,
                'province_name' => $this->provinceName,
                'notes' => $this->notes ?: null,
            ]);
        }

        $this->showModal = false;
        $this->reset(['editingId', 'villageName', 'cityName', 'provinceName', 'notes']);
    }

    public function delete(int $id): void
    {
        $location = Location::findOrFail($id);
        if ($location->projects()->count() === 0) {
            $location->delete();
        } else {
            session()->flash('error', 'Cannot delete location with associated projects.');
        }
    }

    public function with(): array
    {
        $locations = Location::query()
            ->when($this->search, fn ($q) => $q->where('village_name', 'like', "%{$this->search}%")
                ->orWhere('city_name', 'like', "%{$this->search}%")
                ->orWhere('province_name', 'like', "%{$this->search}%"))
            ->withCount('projects')
            ->orderBy('province_name')
            ->orderBy('city_name')
            ->orderBy('village_name')
            ->get();

        return [
            'locations' => $locations,
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Locations</flux:heading>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                Manage project locations (cities and provinces)
            </p>
        </div>
        <flux:button wire:click="create" variant="primary">
            <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Add Location
        </flux:button>
    </div>

    @if(session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    <!-- Search -->
    <div class="max-w-md">
        <flux:input
            wire:model.live.debounce.300ms="search"
            type="search"
            placeholder="Search by name, city, or province..."
        />
    </div>

    <!-- Locations Table -->
    <div class="overflow-x-auto rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Village Name</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">City / Province</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Notes</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Projects</th>
                    <th class="px-4 py-3 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($locations as $location)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900">
                                    <svg class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                </div>
                                <div class="font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ $location->village_name }}
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-neutral-900 dark:text-neutral-100">{{ $location->city_name }}</div>
                            <div class="text-sm text-neutral-500">{{ $location->province_name }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                            @if($location->notes)
                                {{ Str::limit($location->notes, 50) }}
                            @else
                                <span class="text-neutral-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($location->projects_count > 0)
                                <flux:badge>{{ $location->projects_count }} project(s)</flux:badge>
                            @else
                                <span class="text-neutral-400">No projects</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <flux:button wire:click="edit({{ $location->id }})" size="sm" variant="outline">
                                    Edit
                                </flux:button>
                                @if($location->projects_count === 0)
                                    <flux:button wire:click="delete({{ $location->id }})" size="sm" variant="danger" wire:confirm="Are you sure you want to delete this location?">
                                        Delete
                                    </flux:button>
                                @else
                                    <flux:button size="sm" variant="danger" disabled title="Cannot delete - has projects">
                                        Delete
                                    </flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-neutral-600 dark:text-neutral-400">
                            No locations found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Summary -->
    <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
        <flux:heading size="lg" class="mb-4">Summary</flux:heading>
        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Total Locations</div>
                <div class="mt-1 text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                    {{ $locations->count() }}
                </div>
            </div>
            <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Provinces Covered</div>
                <div class="mt-1 text-2xl font-bold text-blue-600 dark:text-blue-400">
                    {{ $locations->pluck('province_name')->unique()->count() }}
                </div>
            </div>
            <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Total Projects</div>
                <div class="mt-1 text-2xl font-bold text-green-600 dark:text-green-400">
                    {{ $locations->sum('projects_count') }}
                </div>
            </div>
        </div>
    </div>

    <!-- Create/Edit Modal -->
    <flux:modal wire:model="showModal" class="min-w-[500px]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Location' : 'Add Location' }}</flux:heading>

            <flux:input
                wire:model="villageName"
                label="Village Name"
                type="text"
                placeholder="e.g., Jebres, Mojosongo"
                required
            />

            <div class="grid grid-cols-2 gap-4">
                <flux:input
                    wire:model="cityName"
                    label="City"
                    type="text"
                    placeholder="e.g., Semarang"
                    required
                />

                <flux:input
                    wire:model="provinceName"
                    label="Province"
                    type="text"
                    placeholder="e.g., Jawa Tengah"
                    required
                />
            </div>

            <flux:textarea
                wire:model="notes"
                label="Notes (Optional)"
                placeholder="Additional information about this location..."
                rows="3"
            />

            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showModal', false)" variant="outline">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $editingId ? 'Update' : 'Create' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>

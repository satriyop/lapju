<?php

use App\Models\Office;
use App\Models\OfficeLevel;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('components.layouts.app');

new class extends Component
{
    public $offices = [];

    public $officeLevels = [];

    // Form fields
    public $name = '';

    public $code = '';

    public $level_id = null;

    public $parent_id = null;

    public $notes = '';

    // UI state
    public $showCreateModal = false;

    public $showEditModal = false;

    public $showDeleteModal = false;

    public $editingOfficeId = null;

    public $deletingOfficeId = null;

    public function mount(): void
    {
        $this->loadOffices();
        $this->loadOfficeLevels();
    }

    public function loadOffices(): void
    {
        // Load all offices ordered by nested set left value for hierarchical display
        $this->offices = Office::with('level', 'parent')
            ->orderBy('_lft')
            ->get();
    }

    public function loadOfficeLevels(): void
    {
        $this->officeLevels = OfficeLevel::orderBy('level')->get();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEditModal($officeId): void
    {
        $office = Office::findOrFail($officeId);

        $this->editingOfficeId = $office->id;
        $this->name = $office->name;
        $this->code = $office->code;
        $this->level_id = $office->level_id;
        $this->parent_id = $office->parent_id;
        $this->notes = $office->notes ?? '';

        $this->showEditModal = true;
    }

    public function openDeleteModal($officeId): void
    {
        $this->deletingOfficeId = $officeId;
        $this->showDeleteModal = true;
    }

    public function closeModals(): void
    {
        $this->showCreateModal = false;
        $this->showEditModal = false;
        $this->showDeleteModal = false;
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->editingOfficeId = null;
        $this->deletingOfficeId = null;
        $this->name = '';
        $this->code = '';
        $this->level_id = null;
        $this->parent_id = null;
        $this->notes = '';
        $this->resetValidation();
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'level_id' => 'required|exists:office_levels,id',
            'parent_id' => 'nullable|exists:offices,id',
            'notes' => 'nullable|string',
        ]);

        Office::create($validated);

        $this->closeModals();
        $this->loadOffices();

        session()->flash('message', 'Office created successfully.');
    }

    public function update(): void
    {
        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'level_id' => 'required|exists:office_levels,id',
            'parent_id' => 'nullable|exists:offices,id',
            'notes' => 'nullable|string',
        ]);

        $office = Office::findOrFail($this->editingOfficeId);

        // Prevent setting parent to itself or its descendants
        if ($validated['parent_id'] && $validated['parent_id'] == $office->id) {
            $this->addError('parent_id', 'An office cannot be its own parent.');

            return;
        }

        $office->update($validated);

        $this->closeModals();
        $this->loadOffices();

        session()->flash('message', 'Office updated successfully.');
    }

    public function delete(): void
    {
        $office = Office::findOrFail($this->deletingOfficeId);

        // Check if office has children
        if ($office->children()->count() > 0) {
            session()->flash('error', 'Cannot delete office with child offices. Delete children first.');
            $this->closeModals();

            return;
        }

        // Check if office has projects
        if ($office->projects()->count() > 0) {
            session()->flash('error', 'Cannot delete office with assigned projects.');
            $this->closeModals();

            return;
        }

        $office->delete();

        $this->closeModals();
        $this->loadOffices();

        session()->flash('message', 'Office deleted successfully.');
    }

    public function getAvailableParentsProperty()
    {
        if (! $this->level_id) {
            return collect();
        }

        $selectedLevel = OfficeLevel::find($this->level_id);
        if (! $selectedLevel || $selectedLevel->level <= 1) {
            return collect();
        }

        // Get parent level
        $parentLevel = OfficeLevel::where('level', $selectedLevel->level - 1)->first();
        if (! $parentLevel) {
            return collect();
        }

        // Get offices at parent level
        $query = Office::where('level_id', $parentLevel->id)->orderBy('name');

        // Exclude self when editing
        if ($this->editingOfficeId) {
            $query->where('id', '!=', $this->editingOfficeId);
        }

        return $query->get();
    }

    public function getOfficeDepth($office): int
    {
        $depth = 0;
        $current = $office;

        while ($current->parent_id) {
            $depth++;
            $current = $current->parent;
            if (! $current) {
                break;
            }
        }

        return $depth;
    }

    public function with(): array
    {
        return [
            'offices' => $this->offices,
            'officeLevels' => $this->officeLevels,
            'availableParents' => $this->availableParents,
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Office Management</flux:heading>
        <flux:button wire:click="openCreateModal" variant="primary">
            Add Office
        </flux:button>
    </div>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <div class="rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950">
            <p class="text-green-800 dark:text-green-200">{{ session('message') }}</p>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950">
            <p class="text-red-800 dark:text-red-200">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Offices Table -->
    <div class="rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <div class="border-b border-neutral-200 p-6 dark:border-neutral-700">
            <flux:heading size="lg">Offices ({{ count($offices) }})</flux:heading>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-sm font-medium text-neutral-600 dark:text-neutral-400">Name</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-neutral-600 dark:text-neutral-400">Code</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-neutral-600 dark:text-neutral-400">Level</th>
                        <th class="px-6 py-3 text-left text-sm font-medium text-neutral-600 dark:text-neutral-400">Parent</th>
                        <th class="px-6 py-3 text-right text-sm font-medium text-neutral-600 dark:text-neutral-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse($offices as $office)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    @php
                                        $depth = $this->getOfficeDepth($office);
                                    @endphp
                                    @for ($i = 0; $i < $depth; $i++)
                                        <span class="text-neutral-400">└─</span>
                                    @endfor
                                    <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ $office->name }}</span>
                                </div>
                                @if($office->notes)
                                    <div class="mt-1 text-sm text-neutral-500 dark:text-neutral-400" style="margin-left: {{ $depth * 2 }}rem;">
                                        {{ $office->notes }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-neutral-600 dark:text-neutral-400">
                                {{ $office->code ?? '-' }}
                            </td>
                            <td class="px-6 py-4">
                                @if($office->level)
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium
                                        @if($office->level->level == 1) bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200
                                        @elseif($office->level->level == 2) bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
                                        @elseif($office->level->level == 3) bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                        @else bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200
                                        @endif">
                                        {{ $office->level->name }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-neutral-600 dark:text-neutral-400">
                                {{ $office->parent?->name ?? '-' }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <flux:button wire:click="openEditModal({{ $office->id }})" variant="outline" size="sm">
                                        Edit
                                    </flux:button>
                                    <flux:button wire:click="openDeleteModal({{ $office->id }})" variant="danger" size="sm">
                                        Delete
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-neutral-600 dark:text-neutral-400">
                                No offices found. Create your first office to get started.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create Modal -->
    @if($showCreateModal)
        <flux:modal wire:model="showCreateModal" class="space-y-6">
            <div>
                <flux:heading size="lg">Create Office</flux:heading>
                <flux:text>Add a new office to the system.</flux:text>
            </div>

            <form wire:submit="create" class="space-y-4">
                <flux:field>
                    <flux:label>Name *</flux:label>
                    <flux:input wire:model="name" placeholder="Enter office name" />
                    @error('name') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                <flux:field>
                    <flux:label>Code</flux:label>
                    <flux:input wire:model="code" placeholder="Enter office code (optional)" />
                    @error('code') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                <flux:field>
                    <flux:label>Level *</flux:label>
                    <flux:select wire:model.live="level_id">
                        <option value="">Select level...</option>
                        @foreach($officeLevels as $level)
                            <option value="{{ $level->id }}">{{ $level->name }} (Level {{ $level->level }})</option>
                        @endforeach
                    </flux:select>
                    @error('level_id') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                @if($availableParents->count() > 0)
                    <flux:field>
                        <flux:label>Parent Office</flux:label>
                        <flux:select wire:model="parent_id">
                            <option value="">None (Root level)</option>
                            @foreach($availableParents as $parent)
                                <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                            @endforeach
                        </flux:select>
                        @error('parent_id') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model="notes" placeholder="Enter notes (optional)" rows="3" />
                    @error('notes') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="closeModals" variant="outline">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary">
                        Create Office
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <!-- Edit Modal -->
    @if($showEditModal)
        <flux:modal wire:model="showEditModal" class="space-y-6">
            <div>
                <flux:heading size="lg">Edit Office</flux:heading>
                <flux:text>Update office information.</flux:text>
            </div>

            <form wire:submit="update" class="space-y-4">
                <flux:field>
                    <flux:label>Name *</flux:label>
                    <flux:input wire:model="name" placeholder="Enter office name" />
                    @error('name') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                <flux:field>
                    <flux:label>Code</flux:label>
                    <flux:input wire:model="code" placeholder="Enter office code (optional)" />
                    @error('code') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                <flux:field>
                    <flux:label>Level *</flux:label>
                    <flux:select wire:model.live="level_id">
                        <option value="">Select level...</option>
                        @foreach($officeLevels as $level)
                            <option value="{{ $level->id }}">{{ $level->name }} (Level {{ $level->level }})</option>
                        @endforeach
                    </flux:select>
                    @error('level_id') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                @if($availableParents->count() > 0)
                    <flux:field>
                        <flux:label>Parent Office</flux:label>
                        <flux:select wire:model="parent_id">
                            <option value="">None (Root level)</option>
                            @foreach($availableParents as $parent)
                                <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                            @endforeach
                        </flux:select>
                        @error('parent_id') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model="notes" placeholder="Enter notes (optional)" rows="3" />
                    @error('notes') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="closeModals" variant="outline">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary">
                        Update Office
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <!-- Delete Confirmation Modal -->
    @if($showDeleteModal)
        <flux:modal wire:model="showDeleteModal" class="space-y-6">
            <div>
                <flux:heading size="lg">Delete Office</flux:heading>
                <flux:text>Are you sure you want to delete this office? This action cannot be undone.</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="closeModals" variant="outline">
                    Cancel
                </flux:button>
                <flux:button type="button" wire:click="delete" variant="danger">
                    Delete Office
                </flux:button>
            </div>
        </flux:modal>
    @endif
</div>

<?php

use App\Models\Role;
use App\Models\OfficeLevel;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public ?int $officeLevelId = null;

    public array $permissions = [];

    public bool $isSystem = false;

    public bool $showModal = false;

    public function with(): array
    {
        $roles = Role::query()
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%"))
            ->with('users')
            ->orderBy('name')
            ->get();

        $officeLevels = OfficeLevel::orderBy('level')->get();

        return [
            'roles' => $roles,
            'officeLevels' => $officeLevels,
            'availablePermissions' => config('permissions.all'),
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'description', 'officeLevelId', 'permissions', 'isSystem']);
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $role = Role::findOrFail($id);
        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = $role->description ?? '';
        $this->officeLevelId = $role->office_level_id;
        $this->permissions = $role->permissions ?? [];
        $this->isSystem = $role->is_system ?? false;
        $this->showModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', $this->editingId ? 'unique:roles,name,'.$this->editingId : 'unique:roles,name'],
            'description' => ['nullable', 'string'],
            'officeLevelId' => ['nullable', 'integer', 'exists:office_levels,id'],
            'permissions' => ['nullable', 'array'],
            'isSystem' => ['boolean'],
        ]);

        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'office_level_id' => $validated['officeLevelId'] ?: null,
            'permissions' => $validated['permissions'] ?: [],
            'is_system' => $validated['isSystem'],
        ];

        if ($this->editingId) {
            Role::findOrFail($this->editingId)->update($data);
        } else {
            Role::create($data);
        }

        $this->reset(['showModal', 'editingId', 'name', 'description', 'officeLevelId', 'permissions', 'isSystem']);
    }

    public function delete(int $id): void
    {
        $role = Role::findOrFail($id);

        if ($role->is_system) {
            $this->addError('delete', 'Cannot delete system roles.');

            return;
        }

        $role->delete();
    }

    public function cancelEdit(): void
    {
        $this->reset(['showModal', 'editingId', 'name', 'description', 'officeLevelId', 'permissions', 'isSystem']);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Roles</flux:heading>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                Manage user roles and permissions
            </p>
        </div>
        <flux:button wire:click="create" variant="primary">
            <flux:icon.plus class="mr-2 h-4 w-4" />
            Create Role
        </flux:button>
    </div>

    <div class="w-full max-w-md">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search roles..."
            type="search"
        />
    </div>

    @error('delete')
        <flux:callout variant="danger">
            {{ $message }}
        </flux:callout>
    @enderror

    <div class="overflow-x-auto rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Name</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Description</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Permissions</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Users</th>
                    <th class="px-4 py-3 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($roles as $role)
                    <tr wire:key="role-{{ $role->id }}"
                        class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ $role->name }}</span>
                                @if($role->is_system)
                                    <flux:badge color="purple" size="sm">System</flux:badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                            {{ $role->description ?? '-' }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="flex flex-wrap gap-1">
                                @if(is_array($role->permissions) && count($role->permissions) > 0)
                                    @if(in_array('*', $role->permissions))
                                        <flux:badge size="sm" color="purple">All Permissions</flux:badge>
                                    @else
                                        @foreach(array_slice($role->permissions, 0, 3) as $permission)
                                            <flux:badge size="sm">{{ $permission }}</flux:badge>
                                        @endforeach
                                        @if(count($role->permissions) > 3)
                                            <flux:badge size="sm" color="zinc">+{{ count($role->permissions) - 3 }}</flux:badge>
                                        @endif
                                    @endif
                                @else
                                    <span class="text-neutral-400">No permissions</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                            {{ $role->users->count() }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                <flux:button wire:click="edit({{ $role->id }})" size="sm" variant="ghost">
                                    Edit
                                </flux:button>
                                @if(!$role->is_system)
                                    <flux:button
                                        wire:click="delete({{ $role->id }})"
                                        wire:confirm="Are you sure you want to delete this role?"
                                        size="sm"
                                        variant="ghost"
                                        class="text-red-600 hover:text-red-700"
                                    >
                                        Delete
                                    </flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-500">
                            No roles found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal wire:model="showModal" class="min-w-[700px]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Role' : 'Create Role' }}</flux:heading>

            <flux:input
                wire:model="name"
                label="Name"
                type="text"
                required
                autofocus
                placeholder="e.g., Project Manager"
            />

            <flux:textarea
                wire:model="description"
                label="Description"
                rows="2"
                placeholder="Brief description of this role"
            />

            <flux:select wire:model="officeLevelId" label="Office Level (Optional)">
                <flux:select.option value="">Not Restricted</flux:select.option>
                @foreach($officeLevels as $level)
                    <flux:select.option value="{{ $level->id }}">
                        {{ $level->name }} (Level {{ $level->level }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div>
                <label class="mb-2 block text-sm font-medium text-neutral-900 dark:text-neutral-100">
                    Permissions
                </label>
                <div class="space-y-2 rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    @foreach($availablePermissions as $permission)
                        <label class="flex items-center gap-3">
                            <input
                                type="checkbox"
                                wire:model="permissions"
                                value="{{ $permission }}"
                                class="rounded border-neutral-300 dark:border-neutral-600"
                            />
                            <span class="text-sm text-neutral-700 dark:text-neutral-300">
                                {{ ucwords(str_replace('_', ' ', $permission)) }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <flux:checkbox wire:model="isSystem" label="System Role (Cannot be deleted)" />

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

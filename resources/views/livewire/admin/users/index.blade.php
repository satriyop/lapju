<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public string $search = '';

    public string $filter = 'all'; // all, pending, approved, admin

    public ?int $editingUserId = null;

    public string $editName = '';

    public string $editEmail = '';

    public string $editNrp = '';

    public bool $editIsAdmin = false;

    public bool $showEditModal = false;

    public bool $showProjectModal = false;

    public ?int $projectUserId = null;

    public array $selectedProjects = [];

    public function approveUser(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->update([
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ]);
    }

    public function rejectUser(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->delete();
    }

    public function toggleAdmin(int $userId): void
    {
        $user = User::findOrFail($userId);
        if ($user->id !== Auth::id()) {
            $user->update(['is_admin' => ! $user->is_admin]);
        }
    }

    public function editUser(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->editingUserId = $user->id;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->editNrp = $user->nrp ?? '';
        $this->editIsAdmin = $user->is_admin;
        $this->showEditModal = true;
    }

    public function saveUser(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255',
            'editEmail' => 'required|email|unique:users,email,'.$this->editingUserId,
            'editNrp' => 'nullable|string|max:50|unique:users,nrp,'.$this->editingUserId,
        ]);

        $user = User::findOrFail($this->editingUserId);
        $user->update([
            'name' => $this->editName,
            'email' => $this->editEmail,
            'nrp' => $this->editNrp ?: null,
            'is_admin' => $this->editIsAdmin,
        ]);

        $this->showEditModal = false;
        $this->reset(['editingUserId', 'editName', 'editEmail', 'editNrp', 'editIsAdmin']);
    }

    public function openProjectModal(int $userId): void
    {
        $user = User::with('projects')->findOrFail($userId);
        $this->projectUserId = $userId;
        $this->selectedProjects = $user->projects->pluck('id')->toArray();
        $this->showProjectModal = true;
    }

    public function saveProjectAssignments(): void
    {
        $user = User::findOrFail($this->projectUserId);
        $user->projects()->sync($this->selectedProjects);
        $this->showProjectModal = false;
        $this->reset(['projectUserId', 'selectedProjects']);
    }

    public function deleteUser(int $userId): void
    {
        $user = User::findOrFail($userId);
        if ($user->id !== Auth::id()) {
            $user->delete();
        }
    }

    public function with(): array
    {
        $query = User::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
                ->orWhere('nrp', 'like', "%{$this->search}%"))
            ->when($this->filter === 'pending', fn ($q) => $q->where('is_approved', false))
            ->when($this->filter === 'approved', fn ($q) => $q->where('is_approved', true))
            ->when($this->filter === 'admin', fn ($q) => $q->where('is_admin', true))
            ->with('approvedBy', 'projects')
            ->orderByDesc('created_at');

        $pendingCount = User::where('is_approved', false)->count();
        $projects = Project::with('location', 'customer')->orderBy('name')->get();

        return [
            'users' => $query->get(),
            'pendingCount' => $pendingCount,
            'projects' => $projects,
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">User Management</flux:heading>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                Manage user registrations, approvals, and project assignments
            </p>
        </div>
        @if($pendingCount > 0)
            <flux:badge color="amber" size="lg">
                {{ $pendingCount }} Pending Approval
            </flux:badge>
        @endif
    </div>

    <!-- Filters -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
        <div class="flex-1">
            <flux:input
                wire:model.live.debounce.300ms="search"
                type="search"
                placeholder="Search by name, email, or NRP..."
            />
        </div>
        <flux:select wire:model.live="filter">
            <option value="all">All Users</option>
            <option value="pending">Pending Approval</option>
            <option value="approved">Approved</option>
            <option value="admin">Admins</option>
        </flux:select>
    </div>

    <!-- Users Table -->
    <div class="overflow-x-auto rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">User</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">NRP</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Status</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Projects</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Registered</th>
                    <th class="px-4 py-3 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($users as $user)
                    <tr class="@if(!$user->is_approved) bg-amber-50 dark:bg-amber-900/10 @endif">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-neutral-200 text-sm font-medium dark:bg-neutral-700">
                                    {{ $user->initials() }}
                                </div>
                                <div>
                                    <div class="font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ $user->name }}
                                        @if($user->is_admin)
                                            <flux:badge color="purple" size="sm">Admin</flux:badge>
                                        @endif
                                    </div>
                                    <div class="text-sm text-neutral-600 dark:text-neutral-400">
                                        {{ $user->email }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                            {{ $user->nrp ?? '-' }}
                        </td>
                        <td class="px-4 py-3">
                            @if($user->is_approved)
                                <flux:badge color="green">Approved</flux:badge>
                                @if($user->approvedBy)
                                    <div class="mt-1 text-xs text-neutral-500">
                                        by {{ $user->approvedBy->name }}
                                    </div>
                                @endif
                            @else
                                <flux:badge color="amber">Pending</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if($user->projects->count() > 0)
                                <div class="flex flex-wrap gap-1">
                                    @foreach($user->projects->take(2) as $project)
                                        <flux:badge size="sm">{{ Str::limit($project->name, 15) }}</flux:badge>
                                    @endforeach
                                    @if($user->projects->count() > 2)
                                        <flux:badge size="sm" color="zinc">+{{ $user->projects->count() - 2 }}</flux:badge>
                                    @endif
                                </div>
                            @else
                                <span class="text-neutral-400">No projects</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                            {{ $user->created_at->format('M d, Y') }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if(!$user->is_approved)
                                    <flux:button wire:click="approveUser({{ $user->id }})" size="sm" variant="primary">
                                        Approve
                                    </flux:button>
                                    <flux:button wire:click="rejectUser({{ $user->id }})" size="sm" variant="danger">
                                        Reject
                                    </flux:button>
                                @else
                                    <flux:button wire:click="openProjectModal({{ $user->id }})" size="sm" variant="outline">
                                        Projects
                                    </flux:button>
                                    <flux:button wire:click="editUser({{ $user->id }})" size="sm" variant="outline">
                                        Edit
                                    </flux:button>
                                    @if($user->id !== Auth::id())
                                        <flux:button wire:click="deleteUser({{ $user->id }})" size="sm" variant="danger" wire:confirm="Are you sure you want to delete this user?">
                                            Delete
                                        </flux:button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-neutral-600 dark:text-neutral-400">
                            No users found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Edit User Modal -->
    <flux:modal wire:model="showEditModal" class="min-w-[500px]">
        <form wire:submit="saveUser" class="space-y-6">
            <flux:heading size="lg">Edit User</flux:heading>

            <flux:input wire:model="editName" label="Name" type="text" required />
            <flux:input wire:model="editEmail" label="Email" type="email" required />
            <flux:input wire:model="editNrp" label="NRP" type="text" />
            <flux:checkbox wire:model="editIsAdmin" label="Admin privileges" />

            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showEditModal', false)" variant="outline">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Save Changes
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <!-- Project Assignment Modal -->
    <flux:modal wire:model="showProjectModal" class="min-w-[600px]">
        <div class="space-y-6">
            <flux:heading size="lg">Assign Projects</flux:heading>

            <div class="max-h-96 space-y-2 overflow-y-auto">
                @foreach($projects as $project)
                    <label class="flex items-center gap-3 rounded-lg border border-neutral-200 p-3 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                        <input
                            type="checkbox"
                            wire:model="selectedProjects"
                            value="{{ $project->id }}"
                            class="rounded border-neutral-300 dark:border-neutral-600"
                        />
                        <div class="flex-1">
                            <div class="font-medium text-neutral-900 dark:text-neutral-100">
                                {{ $project->name }}
                            </div>
                            <div class="text-sm text-neutral-600 dark:text-neutral-400">
                                {{ $project->location->name }} ({{ $project->location->city_name }}) - {{ $project->customer->name }}
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showProjectModal', false)" variant="outline">
                    Cancel
                </flux:button>
                <flux:button wire:click="saveProjectAssignments" variant="primary">
                    Save Assignments
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>

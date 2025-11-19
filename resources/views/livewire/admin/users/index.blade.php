<?php

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Role;
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

    public ?int $editOfficeId = null;

    public bool $editIsAdmin = false;

    public bool $showEditModal = false;

    public bool $showProjectModal = false;

    public ?int $projectUserId = null;

    public array $selectedProjects = [];

    // Create user modal properties
    public bool $showCreateModal = false;

    public string $createName = '';

    public string $createEmail = '';

    public string $createNrp = '';

    public string $createPhone = '';

    public ?int $createRoleId = null;

    public ?int $createOfficeId = null;

    public string $createPassword = '';

    public string $createPasswordConfirmation = '';

    public bool $createIsAdmin = false;

    public bool $createIsApproved = true;

    public function mount(): void
    {
        // Check if user has permission to manage users
        if (! Auth::user()->hasPermission('manage_users')) {
            abort(403, 'Unauthorized access to user management.');
        }
    }

    public function updatedCreateRoleId(): void
    {
        // Reset office when role changes
        $this->createOfficeId = null;
    }

    public function openCreateModal(): void
    {
        $this->reset([
            'createName',
            'createEmail',
            'createNrp',
            'createPhone',
            'createRoleId',
            'createOfficeId',
            'createPassword',
            'createPasswordConfirmation',
            'createIsAdmin',
        ]);
        $this->createIsApproved = true;
        $this->showCreateModal = true;
    }

    public function createUser(): void
    {
        $this->validate([
            'createName' => ['required', 'string', 'max:255'],
            'createEmail' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'createNrp' => ['required', 'string', 'max:50', 'unique:users,nrp'],
            'createPhone' => ['required', 'string', 'max:20', 'regex:/^[0-9\+\-\s\(\)]+$/'],
            'createRoleId' => ['required', 'integer', 'exists:roles,id'],
            'createOfficeId' => ['required', 'integer', 'exists:offices,id'],
            'createPassword' => ['required', 'string', 'min:8', 'same:createPasswordConfirmation'],
        ]);

        // Verify the selected office matches the role's required level
        $role = Role::find($this->createRoleId);
        $office = Office::with('level')->find($this->createOfficeId);

        if ($role->office_level_id && $office->level->id !== $role->office_level_id) {
            $this->addError('createOfficeId', 'Invalid office selection for this role.');

            return;
        }

        $userData = [
            'name' => $this->createName,
            'email' => $this->createEmail,
            'nrp' => $this->createNrp,
            'phone' => $this->createPhone,
            'office_id' => $this->createOfficeId,
            'password' => bcrypt($this->createPassword),
            'is_admin' => $this->createIsAdmin,
            'is_approved' => $this->createIsApproved,
        ];

        if ($this->createIsApproved) {
            $userData['approved_at'] = now();
            $userData['approved_by'] = Auth::id();
        }

        $user = User::create($userData);

        // Assign the selected role to the user
        if ($this->createIsApproved) {
            $user->roles()->attach($this->createRoleId, [
                'assigned_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->showCreateModal = false;
        $this->reset([
            'createName',
            'createEmail',
            'createNrp',
            'createPhone',
            'createRoleId',
            'createOfficeId',
            'createPassword',
            'createPasswordConfirmation',
            'createIsAdmin',
            'createIsApproved',
        ]);
    }

    public function approveUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        // Check if current user can manage this user
        if (!$this->canManageUser($user)) {
            abort(403, 'You do not have permission to approve this user.');
        }

        $user->update([
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ]);

        // Automatically assign Reporter role to newly approved users
        $reporterRole = Role::firstOrCreate(['name' => 'Reporter']);
        if (! $user->hasRole($reporterRole)) {
            $user->roles()->attach($reporterRole->id, [
                'assigned_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Check if current user can manage the given user based on coverage.
     */
    private function canManageUser(User $targetUser): bool
    {
        $currentUser = Auth::user();

        // Admins can manage everyone
        if ($currentUser->isAdmin()) {
            return true;
        }

        // Must have office assigned
        if (!$currentUser->office_id || !$targetUser->office_id) {
            return false;
        }

        $currentOffice = Office::with('level')->find($currentUser->office_id);

        // If Manager at Kodim level, can only manage users in Koramil under their Kodim
        if ($currentOffice && $currentOffice->level->level === 3) {
            $targetOffice = Office::find($targetUser->office_id);
            return $targetOffice && $targetOffice->parent_id === $currentUser->office_id;
        }

        return false;
    }

    public function rejectUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        // Check if current user can manage this user
        if (!$this->canManageUser($user)) {
            abort(403, 'You do not have permission to reject this user.');
        }

        $user->delete();
    }

    public function toggleAdmin(int $userId): void
    {
        // Only admins can toggle admin status
        if (!Auth::user()->isAdmin()) {
            abort(403, 'Only admins can change admin status.');
        }

        $user = User::findOrFail($userId);
        if ($user->id !== Auth::id()) {
            $user->update(['is_admin' => ! $user->is_admin]);
        }
    }

    public function editUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        // Check if current user can manage this user
        if (!$this->canManageUser($user)) {
            abort(403, 'You do not have permission to edit this user.');
        }

        $this->editingUserId = $user->id;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->editNrp = $user->nrp ?? '';
        $this->editOfficeId = $user->office_id;
        $this->editIsAdmin = $user->is_admin;
        $this->showEditModal = true;
    }

    public function saveUser(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255',
            'editEmail' => 'required|email|unique:users,email,'.$this->editingUserId,
            'editNrp' => 'nullable|string|max:50|unique:users,nrp,'.$this->editingUserId,
            'editOfficeId' => 'nullable|integer|exists:offices,id',
        ]);

        $user = User::findOrFail($this->editingUserId);

        // Check if current user can manage this user
        if (!$this->canManageUser($user)) {
            abort(403, 'You do not have permission to edit this user.');
        }

        $user->update([
            'name' => $this->editName,
            'email' => $this->editEmail,
            'nrp' => $this->editNrp ?: null,
            'office_id' => $this->editOfficeId,
            'is_admin' => $this->editIsAdmin,
        ]);

        $this->showEditModal = false;
        $this->reset(['editingUserId', 'editName', 'editEmail', 'editNrp', 'editOfficeId', 'editIsAdmin']);
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

        // Check if current user can manage this user
        if (!$this->canManageUser($user)) {
            abort(403, 'You do not have permission to delete this user.');
        }

        if ($user->id !== Auth::id()) {
            $user->delete();
        }
    }

    public function with(): array
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->isAdmin();

        $query = User::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
                ->orWhere('nrp', 'like', "%{$this->search}%"))
            ->when($this->filter === 'pending', fn ($q) => $q->where('is_approved', false))
            ->when($this->filter === 'approved', fn ($q) => $q->where('is_approved', true))
            ->when($this->filter === 'admin', fn ($q) => $q->where('is_admin', true))
            ->with('approvedBy', 'projects', 'office.parent', 'office.level', 'roles');

        // Managers can only see users under their Kodim coverage
        if (!$isAdmin && $currentUser->office_id) {
            $currentOffice = Office::with('level')->find($currentUser->office_id);

            // If user is at Kodim level (Manager), filter users to only show Koramil under their Kodim
            if ($currentOffice && $currentOffice->level->level === 3) {
                $query->whereHas('office', function ($q) use ($currentUser) {
                    $q->where('parent_id', $currentUser->office_id);
                });
            }
        }

        $query->orderByDesc('created_at');

        // Count pending users with coverage filter
        $pendingCountQuery = User::where('is_approved', false);
        if (!$isAdmin && $currentUser->office_id) {
            $currentOffice = Office::with('level')->find($currentUser->office_id);
            if ($currentOffice && $currentOffice->level->level === 3) {
                $pendingCountQuery->whereHas('office', function ($q) use ($currentUser) {
                    $q->where('parent_id', $currentUser->office_id);
                });
            }
        }
        $pendingCount = $pendingCountQuery->count();
        $projects = Project::with('location', 'partner')->orderBy('name')->get();

        // Get all offices grouped by level for the edit modal
        $offices = Office::with('parent', 'level')
            ->orderBy('level_id')
            ->orderBy('name')
            ->get()
            ->groupBy(fn ($office) => $office->level->name);

        // Get all roles for selection
        $roles = Role::orderBy('name')->get();

        // Get offices based on selected role's office level
        $availableOffices = collect();
        if ($this->createRoleId) {
            $selectedRole = Role::find($this->createRoleId);
            if ($selectedRole && $selectedRole->office_level_id) {
                $officesQuery = Office::where('level_id', $selectedRole->office_level_id);

                // Managers can only assign users to Koramils under their Kodim
                if (!$isAdmin && $currentUser->office_id) {
                    $currentOffice = Office::with('level')->find($currentUser->office_id);
                    if ($currentOffice && $currentOffice->level->level === 3) {
                        // If Manager, only show Koramils under their Kodim
                        $officesQuery->where('parent_id', $currentUser->office_id);
                    }
                }

                $availableOffices = $officesQuery->orderBy('name')->get();
            } else {
                // If role doesn't have office_level_id restriction, show all offices
                $officesQuery = Office::with('level')->orderBy('level_id')->orderBy('name');

                // Managers can only assign to offices under their coverage
                if (!$isAdmin && $currentUser->office_id) {
                    $currentOffice = Office::with('level')->find($currentUser->office_id);
                    if ($currentOffice && $currentOffice->level->level === 3) {
                        $officesQuery->where('parent_id', $currentUser->office_id);
                    }
                }

                $availableOffices = $officesQuery->get();
            }
        }

        return [
            'users' => $query->get(),
            'pendingCount' => $pendingCount,
            'projects' => $projects,
            'offices' => $offices,
            'roles' => $roles,
            'availableOffices' => $availableOffices,
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
        <div class="flex items-center gap-3">
            @if($pendingCount > 0)
                <flux:badge color="amber" size="lg">
                    {{ $pendingCount }} Pending Approval
                </flux:badge>
            @endif
            <flux:button wire:click="openCreateModal" variant="primary">
                <flux:icon.user-plus class="mr-2 h-4 w-4" />
                Create User
            </flux:button>
        </div>
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
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Role</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Office</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Status</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Projects</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">Registered</th>
                    <th class="px-4 py-3 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($users as $user)
                    <tr wire:key="user-{{ $user->id }}" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 text-sm font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                    {{ $user->initials() }}
                                </div>
                                <div>
                                    <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ $user->name }}</div>
                                    <div class="text-sm text-neutral-500 dark:text-neutral-400">{{ $user->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                            {{ $user->nrp ?? '-' }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if($user->roles->count() > 0)
                                <div class="flex flex-wrap gap-1">
                                    @foreach($user->roles as $role)
                                        <flux:badge size="sm">{{ $role->name }}</flux:badge>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-neutral-400">No role</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if($user->office)
                                <div class="text-neutral-900 dark:text-neutral-100">{{ $user->office->name }}</div>
                                @if($user->office->parent)
                                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $user->office->parent->name }}</div>
                                @endif
                            @else
                                <span class="text-neutral-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="flex flex-col gap-1">
                                @if($user->is_admin)
                                    <flux:badge color="purple" size="sm">Admin</flux:badge>
                                @endif
                                @if($user->is_approved)
                                    <flux:badge color="green" size="sm">Approved</flux:badge>
                                @else
                                    <flux:badge color="amber" size="sm">Pending</flux:badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                            {{ $user->projects->count() }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                            {{ $user->created_at->format('M j, Y') }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                @if(!$user->is_approved)
                                    <flux:button
                                        wire:click="approveUser({{ $user->id }})"
                                        wire:confirm="Approve this user?"
                                        size="sm"
                                        variant="primary"
                                    >
                                        Approve
                                    </flux:button>
                                    <flux:button
                                        wire:click="rejectUser({{ $user->id }})"
                                        wire:confirm="Reject and delete this user?"
                                        size="sm"
                                        variant="danger"
                                    >
                                        Reject
                                    </flux:button>
                                @else
                                    <flux:button wire:click="editUser({{ $user->id }})" size="sm" variant="ghost">
                                        Edit
                                    </flux:button>
                                    <flux:button wire:click="openProjectModal({{ $user->id }})" size="sm" variant="ghost">
                                        Projects
                                    </flux:button>
                                    @if($user->id !== Auth::id())
                                        <flux:button
                                            wire:click="deleteUser({{ $user->id }})"
                                            wire:confirm="Delete this user?"
                                            size="sm"
                                            variant="ghost"
                                            class="text-red-600 hover:text-red-700"
                                        >
                                            Delete
                                        </flux:button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-sm text-neutral-500">
                            No users found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Edit User Modal -->
    <flux:modal wire:model="showEditModal" class="min-w-[500px]">
        <form wire:submit="saveUser" class="space-y-4">
            <flux:heading size="lg">Edit User</flux:heading>

            <flux:input
                wire:model="editName"
                label="Full Name"
                type="text"
                required
            />

            <flux:input
                wire:model="editEmail"
                label="Email Address"
                type="email"
                required
            />

            <flux:input
                wire:model="editNrp"
                label="NRP (Employee ID)"
                type="text"
            />

            <div>
                <label class="mb-2 block text-sm font-medium text-neutral-900 dark:text-neutral-100">Office</label>
                <flux:select wire:model="editOfficeId">
                    <option value="">No office</option>
                    @foreach($offices as $levelName => $levelOffices)
                        <optgroup label="{{ $levelName }}">
                            @foreach($levelOffices as $office)
                                <option value="{{ $office->id }}">
                                    {{ $office->name }}
                                    @if($office->parent)
                                        ({{ $office->parent->name }})
                                    @endif
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </flux:select>
            </div>

            <flux:checkbox wire:model="editIsAdmin" label="Grant admin privileges" />

            <div class="flex justify-end gap-3 pt-2">
                <flux:button wire:click="$set('showEditModal', false)" variant="outline" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Save Changes
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <!-- Project Assignment Modal -->
    <flux:modal wire:model="showProjectModal" class="min-w-[500px]">
        <div class="space-y-4">
            <flux:heading size="lg">Assign Projects</flux:heading>

            <div class="max-h-96 space-y-2 overflow-y-auto">
                @foreach($projects as $project)
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-neutral-200 p-3 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                        <input
                            type="checkbox"
                            wire:model="selectedProjects"
                            value="{{ $project->id }}"
                            class="rounded border-neutral-300 text-blue-600 focus:ring-blue-500 dark:border-neutral-600"
                        />
                        <div class="flex-1">
                            <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ $project->name }}</div>
                            <div class="text-sm text-neutral-500 dark:text-neutral-400">
                                {{ $project->location?->village_name ?? '-' }} • {{ $project->partner?->name ?? '-' }}
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <flux:button wire:click="$set('showProjectModal', false)" variant="outline">
                    Cancel
                </flux:button>
                <flux:button wire:click="saveProjectAssignments" variant="primary">
                    Save Assignments
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Create User Modal -->
    <flux:modal wire:model="showCreateModal" class="min-w-[500px]">
        <form wire:submit="createUser" class="space-y-4">
            <flux:heading size="lg">Create New User</flux:heading>

            <flux:input
                wire:model="createName"
                label="Full Name"
                type="text"
                required
                placeholder="Enter full name"
            />
            @error('createName')
                <div class="-mt-2 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <flux:input
                wire:model="createEmail"
                label="Email Address"
                type="email"
                required
                placeholder="email@example.com"
            />
            @error('createEmail')
                <div class="-mt-2 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <flux:input
                wire:model="createNrp"
                label="NRP (Employee ID)"
                type="text"
                required
                placeholder="Enter NRP"
            />
            @error('createNrp')
                <div class="-mt-2 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <flux:input
                wire:model="createPhone"
                label="Mobile Phone Number"
                type="tel"
                required
                placeholder="08123456789"
            />
            @error('createPhone')
                <div class="-mt-2 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <flux:select
                wire:model.live="createRoleId"
                label="Role"
                required
            >
                <flux:select.option value="">Select Role</flux:select.option>
                @foreach ($roles as $role)
                    <flux:select.option value="{{ $role->id }}">
                        {{ $role->name }}
                        @if($role->office_level_id)
                            - {{ \App\Models\OfficeLevel::find($role->office_level_id)->name ?? '' }}
                        @endif
                    </flux:select.option>
                @endforeach
            </flux:select>
            @error('createRoleId')
                <div class="-mt-2 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <flux:select
                wire:model="createOfficeId"
                label="Office"
                required
                :disabled="!$createRoleId"
            >
                <flux:select.option value="">
                    {{ $createRoleId ? 'Select Office' : 'Select Role first' }}
                </flux:select.option>
                @foreach ($availableOffices as $office)
                    <flux:select.option value="{{ $office->id }}">
                        {{ $office->name }}
                        @if(isset($office->parent))
                            ({{ $office->parent->name }})
                        @endif
                    </flux:select.option>
                @endforeach
            </flux:select>
            @error('createOfficeId')
                <div class="-mt-2 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <flux:input
                wire:model="createPassword"
                label="Password"
                type="password"
                required
                placeholder="Minimum 8 characters"
                viewable
            />
            @error('createPassword')
                <div class="-mt-2 text-sm text-red-600">{{ $message }}</div>
            @enderror

            <flux:input
                wire:model="createPasswordConfirmation"
                label="Confirm Password"
                type="password"
                required
                placeholder="Confirm password"
                viewable
            />

            <div class="space-y-3 rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:checkbox wire:model="createIsApproved" label="Pre-approve this user" />
                <flux:checkbox wire:model="createIsAdmin" label="Grant admin privileges" />
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <flux:button wire:click="$set('showCreateModal', false)" variant="outline" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="createUser">Create User</span>
                    <span wire:loading wire:target="createUser">Creating...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>

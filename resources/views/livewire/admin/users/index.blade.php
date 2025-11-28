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

    public string $editPhone = '';

    public string $editNrp = '';

    public ?int $editOfficeId = null;

    public bool $editIsAdmin = false;

    public array $editRoleIds = [];

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

    // User deletion with reassignment properties
    public bool $showReassignModal = false;

    public ?int $deletingUserId = null;

    public array $projectReassignments = [];

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

    public function updatedSearch(): void
    {
        // Expand all offices when searching to make results visible
        $this->dispatch('expand-all-offices');
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
        if (! $this->canManageUser($user)) {
            abort(403, 'You do not have permission to approve this user.');
        }

        $user->update([
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ]);

        // Automatically assign role based on user's office level
        if ($user->office_id) {
            $userOffice = Office::with('level')->find($user->office_id);

            if ($userOffice && $userOffice->level) {
                $roleToAssign = null;

                // Assign role based on office level
                if ($userOffice->level->level === 4) {
                    // Koramil level → Reporter role
                    $roleToAssign = Role::where('name', 'Reporter')->first();
                } elseif ($userOffice->level->level === 3) {
                    // Kodim level → Kodim Admin role
                    $roleToAssign = Role::where('name', 'Kodim Admin')->first();
                }

                // Assign the role if found and not already assigned
                if ($roleToAssign && ! $user->hasRole($roleToAssign)) {
                    $user->roles()->attach($roleToAssign->id, [
                        'assigned_by' => Auth::id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
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
        if (! $currentUser->office_id || ! $targetUser->office_id) {
            return false;
        }

        $currentOffice = Office::with('level')->find($currentUser->office_id);

        // If Manager at Kodim level, can only manage users in Koramil under their Kodim
        if ($currentOffice && $currentOffice->level->level === 3) {
            $targetOffice = Office::find($targetUser->office_id);

            return $targetOffice && $targetOffice->parent_id === $currentUser->office_id;
        }

        // If Koramil Admin at Koramil level, can only manage users in same Koramil
        if ($currentOffice && $currentOffice->level->level === 4) {
            return $targetUser->office_id === $currentUser->office_id;
        }

        return false;
    }

    public function rejectUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        // Check if current user can manage this user
        if (! $this->canManageUser($user)) {
            abort(403, 'You do not have permission to reject this user.');
        }

        $user->delete();
    }

    public function toggleAdmin(int $userId): void
    {
        // Only admins can toggle admin status
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Only admins can change admin status.');
        }

        $user = User::findOrFail($userId);
        if ($user->id !== Auth::id()) {
            $user->update(['is_admin' => ! $user->is_admin]);
        }
    }

    public function editUser(int $userId): void
    {
        $user = User::with('roles')->findOrFail($userId);

        // Check if current user can manage this user
        if (! $this->canManageUser($user)) {
            abort(403, 'You do not have permission to edit this user.');
        }

        $this->editingUserId = $user->id;
        $this->editName = $user->name;
        $this->editPhone = $user->phone ?? '';
        $this->editNrp = $user->nrp ?? '';
        $this->editOfficeId = $user->office_id;
        $this->editIsAdmin = $user->is_admin;
        $this->editRoleIds = $user->roles->pluck('id')->toArray();
        $this->showEditModal = true;
    }

    public function saveUser(): void
    {
        $this->validate([
            'editName' => 'required|string|max:255',
            'editPhone' => 'required|string|min:10|max:13|regex:/^08[0-9]{8,11}$/|unique:users,phone,'.$this->editingUserId,
            'editNrp' => 'nullable|string|max:50|unique:users,nrp,'.$this->editingUserId,
            'editOfficeId' => 'nullable|integer|exists:offices,id',
            'editRoleIds' => 'nullable|array',
            'editRoleIds.*' => 'integer|exists:roles,id',
        ]);

        $user = User::with('office.level')->findOrFail($this->editingUserId);

        // Check if current user can manage this user
        if (! $this->canManageUser($user)) {
            abort(403, 'You do not have permission to edit this user.');
        }

        // Validate roles against office level (check against NEW office if being changed)
        if (! empty($this->editRoleIds)) {
            // Use the NEW office if being changed, otherwise use current office
            $officeIdToCheck = $this->editOfficeId ?? $user->office_id;

            if ($officeIdToCheck) {
                $office = Office::with('level')->find($officeIdToCheck);
                $userOfficeLevel = $office?->level?->level;

                if ($userOfficeLevel) {
                    foreach ($this->editRoleIds as $roleId) {
                        $role = Role::find($roleId);
                        if ($role && $role->office_level_id) {
                            // Get the office level number from office_level_id
                            $requiredLevel = OfficeLevel::find($role->office_level_id)?->level;

                            if ($requiredLevel && $requiredLevel !== $userOfficeLevel) {
                                $this->addError('editRoleIds', "Role '{$role->name}' requires user to be at a different office level.");

                                return;
                            }
                        }
                    }
                }
            }
        }

        $user->update([
            'name' => $this->editName,
            'phone' => $this->editPhone,
            'nrp' => $this->editNrp ?: null,
            'office_id' => $this->editOfficeId,
            'is_admin' => $this->editIsAdmin,
        ]);

        // Sync roles with assigned_by tracking
        $syncData = [];
        foreach ($this->editRoleIds as $roleId) {
            $syncData[$roleId] = ['assigned_by' => Auth::id()];
        }
        $user->roles()->sync($syncData);

        $this->showEditModal = false;
        $this->reset(['editingUserId', 'editName', 'editPhone', 'editNrp', 'editOfficeId', 'editIsAdmin', 'editRoleIds']);
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

    private function getAvailableProjectsForAssignment()
    {
        $currentUser = auth()->user();
        $query = Project::with(['location', 'partner', 'office']);

        // Admins see all projects
        if ($currentUser->isAdmin() || $currentUser->hasPermission('*')) {
            return $query->orderBy('name')->get();
        }

        // Get current user's office for coverage filtering
        if (! $currentUser->office_id) {
            return collect(); // No office = no projects
        }

        $currentOffice = Office::with('level')->find($currentUser->office_id);

        if (! $currentOffice) {
            return collect();
        }

        // Kodim Admins: Only projects in Koramils under their Kodim
        if ($currentOffice->level->level === 3) {
            $query->whereHas('office', function ($q) use ($currentUser) {
                $q->where('parent_id', $currentUser->office_id);
            });
        }
        // Koramil Admins: Only projects in their exact Koramil
        elseif ($currentOffice->level->level === 4) {
            $query->where('office_id', $currentUser->office_id);
        }

        return $query->orderBy('name')->get();
    }

    public function deleteUser(int $userId): void
    {
        $user = User::with('projects')->findOrFail($userId);

        // Check if current user can manage this user
        if (! $this->canManageUser($user)) {
            abort(403, 'You do not have permission to delete this user.');
        }

        // Prevent deleting self
        if ($user->id === Auth::id()) {
            return;
        }

        // Check if user has assigned projects
        if ($user->projects->count() > 0) {
            // Show reassignment modal instead of deleting directly
            $this->prepareUserDeletion($userId);
        } else {
            // No projects, safe to delete directly
            $user->delete();
        }
    }

    public function prepareUserDeletion(int $userId): void
    {
        $user = User::with('projects')->findOrFail($userId);

        // Check if current user can manage this user
        if (! $this->canManageUser($user)) {
            abort(403, 'You do not have permission to delete this user.');
        }

        $this->deletingUserId = $userId;

        // Initialize reassignment array with null for each project
        $this->projectReassignments = [];
        foreach ($user->projects as $project) {
            $this->projectReassignments[$project->id] = null;
        }

        $this->showReassignModal = true;
    }

    public function reassignAndDeleteUser(): void
    {
        $user = User::with('projects')->findOrFail($this->deletingUserId);

        // Check if current user can manage this user
        if (! $this->canManageUser($user)) {
            abort(403, 'You do not have permission to delete this user.');
        }

        // Validate that all projects have been reassigned
        foreach ($user->projects as $project) {
            if (empty($this->projectReassignments[$project->id])) {
                $this->addError('projectReassignments', 'Please assign a replacement user for all projects.');

                return;
            }
        }

        // Perform reassignments
        foreach ($this->projectReassignments as $projectId => $newUserId) {
            $project = Project::findOrFail($projectId);

            // Get current project users
            $currentUserIds = $project->users()->pluck('users.id')->toArray();

            // Remove the user being deleted
            $currentUserIds = array_diff($currentUserIds, [$user->id]);

            // Add the new user if not already assigned
            if (! in_array($newUserId, $currentUserIds)) {
                $currentUserIds[] = $newUserId;
            }

            // Sync the updated user list
            $project->users()->sync($currentUserIds);
        }

        // Now safe to delete the user
        $user->delete();

        // Reset state
        $this->showReassignModal = false;
        $this->reset(['deletingUserId', 'projectReassignments']);
    }

    private function getEligibleReplacementUsers()
    {
        if (! $this->deletingUserId) {
            return collect();
        }

        $userBeingDeleted = User::findOrFail($this->deletingUserId);
        $currentUser = Auth::user();

        // Build query for eligible users
        $query = User::query()
            ->where('id', '!=', $this->deletingUserId) // Exclude user being deleted
            ->where('is_approved', true) // Only approved users
            ->with('office.level', 'roles');

        // Apply coverage filtering based on current user's permissions
        if (! $currentUser->isAdmin() && $currentUser->office_id) {
            $currentOffice = Office::with('level')->find($currentUser->office_id);

            if ($currentOffice) {
                // Kodim Admins can only assign to users in Koramils under their Kodim
                if ($currentOffice->level->level === 3) {
                    $query->whereHas('office', function ($q) use ($currentUser) {
                        $q->where('parent_id', $currentUser->office_id);
                    });
                }
                // Koramil Admins can only assign to users in their exact Koramil
                elseif ($currentOffice->level->level === 4) {
                    $query->where('office_id', $currentUser->office_id);
                }
            }
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Build hierarchical office structure with users, hiding empty offices.
     */
    private function buildOfficeHierarchy($users): array
    {
        // Get all offices that have users
        $officeIds = $users->pluck('office_id')->filter()->unique();

        if ($officeIds->isEmpty()) {
            return [];
        }

        // Get offices that have users
        $userOffices = Office::with(['level', 'parent'])
            ->whereIn('id', $officeIds->toArray())
            ->get();

        // Collect all office IDs we need (including ancestors)
        $allOfficeIds = collect($officeIds);

        // For each office with users, add its ancestors
        foreach ($userOffices as $office) {
            // Find ancestors using nested set: offices where _lft < current._lft AND _rgt > current._rgt
            $ancestorIds = Office::where('_lft', '<', $office->_lft)
                ->where('_rgt', '>', $office->_rgt)
                ->pluck('id');

            $allOfficeIds = $allOfficeIds->merge($ancestorIds);
        }

        // Get all offices (users' offices + their ancestors)
        $offices = Office::with(['level', 'parent'])
            ->whereIn('id', $allOfficeIds->unique()->toArray())
            ->orderBy('_lft') // Use nested set ordering for hierarchy
            ->get();

        // Group users by office
        $usersByOffice = $users->groupBy('office_id');

        // Build hierarchy tree recursively
        return $this->buildOfficeTree($offices, $usersByOffice, null);
    }

    /**
     * Recursively build office tree structure.
     */
    private function buildOfficeTree($offices, $usersByOffice, $parentId, $level = 0): array
    {
        $tree = [];

        foreach ($offices as $office) {
            if ($office->parent_id == $parentId) {
                $officeUsers = $usersByOffice->get($office->id, collect());
                $children = $this->buildOfficeTree($offices, $usersByOffice, $office->id, $level + 1);

                // Only include office if it has users OR has children with users
                if ($officeUsers->isNotEmpty() || ! empty($children)) {
                    $tree[] = [
                        'office' => $office,
                        'users' => $officeUsers,
                        'children' => $children,
                        'level' => $level,
                        'user_count' => $officeUsers->count() + collect($children)->sum(fn ($child) => $child['user_count']),
                        'pending_count' => $officeUsers->where('is_approved', false)->count() +
                                         collect($children)->sum(fn ($child) => $child['pending_count']),
                    ];
                }
            }
        }

        return $tree;
    }

    /**
     * Get list of office IDs that should be expanded by default.
     */
    private function getDefaultExpandedOffices(): array
    {
        $currentUser = Auth::user();
        $expandedIds = [];

        // Admin sees everything expanded
        if ($currentUser->isAdmin()) {
            return ['all']; // Special marker to expand all
        }

        // For Kodim Admin and Koramil Admin, expand their coverage area
        if ($currentUser->office_id) {
            $currentOffice = Office::with('level')->find($currentUser->office_id);

            if ($currentOffice) {
                // Expand current office
                $expandedIds[] = $currentOffice->id;

                // If Kodim level, also expand all child Koramils
                if ($currentOffice->level && $currentOffice->level->level === 3) {
                    $children = Office::where('parent_id', $currentOffice->id)->pluck('id')->toArray();
                    $expandedIds = array_merge($expandedIds, $children);
                }

                // Expand all ancestors to show the path
                $ancestors = Office::where('_lft', '<', $currentOffice->_lft)
                    ->where('_rgt', '>', $currentOffice->_rgt)
                    ->pluck('id')
                    ->toArray();
                $expandedIds = array_merge($expandedIds, $ancestors);
            }
        }

        return $expandedIds;
    }

    public function with(): array
    {
        $currentUser = Auth::user();
        $isAdmin = $currentUser->isAdmin();

        $query = User::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")
                ->orWhere('nrp', 'like', "%{$this->search}%"))
            ->when($this->filter === 'pending', fn ($q) => $q->where('is_approved', false))
            ->when($this->filter === 'approved', fn ($q) => $q->where('is_approved', true))
            ->when($this->filter === 'admin', fn ($q) => $q->where('is_admin', true))
            ->with('approvedBy', 'projects', 'office.parent', 'office.level', 'roles')
            ->withCount('projects');

        // Managers can only see users under their Kodim coverage
        // Koramil Admins can only see users in their exact office
        if (! $isAdmin && $currentUser->office_id) {
            $currentOffice = Office::with('level')->find($currentUser->office_id);

            // If user is at Kodim level (Manager), filter users to only show Koramil under their Kodim
            if ($currentOffice && $currentOffice->level->level === 3) {
                $query->whereHas('office', function ($q) use ($currentUser) {
                    $q->where('parent_id', $currentUser->office_id);
                });
            }
            // If user is at Koramil level (Koramil Admin), filter users to only show same office
            elseif ($currentOffice && $currentOffice->level->level === 4) {
                $query->where('office_id', $currentUser->office_id);
            }
        }

        $query->orderByDesc('created_at');

        // Count pending users with coverage filter
        $pendingCountQuery = User::where('is_approved', false);
        if (! $isAdmin && $currentUser->office_id) {
            $currentOffice = Office::with('level')->find($currentUser->office_id);
            if ($currentOffice && $currentOffice->level->level === 3) {
                $pendingCountQuery->whereHas('office', function ($q) use ($currentUser) {
                    $q->where('parent_id', $currentUser->office_id);
                });
            } elseif ($currentOffice && $currentOffice->level->level === 4) {
                $pendingCountQuery->where('office_id', $currentUser->office_id);
            }
        }
        $pendingCount = $pendingCountQuery->count();
        $projects = $this->getAvailableProjectsForAssignment();

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
                // Koramil Admins can only assign users to their own Koramil
                if (! $isAdmin && $currentUser->office_id) {
                    $currentOffice = Office::with('level')->find($currentUser->office_id);
                    if ($currentOffice && $currentOffice->level->level === 3) {
                        // If Manager, only show Koramils under their Kodim
                        $officesQuery->where('parent_id', $currentUser->office_id);
                    } elseif ($currentOffice && $currentOffice->level->level === 4) {
                        // If Koramil Admin, only show their own office
                        $officesQuery->where('id', $currentUser->office_id);
                    }
                }

                $availableOffices = $officesQuery->orderBy('name')->get();
            } else {
                // If role doesn't have office_level_id restriction, show all offices
                $officesQuery = Office::with('level')->orderBy('level_id')->orderBy('name');

                // Managers can only assign to offices under their coverage
                // Koramil Admins can only assign to their own office
                if (! $isAdmin && $currentUser->office_id) {
                    $currentOffice = Office::with('level')->find($currentUser->office_id);
                    if ($currentOffice && $currentOffice->level->level === 3) {
                        $officesQuery->where('parent_id', $currentUser->office_id);
                    } elseif ($currentOffice && $currentOffice->level->level === 4) {
                        $officesQuery->where('id', $currentUser->office_id);
                    }
                }

                $availableOffices = $officesQuery->get();
            }
        }

        $users = $query->get();

        // Get data for reassignment modal
        $deletingUser = $this->deletingUserId
            ? User::with('projects.location', 'projects.partner', 'projects.office')->find($this->deletingUserId)
            : null;

        $eligibleReplacementUsers = $this->getEligibleReplacementUsers();

        return [
            'users' => $users,
            'officeHierarchy' => $this->buildOfficeHierarchy($users),
            'expandedOffices' => $this->getDefaultExpandedOffices(),
            'pendingCount' => $pendingCount,
            'projects' => $projects,
            'offices' => $offices,
            'roles' => $roles,
            'availableOffices' => $availableOffices,
            'deletingUser' => $deletingUser,
            'eligibleReplacementUsers' => $eligibleReplacementUsers,
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6"
     x-data="{
         expandedOffices: @js($expandedOffices),
         isExpanded(officeId) {
             return this.expandedOffices.includes('all') || this.expandedOffices.includes(officeId);
         },
         toggleOffice(officeId) {
             // If 'all' is present, we need to expand everything except the clicked one
             if (this.expandedOffices.includes('all')) {
                 // Get all office IDs from the hierarchy
                 const allIds = this.getAllOfficeIds();
                 // Remove 'all' and set to all IDs except the one being collapsed
                 this.expandedOffices = allIds.filter(id => id !== officeId);
             } else if (this.expandedOffices.includes(officeId)) {
                 // Normal collapse: remove from array
                 this.expandedOffices = this.expandedOffices.filter(id => id !== officeId);
             } else {
                 // Normal expand: add to array
                 this.expandedOffices.push(officeId);
             }
         },
         getAllOfficeIds() {
             // Recursively get all office IDs from hierarchy
             const ids = [];
             const traverse = (nodes) => {
                 nodes.forEach(node => {
                     ids.push(node.office.id);
                     if (node.children && node.children.length > 0) {
                         traverse(node.children);
                     }
                 });
             };
             traverse(@js($officeHierarchy));
             return ids;
         }
     }"
     @expand-all-offices.window="expandedOffices = ['all']">
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
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex-1 sm:max-w-md">
            <flux:input
                wire:model.live.debounce.300ms="search"
                type="search"
                placeholder="Search by name, phone, or NRP..."
            />
        </div>
        <div class="sm:w-48">
            <flux:select wire:model.live="filter">
                <option value="all">All Users</option>
                <option value="pending">Pending Approval</option>
                <option value="approved">Approved</option>
                <option value="admin">Admins</option>
            </flux:select>
        </div>
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
                    <th class="px-4 py-3 text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($officeHierarchy as $officeNode)
                    @include('livewire.admin.users.partials.office-node', ['node' => $officeNode, 'level' => 0])
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-neutral-500">
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
                wire:model="editPhone"
                label="Phone Number"
                type="tel"
                required
                placeholder="08123456789"
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

            <div>
                <label class="mb-2 block text-sm font-medium text-neutral-900 dark:text-neutral-100">Roles</label>
                @error('editRoleIds')
                    <p class="mb-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <div class="space-y-2">
                    @foreach($roles as $role)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model="editRoleIds"
                                value="{{ $role->id }}"
                                class="rounded border-neutral-300 text-blue-600 focus:ring-blue-500 dark:border-neutral-600"
                            />
                            <span class="text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $role->name }}</span>
                            @if($role->office_level_id)
                                @php
                                    $officeLevel = \App\Models\OfficeLevel::find($role->office_level_id);
                                @endphp
                                <span class="text-xs text-neutral-500 dark:text-neutral-400">
                                    (Requires {{ $officeLevel?->name ?? 'specific office level' }})
                                </span>
                            @endif
                        </label>
                    @endforeach
                </div>
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

    <!-- Reassign Projects Modal -->
    <flux:modal wire:model="showReassignModal" class="min-w-[600px]">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Reassign Projects Before Deletion</flux:heading>
                @if($deletingUser)
                    <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                        User <strong>{{ $deletingUser->name }}</strong> is assigned to {{ $deletingUser->projects->count() }} {{ Str::plural('project', $deletingUser->projects->count()) }}.
                        Please assign a replacement user for each project before deletion.
                    </p>
                @endif
            </div>

            @error('projectReassignments')
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-200">
                    {{ $message }}
                </div>
            @enderror

            <div class="max-h-[500px] space-y-3 overflow-y-auto">
                @if($deletingUser)
                    @foreach($deletingUser->projects as $project)
                        <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                            <div class="mb-3">
                                <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $project->name }}</div>
                                <div class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                                    {{ $project->location?->village_name ?? '-' }}
                                    @if($project->office)
                                        • {{ $project->office->name }}
                                    @endif
                                </div>
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                    Assign to:
                                </label>
                                <flux:select wire:model="projectReassignments.{{ $project->id }}" required>
                                    <flux:select.option value="">Select replacement user</flux:select.option>
                                    @foreach($eligibleReplacementUsers as $user)
                                        <flux:select.option value="{{ $user->id }}">
                                            {{ $user->name }}
                                            @if($user->office)
                                                - {{ $user->office->name }}
                                            @endif
                                            @if($user->roles->count() > 0)
                                                ({{ $user->roles->pluck('name')->implode(', ') }})
                                            @endif
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            <div class="flex justify-end gap-3 border-t border-neutral-200 pt-4 dark:border-neutral-700">
                <flux:button wire:click="$set('showReassignModal', false)" variant="outline">
                    Cancel
                </flux:button>
                <flux:button
                    wire:click="reassignAndDeleteUser"
                    variant="danger"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="reassignAndDeleteUser">Reassign & Delete User</span>
                    <span wire:loading wire:target="reassignAndDeleteUser">Processing...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>

 User, Project, Location, Office, OfficeLevel, and Role Relationships

  Database Schemas

  1. Office Levels Table (office_levels)

  Purpose: Defines the 4-level military command hierarchy

  | Column                | Type      | Constraints    | Description                                 |
  |-----------------------|-----------|----------------|---------------------------------------------|
  | id                    | INT       | Primary Key    | Unique identifier                           |
  | level                 | INT       | UNIQUE         | Numeric level (1-4)                         |
  | name                  | VARCHAR   |                | Display name (Kodam, Korem, Kodim, Koramil) |
  | description           | VARCHAR   | nullable       | Optional description                        |
  | is_default_user_level | BOOLEAN   | default: false | Marks Koramil as default registration level |
  | created_at            | TIMESTAMP |                | Creation timestamp                          |
  | updated_at            | TIMESTAMP |                | Update timestamp                            |

  Indexes:
  - office_levels_level_unique on level

  ---
  2. Offices Table (offices)

  Purpose: Stores military command offices in a 4-level nested hierarchy

  | Column            | Type      | Constraints                             | Description                |
  |-------------------|-----------|-----------------------------------------|----------------------------|
  | id                | INT       | Primary Key                             | Unique identifier          |
  | parent_id         | INT       | FK → offices.id, nullable, nullOnDelete | Parent office in hierarchy |
  | level_id          | INT       | FK → office_levels.id                   | Office level (1-4)         |
  | name              | VARCHAR   |                                         | Office name                |
  | code              | VARCHAR   | nullable                                | Office code                |
  | notes             | TEXT      | nullable                                | Additional notes           |
  | coverage_province | VARCHAR   | nullable                                | Province coverage          |
  | coverage_city     | VARCHAR   | nullable                                | City/municipality coverage |
  | coverage_district | VARCHAR   | nullable                                | District coverage          |
  | _lft              | INT       | default: 0                              | Nested set left value      |
  | _rgt              | INT       | default: 0                              | Nested set right value     |
  | created_at        | TIMESTAMP |                                         | Creation timestamp         |
  | updated_at        | TIMESTAMP |                                         | Update timestamp           |

  Foreign Keys:
  - parent_id → offices.id (cascadeOnDelete)
  - level_id → office_levels.id

  Indexes:
  - offices__lft__rgt_index on (_lft, _rgt) for nested set queries
  - offices_level_id_index on level_id

  ---
  3. Users Table (users)

  Purpose: Stores user accounts with office assignment and approval workflow

  | Column                    | Type      | Constraints                             | Description                          |
  |---------------------------|-----------|-----------------------------------------|--------------------------------------|
  | id                        | INT       | Primary Key                             | Unique identifier                    |
  | name                      | VARCHAR   |                                         | Full name                            |
  | email                     | VARCHAR   | UNIQUE, nullable                        | Email address (optional)             |
  | email_verified_at         | TIMESTAMP | nullable                                | Email verification timestamp         |
  | password                  | VARCHAR   |                                         | Hashed password                      |
  | remember_token            | VARCHAR   | nullable                                | Remember me token                    |
  | nrp                       | VARCHAR   | UNIQUE, nullable                        | Army Employee ID                     |
  | phone                     | VARCHAR   | UNIQUE, nullable                        | Mobile phone number (starts with 08) |
  | phone_verified_at         | TIMESTAMP | nullable                                | Phone verification timestamp         |
  | is_approved               | BOOLEAN   | default: false                          | Approval status                      |
  | approved_at               | TIMESTAMP | nullable                                | When user was approved               |
  | approved_by               | INT       | FK → users.id, nullable, nullOnDelete   | Who approved this user               |
  | is_admin                  | BOOLEAN   | default: false                          | Admin flag                           |
  | office_id                 | INT       | FK → offices.id, nullable, nullOnDelete | Assigned office                      |
  | two_factor_secret         | TEXT      | nullable                                | 2FA secret                           |
  | two_factor_recovery_codes | TEXT      | nullable                                | 2FA recovery codes                   |
  | two_factor_confirmed_at   | TIMESTAMP | nullable                                | 2FA confirmation timestamp           |
  | created_at                | TIMESTAMP |                                         | Creation timestamp                   |
  | updated_at                | TIMESTAMP |                                         | Update timestamp                     |

  Foreign Keys:
  - approved_by → users.id (who approved this user)
  - office_id → offices.id (user's assigned office)

  Unique Indexes:
  - users_email_unique on email
  - users_nrp_unique on nrp
  - users_phone_unique on phone

  ---
  4. Locations Table (locations)

  Purpose: Stores geographic locations (villages/desa) for project assignment

  | Column        | Type      | Constraints | Description                                         |
  |---------------|-----------|-------------|-----------------------------------------------------|
  | id            | INT       | Primary Key | Unique identifier                                   |
  | village_name  | VARCHAR   |             | Village/desa name                                   |
  | city_name     | VARCHAR   |             | City/municipality name                              |
  | district_name | VARCHAR   |             | District/kecamatan name (normalized to "Kec. Name") |
  | province_name | VARCHAR   |             | Province name                                       |
  | notes         | TEXT      | nullable    | Additional notes                                    |
  | created_at    | TIMESTAMP |             | Creation timestamp                                  |
  | updated_at    | TIMESTAMP |             | Update timestamp                                    |

  ---
  5. Projects Table (projects)

  Purpose: Stores project information with office and location assignment

  | Column      | Type      | Constraints                             | Description                  |
  |-------------|-----------|-----------------------------------------|------------------------------|
  | id          | INT       | Primary Key                             | Unique identifier            |
  | name        | VARCHAR   |                                         | Project name                 |
  | description | TEXT      | nullable                                | Project description          |
  | partner_id  | INT       | FK → partners.id, cascadeOnDelete       | Partner organization         |
  | office_id   | INT       | FK → offices.id, nullable, nullOnDelete | Responsible office (Koramil) |
  | location_id | INT       | FK → locations.id, cascadeOnDelete      | Project location             |
  | start_date  | DATE      | nullable                                | Start date                   |
  | end_date    | DATE      | nullable                                | End date                     |
  | status      | ENUM      | planning, active, completed, on_hold    | Project status               |
  | created_at  | TIMESTAMP |                                         | Creation timestamp           |
  | updated_at  | TIMESTAMP |                                         | Update timestamp             |

  Foreign Keys:
  - partner_id → partners.id
  - office_id → offices.id (the Koramil responsible)
  - location_id → locations.id

  ---
  6. Project_User Pivot Table (project_user)

  Purpose: Many-to-many relationship between users and projects

  | Column     | Type      | Constraints                       | Description                               |
  |------------|-----------|-----------------------------------|-------------------------------------------|
  | id         | INT       | Primary Key                       | Unique identifier                         |
  | project_id | INT       | FK → projects.id, cascadeOnDelete | Assigned project                          |
  | user_id    | INT       | FK → users.id, cascadeOnDelete    | Assigned user                             |
  | role       | VARCHAR   | default: 'member'                 | User's role (member, supervisor, manager) |
  | created_at | TIMESTAMP |                                   | Creation timestamp                        |
  | updated_at | TIMESTAMP |                                   | Update timestamp                          |

  Unique Index:
  - project_user_project_id_user_id_unique on (project_id, user_id)

  ---
  7. Roles Table (roles)

  Purpose: Defines user roles with office level requirements and permissions

  | Column          | Type      | Constraints                                   | Description                                    |
  |-----------------|-----------|-----------------------------------------------|------------------------------------------------|
  | id              | INT       | Primary Key                                   | Unique identifier                              |
  | name            | VARCHAR   | UNIQUE                                        | Role name (Admin, Reporter, Kodim Admin, etc.) |
  | description     | TEXT      | nullable                                      | Role description                               |
  | office_level_id | INT       | FK → office_levels.id, nullable, nullOnDelete | Required office level                          |
  | permissions     | JSON      | nullable                                      | Array of permission strings                    |
  | is_system       | BOOLEAN   | default: false                                | System role flag                               |
  | created_at      | TIMESTAMP |                                               | Creation timestamp                             |
  | updated_at      | TIMESTAMP |                                               | Update timestamp                               |

  Foreign Keys:
  - office_level_id → office_levels.id (null means any level)

  Unique Index:
  - roles_name_unique on name

  ---
  8. Role_User Pivot Table (role_user)

  Purpose: Many-to-many relationship between users and roles with audit trail

  | Column      | Type      | Constraints                           | Description                    |
  |-------------|-----------|---------------------------------------|--------------------------------|
  | id          | INT       | Primary Key                           | Unique identifier              |
  | role_id     | INT       | FK → roles.id, cascadeOnDelete        | Assigned role                  |
  | user_id     | INT       | FK → users.id, cascadeOnDelete        | User receiving role            |
  | assigned_by | INT       | FK → users.id, nullable, nullOnDelete | Who assigned this role (audit) |
  | created_at  | TIMESTAMP |                                       | Creation timestamp             |
  | updated_at  | TIMESTAMP |                                       | Update timestamp               |

  Unique Index:
  - role_user_role_id_user_id_unique on (role_id, user_id)

  ---
  Model Relationships

  User Model (app/Models/User.php)

  Belongs to One Office

  public function office(): BelongsTo
  {
      return $this->belongsTo(Office::class);
  }
  Explanation: Each user is assigned to ONE office (Koramil, Kodim, Korem, or Kodam). This determines their coverage area and default permissions.

  Example:
  $user = User::find(1);
  $office = $user->office; // Koramil 0735/Surakarta
  $officeName = $user->office->name; // "Koramil 0735/Surakarta"

  ---
  Approved By Another User (Self-Referential)

  public function approvedBy(): BelongsTo
  {
      return $this->belongsTo(User::class, 'approved_by');
  }
  Explanation: Tracks which admin approved this user's registration. Creates an audit trail for user approvals.

  Example:
  $user = User::find(5);
  $approver = $user->approvedBy; // The admin who approved this user
  $approverName = $user->approvedBy->name ?? 'Pending approval';

  ---
  Many-to-Many with Roles

  public function roles(): BelongsToMany
  {
      return $this->belongsToMany(Role::class)
          ->withPivot('assigned_by')
          ->withTimestamps();
  }
  Explanation: Users can have multiple roles (e.g., both Reporter and Viewer). The pivot includes assigned_by to track who granted each role.

  Example:
  // Get user's roles
  $user->roles; // Collection of Role models

  // Check if user has role
  $user->hasRole('Reporter'); // true/false

  // Assign role with audit trail
  $user->roles()->attach($roleId, [
      'assigned_by' => auth()->id(),
  ]);

  // Get who assigned a role
  $user->roles()->first()->pivot->assigned_by;

  ---
  Many-to-Many with Projects

  public function projects(): BelongsToMany
  {
      return $this->belongsToMany(Project::class)
          ->withPivot('role')
          ->withTimestamps();
  }
  Explanation: Users can be assigned to multiple projects. The pivot stores their role in each project (member, supervisor, manager).

  Example:
  // Get assigned projects
  $user->projects; // Collection of Project models

  // Get user's role in a project
  $project = $user->projects()->first();
  $roleInProject = $project->pivot->role; // 'member', 'supervisor', or 'manager'

  // Assign user to project
  $project->users()->attach($userId, ['role' => 'supervisor']);

  ---
  Permission Checking

  public function hasRole(Role|string $role): bool
  {
      if ($role instanceof Role) {
          return $this->roles->contains('id', $role->id);
      }
      return $this->roles->contains('name', $role);
  }

  public function hasPermission(string $permission): bool
  {
      if ($this->isAdmin()) {
          return true;
      }
      return $this->roles
          ->pluck('permissions')
          ->flatten()
          ->contains(fn ($p) => $p === '*' || $p === $permission);
  }
  Explanation:
  - hasRole() checks if user has a specific role
  - hasPermission() checks if user has a specific permission (wildcard '*' grants all)
  - Admins automatically have all permissions

  Example:
  if ($user->hasPermission('edit_projects')) {
      // Allow editing
  }

  if ($user->hasRole('Kodim Admin')) {
      // Show Kodim admin menu
  }

  ---
  Project Model (app/Models/Project.php)

  Belongs to a Partner

  public function partner(): BelongsTo
  {
      return $this->belongsTo(Partner::class);
  }

  Belongs to a Location

  public function location(): BelongsTo
  {
      return $this->belongsTo(Location::class);
  }
  Explanation: Each project happens in ONE specific location (village). Location is filtered by the Koramil's geographic coverage.

  Example:
  $project->location->village_name; // "Desa Manahan"
  $project->location->district_name; // "Kec. Surakarta"

  ---
  Belongs to an Office

  public function office(): BelongsTo
  {
      return $this->belongsTo(Office::class);
  }
  Explanation: The Koramil responsible for this project. Determines which users can see/manage the project.

  Example:
  $project->office->name; // "Koramil 0735/Surakarta"
  $project->office->level->name; // "Koramil"

  ---
  Many-to-Many with Users

  public function users(): BelongsToMany
  {
      return $this->belongsToMany(User::class)
          ->withPivot('role')
          ->withTimestamps();
  }
  Explanation: Multiple users can be assigned to a project. Each assignment includes a role (member, supervisor, manager).

  Example:
  // Get assigned users
  $project->users; // Collection of User models

  // With role
  foreach ($project->users as $user) {
      echo "{$user->name} - {$user->pivot->role}";
  }

  // Count assigned users
  $project->users()->count();

  ---
  Has Many Tasks

  public function tasks(): HasMany
  {
      return $this->hasMany(Task::class);
  }
  Explanation: Each project has multiple tasks cloned from templates. Tasks form a 6-level nested hierarchy.

  ---
  Location Model (app/Models/Location.php)

  Has Many Projects

  public function projects(): HasMany
  {
      return $this->hasMany(Project::class);
  }
  Explanation: A location (village) can have multiple projects happening there.

  Example:
  $location = Location::where('village_name', 'Desa Manahan')->first();
  $projectsInVillage = $location->projects; // All projects in this village

  ---
  Office Model (app/Models/Office.php)

  Belongs to a Parent Office (Self-Referential)

  public function parent(): BelongsTo
  {
      return $this->belongsTo(Office::class, 'parent_id');
  }
  Explanation: Creates the 4-level hierarchy. Koramil → Kodim → Korem → Kodam

  Example:
  $koramil = Office::find(10); // Koramil 0735/Surakarta
  $kodim = $koramil->parent; // Kodim 0735
  $korem = $kodim->parent; // Korem 074
  $kodam = $korem->parent; // Kodam IV

  ---
  Has Many Child Offices

  public function children(): HasMany
  {
      return $this->hasMany(Office::class, 'parent_id');
  }
  Explanation: A Kodim has many Koramils, a Korem has many Kodims, etc.

  Example:
  $kodim = Office::find(5);
  $koramils = $kodim->children; // All Koramils under this Kodim

  ---
  Belongs to an Office Level

  public function level(): BelongsTo
  {
      return $this->belongsTo(OfficeLevel::class, 'level_id');
  }
  Explanation: Each office has a level (1=Kodam, 2=Korem, 3=Kodim, 4=Koramil)

  Example:
  $office->level->name; // "Koramil"
  $office->level->level; // 4

  ---
  Has Many Users

  public function users(): HasMany
  {
      return $this->hasMany(User::class, 'office_id');
  }
  Explanation: Multiple users can be assigned to the same office.

  ---
  Has Many Projects

  public function projects(): HasMany
  {
      return $this->hasMany(Project::class, 'office_id');
  }
  Explanation: Tracks which office is responsible for which projects.

  ---
  Hierarchy Helper Methods

  public function getHierarchyPath(): string
  {
      $path = [$this->name];
      $current = $this;
      while ($current->parent_id) {
          $parent = $current->parent;
          if (!$parent) break;
          array_unshift($path, $parent->name);
          $current = $parent;
      }
      return implode(' > ', $path);
  }
  Example:
  $koramil->getHierarchyPath();
  // "Kodam IV > Korem 074 > Kodim 0735 > Koramil 0735/Surakarta"

  ---
  Nested Set Queries

  // Get all descendants (using _lft and _rgt)
  public function descendants(): Collection
  {
      return static::where('_lft', '>', $this->_lft)
          ->where('_rgt', '<', $this->_rgt)
          ->orderBy('_lft')
          ->get();
  }

  // Get all ancestors
  public function ancestors(): Collection
  {
      return static::where('_lft', '<', $this->_lft)
          ->where('_rgt', '>', $this->_rgt)
          ->orderBy('_lft')
          ->get();
  }
  Explanation: Nested set model allows efficient querying of all descendants or ancestors without recursive queries.

  Example:
  $kodim = Office::find(5);
  $allUnderKodim = $kodim->descendants(); // All Koramils under this Kodim
  $allAboveKoramil = $koramil->ancestors(); // Kodim, Korem, Kodam

  ---
  OfficeLevel Model (app/Models/OfficeLevel.php)

  Has Many Offices

  public function offices(): HasMany
  {
      return $this->hasMany(Office::class, 'level_id');
  }
  Example:
  $koramilLevel = OfficeLevel::where('level', 4)->first();
  $allKoramils = $koramilLevel->offices; // All Koramil offices

  ---
  Get Default User Level

  public static function getDefaultUserLevel(): ?self
  {
      return static::where('is_default_user_level', true)->first();
  }
  Explanation: Returns Koramil level (level 4), the default registration level for new users.

  ---
  Role Model (app/Models/Role.php)

  Many-to-Many with Users

  public function users(): BelongsToMany
  {
      return $this->belongsToMany(User::class)
          ->withPivot('assigned_by')
          ->withTimestamps();
  }
  Example:
  $reporterRole = Role::where('name', 'Reporter')->first();
  $allReporters = $reporterRole->users; // All users with Reporter role

  ---
  Office Hierarchy Structure (4-Level System)

  The system implements a military command structure using nested set (modified preorder tree traversal) pattern:

  Hierarchy Levels

  Level 1: Kodam (Komando Daerah Militer)
      └─ Level 2: Korem (Komando Resort Militer)
          └─ Level 3: Kodim (Komando Distrik Militer)
              └─ Level 4: Koramil (Komando Rayon Militer) ← DEFAULT

  Real Example from Seeder Data

  Kodam IV/Diponegoro (_lft=1, _rgt=50)
    └─ Korem 074/Warastratama (_lft=2, _rgt=49)
        └─ Kodim 0735/Surakarta (_lft=3, _rgt=20)
            ├─ Koramil 0735/Surakarta (_lft=4, _rgt=5)
            ├─ Koramil 0735/Banjarsari (_lft=6, _rgt=7)
            ├─ Koramil 0735/Jebres (_lft=8, _rgt=9)
            ├─ Koramil 0735/Laweyan (_lft=10, _rgt=11)
            ├─ Koramil 0735/Pasar Kliwon (_lft=12, _rgt=13)
            └─ Koramil 0735/Serengan (_lft=14, _rgt=15)

  Nested Set Query Examples

  Find all descendants of Kodim 0735:
  SELECT * FROM offices
  WHERE _lft > 3 AND _rgt < 20
  ORDER BY _lft;
  Returns: All 6 Koramils under Kodim 0735

  Find all ancestors of Koramil 0735/Surakarta:
  SELECT * FROM offices
  WHERE _lft < 4 AND _rgt > 5
  ORDER BY _lft;
  Returns: Kodim 0735 → Korem 074 → Kodam IV (in order)

  ---
  Geographic Coverage System

  Each office has three optional coverage fields that filter available locations:

  'coverage_province'  // Province level
  'coverage_city'      // City/municipality level  
  'coverage_district'  // District/kecamatan level (most specific)

  Coverage Filtering Logic (from projects/index.blade.php:625-636)

  $locations = Location::query();
  if ($this->koramilId) {
      $koramil = Office::find($this->koramilId);
      if ($koramil) {
          if ($koramil->coverage_district) {
              // Filter by district (most specific)
              $locations->where('district_name', $koramil->coverage_district);
          } elseif ($koramil->coverage_city) {
              // Filter by city
              $locations->where('city_name', $koramil->coverage_city);
          }
      }
  }
  $locations = $locations->orderBy('city_name')
                         ->orderBy('village_name')
                         ->get();

  Real Example

  Koramil 0735/Surakarta:
  - coverage_district = "Kec. Surakarta"

  When creating a project at this Koramil:
  - Location dropdown shows ONLY villages where district_name = "Kec. Surakarta"
  - Example results: Desa Manahan, Desa Sangkrah, Desa Timuran, etc.

  ---
  Role-Based Access Control System

  Defined Roles (from database/seeders/RoleSeeder.php)

  1. Admin

  [
      'name' => 'Admin',
      'office_level_id' => null, // ANY level
      'permissions' => ['*'],     // ALL permissions
      'is_system' => true,
  ]
  Can do: Everything across all offices

  ---
  2. Reporter (Koramil Level)

  [
      'name' => 'Reporter',
      'office_level_id' => 4, // MUST be at Koramil
      'permissions' => [
          'view_projects',
          'create_projects',
          'update_progress',
          'view_tasks',
      ],
      'is_system' => false,
  ]
  Can do: Create projects, update progress in their Koramil

  ---
  3. Kodim Admin (Kodim Level)

  [
      'name' => 'Kodim Admin',
      'office_level_id' => 3, // MUST be at Kodim
      'permissions' => [
          'view_projects',
          'edit_projects',
          'view_tasks',
          'edit_tasks',
          'view_reports',
          'manage_users',
      ],
      'is_system' => false,
  ]
  Can do: Manage all projects in Koramils under their Kodim, manage users

  ---
  4. Koramil Admin (Koramil Level)

  [
      'name' => 'Koramil Admin',
      'office_level_id' => 4, // MUST be at Koramil
      'permissions' => [
          'view_projects',
          'create_projects',
          'edit_projects',
          'delete_projects',
          'update_progress',
          'view_tasks',
          'manage_users',
      ],
      'is_system' => false,
  ]
  Can do: Full CRUD on projects in their Koramil, manage Koramil users

  ---
  5. Viewer

  [
      'name' => 'Viewer',
      'office_level_id' => null, // ANY level
      'permissions' => [
          'view_projects',
          'view_tasks',
          'view_reports',
      ],
      'is_system' => false,
  ]
  Can do: Read-only access

  ---
  Role Assignment Tracking

  The role_user pivot table includes assigned_by for audit trail:

  // Assigning a role
  $user->roles()->attach($roleId, [
      'assigned_by' => Auth::id(),
      'created_at' => now(),
      'updated_at' => now(),
  ]);

  // Finding who assigned a role
  $pivot = DB::table('role_user')
      ->where('user_id', $userId)
      ->where('role_id', $roleId)
      ->first();
  $assignedBy = User::find($pivot->assigned_by);

  ---
  Auto-Assignment on Approval (app/Livewire/Admin/Users/Index.php:173-206)

  When an admin approves a pending user, the system automatically assigns a role based on their office level:

  public function approveUser(int $userId): void
  {
      $user = User::findOrFail($userId);

      $user->update([
          'is_approved' => true,
          'approved_at' => now(),
          'approved_by' => Auth::id(),
      ]);

      // Auto-assign role based on office level
      if ($user->office_id) {
          $userOffice = Office::with('level')->find($user->office_id);

          if ($userOffice && $userOffice->level) {
              if ($userOffice->level->level === 4) {
                  // Koramil → Reporter
                  $roleToAssign = Role::where('name', 'Reporter')->first();
              } elseif ($userOffice->level->level === 3) {
                  // Kodim → Kodim Admin
                  $roleToAssign = Role::where('name', 'Kodim Admin')->first();
              }

              if ($roleToAssign && !$user->hasRole($roleToAssign)) {
                  $user->roles()->attach($roleToAssign->id, [
                      'assigned_by' => Auth::id(),
                  ]);
              }
          }
      }
  }

  ---
  Permission Checking (app/Models/User.php:78-90)

  public function hasPermission(string $permission): bool
  {
      // Admins bypass all checks
      if ($this->isAdmin()) {
          return true;
      }

      // Check if any role has the permission
      return $this->roles
          ->pluck('permissions')
          ->flatten()
          ->contains(fn ($p) => $p === '*' || $p === $permission);
  }

  Usage Example:
  if (auth()->user()->hasPermission('edit_projects')) {
      // Show edit button
  }

  ---
  User-Project Assignment System

  Many-to-Many via project_user Pivot

  The relationship stores:
  - project_id - The assigned project
  - user_id - The assigned user
  - role - User's role in project (member, supervisor, manager)
  - created_at / updated_at - Timestamps

  Assignment Logic (resources/views/livewire/admin/users/index.blade.php:322-355)

  Non-admin users can only assign projects within their coverage area:

  private function getAvailableProjectsForAssignment()
  {
      $currentUser = auth()->user();
      $query = Project::with(['location', 'partner', 'office']);

      // Admins see ALL projects
      if ($currentUser->isAdmin() || $currentUser->hasPermission('*')) {
          return $query->orderBy('name')->get();
      }

      // Non-admins need an office
      if (!$currentUser->office_id) {
          return collect();
      }

      $currentOffice = Office::with('level')->find($currentUser->office_id);

      // Kodim Admin: Projects in Koramils under their Kodim
      if ($currentOffice->level->level === 3) {
          $query->whereHas('office', function ($q) use ($currentUser) {
              $q->where('parent_id', $currentUser->office_id);
          });
      }
      // Koramil Admin: Projects in their exact Koramil
      elseif ($currentOffice->level->level === 4) {
          $query->where('office_id', $currentUser->office_id);
      }

      return $query->orderBy('name')->get();
  }

  Syncing Assignments

  public function saveProjectAssignments(): void
  {
      $user = User::findOrFail($this->projectUserId);
      $user->projects()->sync($this->selectedProjects);
      // Eloquent handles adding/removing pivot records
  }

  ---
  Real-World Workflow Examples

  Workflow 1: User Registration → Approval → Role Assignment

  Step 1: User Registers (resources/views/livewire/auth/register.blade.php:31-71)

  // User fills form
  $this->validate([
      'name' => ['required', 'string', 'max:255'],
      'nrp' => ['required', 'string', 'unique:users,nrp'],
      'phone' => ['required', 'string', 'regex:/^08[0-9]{8,11}$/'],
      'kodimId' => ['required', 'exists:offices,id'],
      'officeId' => ['nullable', 'exists:offices,id'], // Optional Koramil
      'password' => ['required', 'min:8', 'confirmed'],
  ]);

  // Create user
  app(CreatesNewUsers::class)->create([
      'name' => $this->name,
      'nrp' => $this->nrp,
      'phone' => $this->phone,
      'office_id' => $this->officeId ?: $this->kodimId, // Koramil OR Kodim
      'password' => $this->password,
  ]);

  // User created with:
  // - is_approved = false
  // - approved_at = null
  // - approved_by = null
  // - NO roles yet

  Result: User is in "pending approval" state

  ---
  Step 2: Admin Approves User

  Admin goes to User Management → sees pending users → assigns office → clicks Approve

  public function approveUser(int $userId): void
  {
      $user = User::findOrFail($userId);

      // Mark as approved
      $user->update([
          'is_approved' => true,
          'approved_at' => now(),
          'approved_by' => Auth::id(), // Audit trail
      ]);

      // Auto-assign role based on office level
      if ($user->office_id) {
          $userOffice = Office::with('level')->find($user->office_id);

          if ($userOffice->level->level === 4) {
              // Koramil → Reporter role
              $role = Role::where('name', 'Reporter')->first();
          } elseif ($userOffice->level->level === 3) {
              // Kodim → Kodim Admin role
              $role = Role::where('name', 'Kodim Admin')->first();
          }

          if ($role && !$user->hasRole($role)) {
              $user->roles()->attach($role->id, [
                  'assigned_by' => Auth::id(),
              ]);
          }
      }
  }

  Result:
  - User can now log in
  - Has "Reporter" role (if Koramil) or "Kodim Admin" (if Kodim)
  - Can create projects within their coverage

  ---
  Workflow 2: Project Creation with Coverage Filtering

  Step 1: Reporter Opens Create Project Form

  System auto-populates their office (resources/views/livewire/projects/index.blade.php:96-109):

  public function create(): void
  {
      $user = auth()->user();

      if ($user->office) {
          if ($user->office->level->level == 4) {
              // Koramil level user
              $this->koramilId = $user->office->id;
              $this->kodimId = $user->office->parent_id;
          }
      }

      $this->isCreating = true;
  }

  UI shows:
  - Kodim: Pre-selected (parent of their Koramil)
  - Koramil: Pre-selected (their office)
  - Location: Filtered by Koramil coverage

  ---
  Step 2: Location Filtering (resources/views/livewire/projects/index.blade.php:625-636)

  When Koramil is selected, locations are filtered:

  // In with() method
  $locations = Location::query();

  if ($this->koramilId) {
      $koramil = Office::find($this->koramilId);

      if ($koramil) {
          if ($koramil->coverage_district) {
              // Filter by district
              $locations->where('district_name', $koramil->coverage_district);
          } elseif ($koramil->coverage_city) {
              // Filter by city
              $locations->where('city_name', $koramil->coverage_city);
          }
      }
  }

  $locations = $locations->orderBy('city_name')
                         ->orderBy('village_name')
                         ->get();

  Example:
  - Koramil 0735/Surakarta has coverage_district = "Kec. Surakarta"
  - Location dropdown shows ONLY villages in "Kec. Surakarta"

  ---
  Step 3: Project Created

  $project = Project::create([
      'name' => 'Koperasi Merah Putih Manahan',
      'office_id' => $this->koramilId,     // Koramil 0735/Surakarta
      'location_id' => $this->locationId,   // Village in Kec. Surakarta
      'partner_id' => $this->partnerId,
      'start_date' => $this->startDate,
      'end_date' => $this->endDate,
      'status' => 'planning',
  ]);

  // Auto-attach current user as reporter
  $project->users()->attach(auth()->id(), [
      'role' => 'member',
      'created_at' => now(),
      'updated_at' => now(),
  ]);

  Result:
  - Project assigned to Koramil 0735/Surakarta
  - Location validated to be within Koramil's coverage
  - Creator automatically assigned to project
  - Tasks auto-cloned from templates (via Observer)

  ---
  Workflow 3: Project Visibility Based on User Role/Office

  Scenario A: Admin
  // Query: ALL projects
  $projects = Project::with(['office', 'location', 'users'])->get();

  ---
  Scenario B: Reporter at Koramil X
  // Query: ONLY assigned projects
  $projects = Project::whereHas('users', function ($q) use ($userId) {
      $q->where('user_id', $userId);
  })->get();

  ---
  Scenario C: Kodim Admin at Kodim Y
  // Query: Projects in ALL Koramils under their Kodim
  $projects = Project::whereHas('office', function ($q) use ($kodimAdminOfficeId) {
      $q->where('parent_id', $kodimAdminOfficeId);
  })->get();

  Example:
  - Kodim Admin at "Kodim 0735"
  - Sees projects from: Koramil 0735/Surakarta, Koramil 0735/Banjarsari, etc.
  - Does NOT see projects from Kodim 0736

  ---
  Scenario D: Koramil Admin at Koramil Z
  // Query: Projects in their Koramil OR created by users in their Koramil
  $projects = Project::where(function ($q) use ($koramilAdminOfficeId) {
      $q->where('office_id', $koramilAdminOfficeId)
        ->orWhereHas('users', function ($userQ) use ($koramilAdminOfficeId) {
            $userQ->where('office_id', $koramilAdminOfficeId);
        });
  })->get();

  ---
  Workflow 4: User Management with Coverage-Based Project Assignment

  Step 1: Admin Opens User Management

  System builds hierarchical office tree (resources/views/livewire/admin/users/index.blade.php:483-546):

  private function buildOfficeHierarchy($users): array
  {
      // Get offices that have users
      $officeIds = $users->pluck('office_id')->unique();

      // Get those offices + their ancestors
      $userOffices = Office::whereIn('id', $officeIds)->get();

      $allOfficeIds = collect();
      foreach ($userOffices as $office) {
          // Add the office itself
          $allOfficeIds->push($office->id);

          // Add all ancestors using nested set
          $ancestorIds = Office::where('_lft', '<', $office->_lft)
                               ->where('_rgt', '>', $office->_rgt)
                               ->pluck('id');
          $allOfficeIds = $allOfficeIds->merge($ancestorIds);
      }

      // Build tree recursively
      return $this->buildOfficeTree($offices, $usersByOffice, null);
  }

  Result: Users displayed in hierarchical tree matching office structure

  ---
  Step 2: Admin Clicks "Projects" Button on a User

  Modal shows available projects based on admin's coverage:

  private function getAvailableProjectsForAssignment()
  {
      $currentUser = auth()->user();

      if ($currentUser->isAdmin()) {
          // Admins see ALL projects
          return Project::with(['location', 'partner', 'office'])
              ->orderBy('name')
              ->get();
      }

      $currentOffice = Office::with('level')->find($currentUser->office_id);

      if ($currentOffice->level->level === 3) {
          // Kodim Admin: Projects in Koramils under their Kodim
          return Project::whereHas('office', fn($q) =>
              $q->where('parent_id', $currentUser->office_id)
          )->orderBy('name')->get();
      }

      if ($currentOffice->level->level === 4) {
          // Koramil Admin: Projects in their Koramil
          return Project::where('office_id', $currentUser->office_id)
              ->orderBy('name')
              ->get();
      }
  }

  ---
  Step 3: Admin Selects Projects and Saves

  public function saveProjectAssignments(): void
  {
      $user = User::findOrFail($this->projectUserId);

      // Sync selected projects
      $user->projects()->sync($this->selectedProjects);

      // Eloquent handles:
      // - Adding new project_user records
      // - Removing unselected ones
      // - Keeping unchanged ones
  }

  Result: User now sees assigned projects when they log in

  ---
  Key Design Patterns

  1. Nested Set Pattern (_lft, _rgt)

  - Enables efficient querying of tree relationships
  - Find all descendants: WHERE _lft > parent._lft AND _rgt < parent._rgt
  - Find all ancestors: WHERE _lft < current._lft AND _rgt > current._rgt
  - Ordered by _lft for natural hierarchy traversal

  ---
  2. Pivot Table with Metadata

  - project_user: Stores user's role in project (member, supervisor, manager)
  - role_user: Stores who assigned the role (assigned_by) for audit trail

  ---
  3. Coverage-Based Authorization

  - Office coverage fields filter available locations
  - Hierarchical office relationships determine data visibility
  - Users can only see/manage data within their coverage area

  ---
  4. Office Level Enforcement

  - Roles tied to specific office levels (office_level_id)
  - Role assignment validated against user's office level
  - Project creation restricted by user's office coverage

  ---
  5. Permission System

  - Wildcard permissions ('*') for admins
  - Granular permissions for specific roles (e.g., 'edit_projects')
  - Permission checking via hasPermission() method
  - Role checking via hasRole() method

  ---
  6. Self-Referential Relationships

  - offices.parent_id → offices.id (hierarchy)
  - users.approved_by → users.id (audit trail)

  ---
  Data Seeding Flow

  1. OfficeLevelSeeder

  Creates 4 office levels:
  ['level' => 1, 'name' => 'Kodam', 'is_default_user_level' => false],
  ['level' => 2, 'name' => 'Korem', 'is_default_user_level' => false],
  ['level' => 3, 'name' => 'Kodim', 'is_default_user_level' => false],
  ['level' => 4, 'name' => 'Koramil', 'is_default_user_level' => true],

  ---
  2. OfficeSeeder

  Imports offices from database/seeders/data/korem-074.csv with:
  - Hierarchy structure (parent_id)
  - Nested set values (_lft, _rgt)
  - Geographic coverage (province, city, district)

  Example CSV:
  kodam,korem,kodim,koramil,coverage_province,coverage_city,coverage_district
  Kodam IV/Diponegoro,Korem 074/Warastratama,Kodim 0735/Surakarta,Koramil 0735/Surakarta,Jawa Tengah,Kota Surakarta,Kec. Surakarta

  ---
  3. LocationSeeder

  Imports locations from database/seeders/data/locations.csv:
  Location::updateOrCreate(
      [
          'province_name' => $provinceName,
          'city_name' => $cityName,
          'district_name' => $districtName,  // "Kec. Surakarta"
          'village_name' => $villageName,
      ]
  );

  ---
  4. RoleSeeder

  Creates 5 system roles:
  - Admin (all permissions)
  - Reporter (Koramil level)
  - Kodim Admin (Kodim level)
  - Koramil Admin (Koramil level)
  - Viewer (read-only)

  ---
  5. Complete User Flow

  Registration:
  1. User fills form with: name, phone, nrp, Kodim, Koramil (optional)
  2. User.is_approved = false
  3. User.office_id = selected Koramil OR Kodim
  4. NO roles yet

  Approval:
  5. Admin assigns office if needed
  6. Admin clicks "Approve"
  7. User.is_approved = true
  8. User.approved_by = admin.id
  9. System auto-assigns role based on office level:
     - Koramil → Reporter
     - Kodim → Kodim Admin

  Project Creation:
  10. User logs in (now approved with role)
  11. Creates project
  12. Location dropdown filtered by Koramil coverage
  13. Project.office_id = user's Koramil
  14. project_user record created linking user to project
  15. Tasks auto-cloned from templates (via Observer)

  Project Visibility:
  16. Reporters see ONLY assigned projects
  17. Koramil Admins see projects in their Koramil
  18. Kodim Admins see projects in ALL Koramils under their Kodim
  19. Admins see ALL projects

  ---
  Summary

  This comprehensive relationship system creates a hierarchical military command structure with:

  ✅ 4-level office hierarchy (Kodam → Korem → Kodim → Koramil) using nested set pattern
  ✅ Role-based access control with office level enforcement
  ✅ Geographic coverage-based filtering for locations
  ✅ User-project many-to-many assignment with role tracking
  ✅ Audit trails (who approved users, who assigned roles)
  ✅ Coverage-based authorization (users see only data in their area)
  ✅ Auto-assignment (roles assigned on approval based on office level)
  ✅ Efficient tree queries (nested set for ancestors/descendants)

  The system ensures that users can only create and manage projects within their geographic coverage area, roles are properly enforced based on office hierarchy, and all
  administrative actions are tracked for accountability.
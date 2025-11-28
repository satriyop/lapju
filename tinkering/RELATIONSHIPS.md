# Visual Guide: User-Project-Location-Office-Role Relationships

## 1. Entity Relationship Diagram (ERD)

```
┌─────────────────┐
│  OfficeLevel    │
│─────────────────│
│ id (PK)         │
│ level (1-4)     │◄──────────────┐
│ name            │               │
│ is_default_...  │               │
└─────────────────┘               │
                                  │
                                  │ level_id (FK)
                                  │
┌─────────────────┐         ┌─────┴──────────────┐
│     Role        │         │      Office        │
│─────────────────│         │────────────────────│
│ id (PK)         │         │ id (PK)            │
│ name            │         │ parent_id (FK) ────┼──┐
│ office_level_id ├────────►│ level_id (FK)      │  │
│ permissions[]   │         │ name               │  │
│ is_system       │         │ coverage_province  │  │ Self-referential
└────────┬────────┘         │ coverage_city      │  │ (hierarchy)
         │                  │ coverage_district  │  │
         │                  │ _lft, _rgt         │◄─┘
         │                  └──────┬─────────────┘
         │                         │
         │                         │ office_id (FK)
         │                         │
         │                  ┌──────┴─────────────┐
         │                  │       User         │
         │                  │────────────────────│
         │                  │ id (PK)            │
         │                  │ name               │
         │                  │ email              │
         │                  │ nrp                │
         │                  │ phone              │
         │                  │ office_id (FK) ────┼──┐
         │                  │ is_approved        │  │
         │                  │ approved_at        │  │
         │                  │ approved_by (FK) ──┼──┤ Self-referential
         │                  │ is_admin           │  │ (approval audit)
         │                  └──────┬─────────────┘◄─┘
         │                         │
         │                         │
         │        ┌────────────────┼────────────────┐
         │        │                │                │
         │        │                │                │
         │   ┌────▼──────┐    ┌────▼──────┐   ┌────▼──────┐
         │   │role_user  │    │project_   │   │  Task     │
         │   │(PIVOT)    │    │user       │   │ Progress  │
         │   │───────────│    │(PIVOT)    │   │───────────│
         └──►│ role_id   │    │───────────│   │ id        │
             │ user_id   │    │ project_id│◄──┤ user_id   │
             │assigned_by│    │ user_id   │   │ project_id│
             └───────────┘    │ role      │   │ task_id   │
                              └─────┬─────┘   └───────────┘
                                    │
                                    │
                              ┌─────▼─────────────┐
                              │     Project       │
                              │───────────────────│
                              │ id (PK)           │
                              │ name              │
                              │ office_id (FK) ───┼─────┐
                              │ location_id (FK) ─┼───┐ │
                              │ partner_id (FK)   │   │ │
                              │ start_date        │   │ │
                              │ end_date          │   │ │
                              │ status            │   │ │
                              └───────────────────┘   │ │
                                                      │ │
                                                      │ │
                              ┌───────────────────┐   │ │
                              │    Location       │◄──┘ │
                              │───────────────────│     │
                              │ id (PK)           │     │
                              │ village_name      │     │
                              │ city_name         │     │
                              │ district_name     │     │
                              │ province_name     │     │
                              └───────────────────┘     │
                                                        │
                              Back to Office ───────────┘
```

---

## 2. Office Hierarchy Structure (4-Level Tree)

```
┌──────────────────────────────────────────────────────────────────┐
│                    LEVEL 1: KODAM (Kodam IV)                     │
│                    _lft=1, _rgt=50                               │
│                    coverage_province="Jawa Tengah"               │
└────────────────────────────┬─────────────────────────────────────┘
                             │
                             │ parent_id
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│              LEVEL 2: KOREM (Korem 074/Warastratama)             │
│              _lft=2, _rgt=49                                     │
│              coverage_province="Jawa Tengah"                     │
└────────────────────────────┬─────────────────────────────────────┘
                             │
                             │ parent_id
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│           LEVEL 3: KODIM (Kodim 0735/Surakarta)                  │
│           _lft=3, _rgt=20                                        │
│           coverage_city="Kota Surakarta"                         │
└────────────────────────────┬─────────────────────────────────────┘
                             │
                             │ parent_id
                             ▼
         ┌───────────────────┴────────────────────┐
         │                   │                    │
         ▼                   ▼                    ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ LEVEL 4:        │  │ LEVEL 4:        │  │ LEVEL 4:        │
│ KORAMIL         │  │ KORAMIL         │  │ KORAMIL         │
│─────────────────│  │─────────────────│  │─────────────────│
│ Koramil 0735/   │  │ Koramil 0735/   │  │ Koramil 0735/   │
│ Surakarta       │  │ Banjarsari      │  │ Jebres          │
│                 │  │                 │  │                 │
│ _lft=4, _rgt=5  │  │ _lft=6, _rgt=7  │  │ _lft=8, _rgt=9  │
│                 │  │                 │  │                 │
│ coverage_       │  │ coverage_       │  │ coverage_       │
│ district=       │  │ district=       │  │ district=       │
│ "Kec. Surakarta"│  │ "Kec. Banjarsari│  │ "Kec. Jebres"   │
└─────────────────┘  └─────────────────┘  └─────────────────┘
```

### Nested Set Query Examples:

**Find all descendants of Kodim 0735 (_lft=3, _rgt=20):**
```sql
SELECT * FROM offices
WHERE _lft > 3 AND _rgt < 20
ORDER BY _lft;
```
Result: All 6 Koramils (nodes with _lft between 4-19)

**Find all ancestors of Koramil 0735/Surakarta (_lft=4, _rgt=5):**
```sql
SELECT * FROM offices
WHERE _lft < 4 AND _rgt > 5
ORDER BY _lft;
```
Result: Kodim 0735 → Korem 074 → Kodam IV (in order)

---

## 3. Role-Office Level Relationship

```
┌──────────────────────────────────────────────────────────────────┐
│                         OFFICE LEVELS                            │
└──────────────────────────────────────────────────────────────────┘

Level 1: Kodam          Level 2: Korem          Level 3: Kodim          Level 4: Koramil
   (NULL)                  (NULL)                  (level_id=3)           (level_id=4)
                                                         │                      │
                                                         │                      │
                                                         │                      │
┌──────────────────────────────────────────────────────────────────────────────┐
│                              ROLES                                           │
└──────────────────────────────────────────────────────────────────────────────┘

┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐
│     Admin       │   │     Viewer      │   │  Kodim Admin    │   │   Reporter      │
│─────────────────│   │─────────────────│   │─────────────────│   │─────────────────│
│ office_level_id │   │ office_level_id │   │ office_level_id │   │ office_level_id │
│ = NULL          │   │ = NULL          │   │ = 3 (Kodim)     │   │ = 4 (Koramil)   │
│                 │   │                 │   │                 │   │                 │
│ permissions:    │   │ permissions:    │   │ permissions:    │   │ permissions:    │
│ ['*']           │   │ ['view_...']    │   │ ['view_...',    │   │ ['view_...',    │
│                 │   │                 │   │  'edit_...',    │   │  'create_...',  │
│ (ALL)           │   │ (READ ONLY)     │   │  'manage_users']│   │  'update_...']  │
└─────────────────┘   └─────────────────┘   └─────────────────┘   └─────────────────┘
     ▲                     ▲                       ▲                       ▲
     │                     │                       │                       │
     │                     │                       │                       │
     └─────────────────────┴───────────────────────┴───────────────────────┘
                                   │
                                   │ role_user (PIVOT)
                                   │ - assigned_by (audit)
                                   │
                            ┌──────▼──────┐
                            │    User     │
                            │─────────────│
                            │ office_id   │
                            │ is_approved │
                            │ approved_by │
                            └─────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    ROLE ASSIGNMENT RULES                        │
└─────────────────────────────────────────────────────────────────┘

✓ Admin at Kodam level     → Can have Admin role (office_level_id=NULL)
✓ User at Kodim level      → Can have Kodim Admin role (office_level_id=3)
✓ User at Koramil level    → Can have Reporter role (office_level_id=4)
✗ User at Koramil level    → CANNOT have Kodim Admin role (mismatch!)
✗ User at Kodim level      → CANNOT have Reporter role (mismatch!)
```

---

## 4. User Registration → Approval → Role Assignment Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          STEP 1: REGISTRATION                               │
└─────────────────────────────────────────────────────────────────────────────┘

User fills form:
├─ Name: "Serka Budi Santoso"
├─ Phone: "081234567890"
├─ NRP: "31050123"
├─ Selects Kodim: "Kodim 0735/Surakarta"
└─ Selects Koramil: "Koramil 0735/Surakarta" (or leaves empty)

                            ▼

┌────────────────────────────────────────────────────────────────┐
│                    User Record Created                         │
│────────────────────────────────────────────────────────────────│
│ name: "Serka Budi Santoso"                                     │
│ phone: "081234567890"                                          │
│ nrp: "31050123"                                                │
│ office_id: 10 (Koramil 0735/Surakarta)                         │
│ is_approved: FALSE                                             │
│ approved_at: NULL                                              │
│ approved_by: NULL                                              │
│ Roles: NONE                                                    │
└────────────────────────────────────────────────────────────────┘

                            ▼

User sees message: "Your account is pending approval"


┌─────────────────────────────────────────────────────────────────────────────┐
│                          STEP 2: APPROVAL                                   │
└─────────────────────────────────────────────────────────────────────────────┘

Admin opens User Management → Sees pending users:

┌────────────────────────────────────────────────────────────────┐
│ Serka Budi Santoso                                             │
│ Office: Koramil 0735/Surakarta                                 │
│ Status: PENDING                                                │
│ [Edit] [Approve] [Reject]                                      │
└────────────────────────────────────────────────────────────────┘

Admin clicks [Approve]

                            ▼

System executes:
1. user.is_approved = true
2. user.approved_at = now()
3. user.approved_by = admin.id

4. Get user's office level:
   Office(10) → level_id=4 (Koramil)

5. Auto-assign role based on level:
   IF level = 4 → Assign "Reporter" role
   IF level = 3 → Assign "Kodim Admin" role

6. Create role_user record:
   ┌────────────────────────────────────┐
   │ role_id: 2 (Reporter)              │
   │ user_id: 5 (Serka Budi)            │
   │ assigned_by: 1 (Admin)             │
   │ created_at: 2025-11-28 10:00:00    │
   └────────────────────────────────────┘

                            ▼

┌────────────────────────────────────────────────────────────────┐
│                    User Record Updated                         │
│────────────────────────────────────────────────────────────────│
│ name: "Serka Budi Santoso"                                     │
│ office_id: 10 (Koramil 0735/Surakarta)                         │
│ is_approved: TRUE ✓                                            │
│ approved_at: 2025-11-28 10:00:00 ✓                             │
│ approved_by: 1 (Admin) ✓                                       │
│ Roles: [Reporter] ✓                                            │
└────────────────────────────────────────────────────────────────┘

User can now login and create projects!


┌─────────────────────────────────────────────────────────────────────────────┐
│                       STEP 3: PERMISSION CHECK                              │
└─────────────────────────────────────────────────────────────────────────────┘

When user tries to create a project:

if (auth()->user()->hasPermission('create_projects')) {
    // Allow
}

Permission check flow:
1. Is user admin? → NO
2. Get user's roles → [Reporter]
3. Get Reporter role permissions → ['view_projects', 'create_projects', ...]
4. Does 'create_projects' exist in permissions? → YES ✓
5. Allow project creation
```

---

## 5. Coverage-Based Location Filtering

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    PROJECT CREATION WORKFLOW                                │
└─────────────────────────────────────────────────────────────────────────────┘

User: Serka Budi (Reporter at Koramil 0735/Surakarta)
Opens: Create Project Form

                            ▼

System auto-populates:
┌────────────────────────────────────────────────────────────────┐
│ Kodim: Kodim 0735/Surakarta (parent of user's office)         │
│ Koramil: Koramil 0735/Surakarta (user's office) ✓             │
│ Location: [Dropdown - filtered by coverage]                   │
└────────────────────────────────────────────────────────────────┘

                            ▼

System queries Koramil coverage:
┌────────────────────────────────────────────────────────────────┐
│ Koramil 0735/Surakarta                                         │
│ coverage_district = "Kec. Surakarta"                           │
└────────────────────────────────────────────────────────────────┘

                            ▼

System filters locations:
┌────────────────────────────────────────────────────────────────┐
│ SELECT * FROM locations                                        │
│ WHERE district_name = 'Kec. Surakarta'                         │
│ ORDER BY village_name                                          │
└────────────────────────────────────────────────────────────────┘

                            ▼

Location dropdown shows ONLY:
┌────────────────────────────────────────────────────────────────┐
│ ✓ Desa Manahan (Kec. Surakarta)                               │
│ ✓ Desa Sangkrah (Kec. Surakarta)                              │
│ ✓ Desa Timuran (Kec. Surakarta)                               │
│ ✓ Desa Punggawan (Kec. Surakarta)                             │
│                                                                │
│ ✗ Desa XYZ (Kec. Banjarsari) ← NOT SHOWN                      │
│ ✗ Desa ABC (Kec. Jebres) ← NOT SHOWN                          │
└────────────────────────────────────────────────────────────────┘

User selects: "Desa Manahan"
User fills: Partner, dates, etc.
User clicks: [Create Project]

                            ▼

System creates:
┌────────────────────────────────────────────────────────────────┐
│                         Project                                │
│────────────────────────────────────────────────────────────────│
│ name: "Koperasi Merah Putih Manahan"                           │
│ office_id: 10 (Koramil 0735/Surakarta)                         │
│ location_id: 25 (Desa Manahan)                                 │
│ partner_id: 3                                                  │
│ status: 'planning'                                             │
└────────────────────────────────────────────────────────────────┘

AND creates project_user pivot:
┌────────────────────────────────────────────────────────────────┐
│ project_id: 15                                                 │
│ user_id: 5 (Serka Budi)                                        │
│ role: 'member'                                                 │
└────────────────────────────────────────────────────────────────┘

✓ Project created in correct coverage area!
✓ User automatically assigned to project!
```

---

## 6. Project Visibility Matrix

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    WHO CAN SEE WHICH PROJECTS?                              │
└─────────────────────────────────────────────────────────────────────────────┘

Office Hierarchy:
Kodam IV
  └─ Korem 074
      └─ Kodim 0735
          ├─ Koramil 0735/Surakarta (has Project A, B, C)
          ├─ Koramil 0735/Banjarsari (has Project D, E)
          └─ Koramil 0735/Jebres (has Project F)
      └─ Kodim 0736
          └─ Koramil 0736/Sragen (has Project G, H)


┌──────────────────────────┬─────────────────────────────────────────────────┐
│     USER TYPE            │           CAN SEE PROJECTS                      │
├──────────────────────────┼─────────────────────────────────────────────────┤
│                          │                                                 │
│ Admin                    │ ALL PROJECTS (A, B, C, D, E, F, G, H)          │
│ (Any office)             │                                                 │
│                          │ Query: Project::all()                           │
│                          │                                                 │
├──────────────────────────┼─────────────────────────────────────────────────┤
│                          │                                                 │
│ Reporter                 │ ONLY ASSIGNED PROJECTS                          │
│ at Koramil 0735/Surakarta│                                                 │
│ Assigned to: A, B        │ Projects A, B                                   │
│                          │                                                 │
│                          │ Query: Project::whereHas('users',              │
│                          │           fn($q) => $q->where('id', $userId))   │
│                          │                                                 │
├──────────────────────────┼─────────────────────────────────────────────────┤
│                          │                                                 │
│ Koramil Admin            │ PROJECTS IN THEIR KORAMIL                       │
│ at Koramil 0735/Surakarta│                                                 │
│                          │ Projects A, B, C                                │
│                          │                                                 │
│                          │ Query: Project::where('office_id',              │
│                          │           $koramilAdminOfficeId)                │
│                          │                                                 │
├──────────────────────────┼─────────────────────────────────────────────────┤
│                          │                                                 │
│ Kodim Admin              │ PROJECTS IN ALL KORAMILS UNDER THEIR KODIM      │
│ at Kodim 0735            │                                                 │
│                          │ Projects A, B, C, D, E, F                       │
│                          │ (All from Koramil 0735/Surakarta,               │
│                          │  0735/Banjarsari, 0735/Jebres)                  │
│                          │                                                 │
│                          │ Query: Project::whereHas('office',              │
│                          │   fn($q) => $q->where('parent_id', $kodimId))   │
│                          │                                                 │
│                          │ ✗ CANNOT see Project G, H (different Kodim)     │
│                          │                                                 │
└──────────────────────────┴─────────────────────────────────────────────────┘
```

---

## 7. User-Project Assignment with Coverage Filtering

```
┌─────────────────────────────────────────────────────────────────────────────┐
│              KODIM ADMIN ASSIGNS PROJECTS TO USERS                          │
└─────────────────────────────────────────────────────────────────────────────┘

Current user: Kapten Andi (Kodim Admin at Kodim 0735)

Opens: User Management
Clicks: "Projects" button on Serka Budi (Reporter at Koramil 0735/Surakarta)

                            ▼

System checks Kodim Admin's coverage:
┌────────────────────────────────────────────────────────────────┐
│ Kapten Andi's office: Kodim 0735 (level=3)                     │
│ Coverage: All Koramils with parent_id = Kodim 0735.id          │
└────────────────────────────────────────────────────────────────┘

                            ▼

System queries available projects:
┌────────────────────────────────────────────────────────────────┐
│ SELECT * FROM projects                                         │
│ WHERE office_id IN (                                           │
│   SELECT id FROM offices WHERE parent_id = 5  -- Kodim 0735    │
│ )                                                              │
└────────────────────────────────────────────────────────────────┘

                            ▼

Modal shows available projects:
┌────────────────────────────────────────────────────────────────┐
│           Assign Projects to Serka Budi                        │
│────────────────────────────────────────────────────────────────│
│ ☐ Project A - Koperasi Manahan (Koramil 0735/Surakarta)       │
│ ☐ Project B - Posyandu Sangkrah (Koramil 0735/Surakarta)      │
│ ☐ Project C - Taman Balekambang (Koramil 0735/Surakarta)      │
│ ☐ Project D - UMKM Batik (Koramil 0735/Banjarsari)            │
│ ☐ Project E - Perpustakaan (Koramil 0735/Banjarsari)          │
│ ☐ Project F - Masjid (Koramil 0735/Jebres)                    │
│                                                                │
│ NOTE: Project G, H NOT shown (different Kodim)                │
│                                                                │
│ [Cancel] [Save]                                                │
└────────────────────────────────────────────────────────────────┘

Kodim Admin selects: A, B, D
Clicks: [Save]

                            ▼

System syncs project_user:
┌────────────────────────────────────────────────────────────────┐
│ DELETE FROM project_user WHERE user_id = 5                     │
│ INSERT INTO project_user VALUES                               │
│   (project_id=1, user_id=5, role='member'),                   │
│   (project_id=2, user_id=5, role='member'),                   │
│   (project_id=4, user_id=5, role='member')                    │
└────────────────────────────────────────────────────────────────┘

✓ Serka Budi now sees Projects A, B, D when logged in!
```

---

## 8. Complete Data Flow: Registration → Project Creation

```
┌───────────────────────────────────────────────────────────────────────────┐
│                                                                           │
│                          COMPLETE WORKFLOW                                │
│                                                                           │
└───────────────────────────────────────────────────────────────────────────┘

STEP 1: USER REGISTERS
┌────────────────────────────────────────────────────────────────┐
│ Serka Budi fills registration form                            │
│ ├─ Name: "Serka Budi Santoso"                                 │
│ ├─ NRP: "31050123"                                            │
│ ├─ Phone: "081234567890"                                      │
│ ├─ Kodim: Kodim 0735/Surakarta                                │
│ └─ Koramil: Koramil 0735/Surakarta                            │
└────────────────────────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│                      User Created                              │
│ is_approved = FALSE, office_id = 10, roles = []               │
└────────────────────────────────────────────────────────────────┘

                            ▼

STEP 2: ADMIN APPROVES
┌────────────────────────────────────────────────────────────────┐
│ Admin sees pending user → Clicks [Approve]                     │
└────────────────────────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│                    User Updated                                │
│ is_approved = TRUE                                             │
│ approved_by = Admin.id                                         │
│ Roles = [Reporter] (auto-assigned)                             │
└────────────────────────────────────────────────────────────────┘

                            ▼

STEP 3: USER LOGS IN & CREATES PROJECT
┌────────────────────────────────────────────────────────────────┐
│ Serka Budi logs in → Goes to Projects → [Create Project]      │
└────────────────────────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│             Form Auto-Populated                                │
│ Kodim: Kodim 0735/Surakarta (from user.office.parent)         │
│ Koramil: Koramil 0735/Surakarta (from user.office)            │
└────────────────────────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│           Location Dropdown Filtered                           │
│ Coverage check: Koramil.coverage_district = "Kec. Surakarta"  │
│ Shows: Only villages in "Kec. Surakarta"                      │
└────────────────────────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│ User fills:                                                    │
│ ├─ Name: "Koperasi Merah Putih Manahan"                       │
│ ├─ Location: Desa Manahan                                     │
│ ├─ Partner: Koperasi ABC                                      │
│ ├─ Dates: 2025-01-01 to 2025-12-31                            │
│ └─ Clicks: [Create]                                           │
└────────────────────────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│                  Project Created                               │
│ office_id = 10 (Koramil 0735/Surakarta)                        │
│ location_id = 25 (Desa Manahan, Kec. Surakarta)               │
│ status = 'planning'                                            │
└────────────────────────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│              project_user Created                              │
│ project_id = 15                                                │
│ user_id = 5 (Serka Budi)                                       │
│ role = 'member'                                                │
└────────────────────────────────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│           Tasks Auto-Cloned from Templates                     │
│ (via ProjectObserver → created event)                          │
│ Creates 36 tasks in 6-level hierarchy                          │
└────────────────────────────────────────────────────────────────┘

                            ▼

STEP 4: VISIBILITY CHECK
┌────────────────────────────────────────────────────────────────┐
│ WHO CAN SEE THIS PROJECT?                                      │
│                                                                │
│ ✓ Serka Budi (Creator, Reporter)                              │
│ ✓ Koramil Admin at Koramil 0735/Surakarta                     │
│ ✓ Kodim Admin at Kodim 0735/Surakarta                         │
│ ✓ Any Admin                                                   │
│                                                                │
│ ✗ Reporter at Koramil 0735/Banjarsari (not assigned)          │
│ ✗ Kodim Admin at Kodim 0736 (different Kodim)                 │
└────────────────────────────────────────────────────────────────┘
```

---

## 9. Permission Matrix

```
┌───────────────────────────────────────────────────────────────────────────┐
│                          PERMISSION MATRIX                                │
└───────────────────────────────────────────────────────────────────────────┘

Permission           │ Admin │ Viewer │ Kodim Admin │ Koramil Admin │ Reporter
─────────────────────┼───────┼────────┼─────────────┼───────────────┼─────────
view_projects        │   ✓   │   ✓    │      ✓      │       ✓       │    ✓
create_projects      │   ✓   │   ✗    │      ✗      │       ✓       │    ✓
edit_projects        │   ✓   │   ✗    │      ✓      │       ✓       │    ✗
delete_projects      │   ✓   │   ✗    │      ✗      │       ✓       │    ✗
                     │       │        │             │               │
view_tasks           │   ✓   │   ✓    │      ✓      │       ✓       │    ✓
edit_tasks           │   ✓   │   ✗    │      ✓      │       ✓       │    ✗
                     │       │        │             │               │
update_progress      │   ✓   │   ✗    │      ✗      │       ✓       │    ✓
                     │       │        │             │               │
view_reports         │   ✓   │   ✓    │      ✓      │       ✗       │    ✗
                     │       │        │             │               │
manage_users         │   ✓   │   ✗    │      ✓      │       ✓       │    ✗
                     │       │        │             │               │
WILDCARD (*)         │   ✓   │   ✗    │      ✗      │       ✗       │    ✗


OFFICE LEVEL         │  ANY  │  ANY   │  Kodim (3)  │  Koramil (4)  │ Koramil (4)
REQUIREMENT          │       │        │             │               │
```

---

## 10. Audit Trail Relationships

```
┌───────────────────────────────────────────────────────────────────────────┐
│                            AUDIT TRAILS                                   │
└───────────────────────────────────────────────────────────────────────────┘

1. USER APPROVAL AUDIT
   ┌─────────────────────────────────────────────────────────┐
   │                        User                             │
   │─────────────────────────────────────────────────────────│
   │ id: 5                                                   │
   │ name: "Serka Budi"                                      │
   │ is_approved: true                                       │
   │ approved_at: 2025-11-28 10:00:00                        │
   │ approved_by: 1 ──────────────────────┐                 │
   └──────────────────────────────────────┼─────────────────┘
                                          │
                                          │ Self-referential
                                          ▼
   ┌─────────────────────────────────────────────────────────┐
   │                        User                             │
   │─────────────────────────────────────────────────────────│
   │ id: 1                                                   │
   │ name: "Admin Utama"                                     │
   │ is_admin: true                                          │
   └─────────────────────────────────────────────────────────┘

   Query: "Who approved Serka Budi?"
   → User::find(5)->approvedBy->name
   → "Admin Utama"


2. ROLE ASSIGNMENT AUDIT
   ┌─────────────────────────────────────────────────────────┐
   │                     role_user                           │
   │─────────────────────────────────────────────────────────│
   │ role_id: 2 (Reporter)                                   │
   │ user_id: 5 (Serka Budi)                                 │
   │ assigned_by: 1 (Admin Utama) ─────────┐                │
   │ created_at: 2025-11-28 10:00:00       │                │
   └───────────────────────────────────────┼─────────────────┘
                                           │
                                           ▼
   ┌─────────────────────────────────────────────────────────┐
   │                        User                             │
   │─────────────────────────────────────────────────────────│
   │ id: 1                                                   │
   │ name: "Admin Utama"                                     │
   └─────────────────────────────────────────────────────────┘

   Query: "Who assigned Reporter role to Serka Budi?"
   → DB::table('role_user')
       ->where('user_id', 5)
       ->where('role_id', 2)
       ->first()->assigned_by
   → User::find(1)->name
   → "Admin Utama"


3. PROJECT ASSIGNMENT TIMELINE
   ┌─────────────────────────────────────────────────────────┐
   │                   project_user                          │
   │─────────────────────────────────────────────────────────│
   │ project_id: 15                                          │
   │ user_id: 5                                              │
   │ role: 'member'                                          │
   │ created_at: 2025-11-28 11:00:00  ← When assigned        │
   │ updated_at: 2025-11-28 11:00:00                         │
   └─────────────────────────────────────────────────────────┘

   Query: "When was Serka Budi assigned to this project?"
   → $project->users()->find(5)->pivot->created_at
   → "2025-11-28 11:00:00"
```

---

This visual guide demonstrates:
- Entity relationships with foreign keys
- 4-level office hierarchy using nested set pattern
- Role-office level enforcement
- Coverage-based location filtering
- Complete user registration → approval → project creation workflow
- Project visibility based on user role and office
- Permission matrix across all roles
- Audit trail tracking for approvals and role assignments

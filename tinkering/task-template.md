 Overview: The 4-Entity Relationship

  TaskTemplate (Master Blueprint)
         │
         │ (cloned automatically)
         ▼
      Project ──► Task (Nested Hierarchy)
         │            │
         │            ▼
         └──────► TaskProgress (Leaf Tasks Only)

  ---
  1. TaskTemplate - The Master Blueprint

  Purpose: Reusable master copy of task hierarchies

  Key Features:
  - Stores a hierarchical template of ALL tasks in a standard project
  - Uses Nested Set Model (_lft, _rgt, parent_id) for tree structure
  - Contains pricing info: volume, unit, price, weight
  - Created once from CSV file (tasks_data.csv) with 6 levels of hierarchy
  - Never modified after seeding - it's the master blueprint

  Example Template Structure:
  FONDASI (_lft:1, _rgt:500)
  ├─ PERSIAPAN (_lft:2, _rgt:100)
  │  └─ Pembersihan Lahan (_lft:3, _rgt:4)
  │     volume: 1000 m², price: 50,000/m²
  ├─ PEKERJAAN TANAH (_lft:101, _rgt:200)
  │  └─ Galian Tanah (_lft:102, _rgt:103)
  ...
  DINDING (_lft:501, _rgt:1000)
  └─ PASANGAN BATU BATA (_lft:502, _rgt:600)
     └─ Pasang Bata Merah (_lft:503, _rgt:504)

  ---
  2. Project - The Container

  Purpose: Represents a single construction project

  Key Attributes:
  - Name: "KOPERASI MERAH PUTIH [Village Name]"
  - Partner (customer): Who is funding the project
  - Location: Where the project is located
  - Office: Which military office oversees it (Kodim/Koramil)
  - Dates: start_date (2025-11-01), end_date (2026-01-31)
  - Status: planning, active, completed, on_hold

  Automatic Task Creation:
  When a project is created, a ProjectObserver triggers:
  ProjectObserver::created($project) {
      TaskTemplateClonerService::cloneTemplatesForProject($project);
  }

  This automatically creates a complete copy of all TaskTemplates as Tasks for this project.

  ---
  3. Task - Project-Specific Work Items

  Purpose: Project-specific clone of TaskTemplates

  Key Features:
  - Every project gets its own complete copy of the task hierarchy
  - Maintains hierarchical structure using Nested Set (_lft, _rgt, parent_id)
  - References original template via template_task_id
  - Contains same data: volume, unit, price, weight
  - Auto-calculates total_price = price × volume

  Hierarchy Depth: Up to 6+ levels
  Level 0: Root Task (e.g., "FONDASI", "DINDING")
  Level 1: Parent Task (e.g., "PERSIAPAN", "PEKERJAAN TANAH")
  Level 2: Sub Parent Task
  Level 3: Child Task
  Level 4: Sub Child Task
  Level 5: Leaf Task (actual work items with progress)

  Example for Project #1:
  Task #100: FONDASI (cloned from Template #1)
  ├─ Task #101: PERSIAPAN (cloned from Template #2)
  │  └─ Task #102: Pembersihan Lahan (cloned from Template #3)
  │     ├─ template_task_id: 3 (links back to original)
  │     ├─ volume: 1000 m²
  │     ├─ price: 50,000/m²
  │     └─ total_price: 50,000,000 (auto-calculated)

  Leaf vs Parent Tasks:
  - Leaf Tasks: No children, can have progress entries
  - Parent Tasks: Have children, progress is aggregated from descendants

  ---
  4. TaskProgress - Progress Tracking

  Purpose: Records percentage completion of leaf tasks over time

  Key Features:
  - Tracks percentage: 0.00 to 100.00
  - Date-specific: progress_date
  - One entry per task per date (unique constraint)
  - Only for LEAF TASKS (tasks without children)
  - Includes notes from reporter

  Data Structure:
  TaskProgress {
      task_id: 102,           // Which leaf task
      project_id: 1,          // Which project
      user_id: 5,             // Who reported it
      percentage: 45.00,      // 45% complete
      progress_date: '2025-11-15',
      notes: 'Pekerjaan sedang berlangsung'
  }

  Unique Constraint:
  UNIQUE (task_id, project_id, progress_date)
  -- Can't create duplicate progress for same task on same day
  -- Must update existing entry instead

  ---
  The Magic: S-Curve Auto-Backfilling

  When a Reporter enters the first progress entry for a task, the system automatically creates historical progress using an S-curve formula:

  Scenario:
  - Project starts: Nov 1, 2025
  - Reporter enters: Nov 15, 2025 → 45% complete

  System Auto-Creates:
  Nov 1:  ~1%  (slow start)
  Nov 7:  ~11% (accelerating)
  Nov 8:  ~22% (mid-curve)
  Nov 9:  ~35% (still growing)
  Nov 10: ~40% (approaching max)
  Nov 14: ~44% (slowing down)
  Nov 15: 45%  (user-entered value)

  S-Curve Formula:
  if ($t <= 0.5) {
      // First half: slow growth (quadratic)
      $progress = 2 * pow($t, 2);
  } else {
      // Second half: fast then slow (inverse quadratic)
      $progress = 1 - 2 * pow(1 - $t, 2);
  }

  This creates realistic progress curves that mirror real construction work:
  - Slow start (mobilization, prep)
  - Fast middle (peak productivity)
  - Slow end (finishing touches)

  ---
  Complete Data Flow Example

  1. System Seeding (One-Time)
  TaskTemplateSeeder runs:
  ├─ Reads tasks_data.csv
  ├─ Creates hierarchical TaskTemplate records
  └─ Builds nested set tree (_lft, _rgt values)

  2. Project Creation
  User creates Project: "KOPERASI MERAH PUTIH Desa Cilacap"
  ├─ partner_id: 1 (PT Agrinas)
  ├─ location_id: 1 (Village in Cilacap)
  ├─ office_id: 1 (Koramil 01)
  ├─ start_date: 2025-11-01
  └─ end_date: 2026-01-31

  ProjectObserver::created() triggers:
  └─ TaskTemplateClonerService clones ALL templates
     ├─ Creates 500+ Task records for this project
     ├─ Preserves parent-child relationships
     ├─ Maintains nested set structure
     └─ Links via template_task_id

  3. Reporter Assignment
  Admin assigns Reporter to Project:
  └─ project_user table: [project_id:1, user_id:5, role:'reporter']

  4. Progress Reporting
  Nov 15, Reporter enters progress for Task #102:
  ├─ percentage: 45%
  ├─ progress_date: 2025-11-15
  └─ notes: "Pembersihan lahan 45% selesai"

  TaskProgressObserver::created() detects first entry:
  ├─ Checks: Is this the first progress for task #102? YES
  ├─ Calculates: Days from Nov 1 to Nov 15 = 14 days
  └─ Auto-creates 14 backfill entries (Nov 1-14) using S-curve
     ├─ Nov 1: 1.22%
     ├─ Nov 7: 11.45%
     ├─ Nov 14: 43.89%
     └─ Nov 15: 45% (user entry - unchanged)

  5. Parent Task Aggregation
  Task #101 (PERSIAPAN) has 5 leaf children:
  ├─ Task #102: 45% (Pembersihan Lahan)
  ├─ Task #103: 60% (Survey Lokasi)
  ├─ Task #104: 30% (Mobilisasi Alat)
  ├─ Task #105: 50% (Pengukuran)
  └─ Task #106: 80% (Pematokan)

  Effective progress for Task #101:
  = (45 + 60 + 30 + 50 + 80) / 5
  = 53% (aggregated from children)

  ---
  Visual Relationship Diagram

  ┌──────────────────┐
  │  TaskTemplate    │ ◄── Master Blueprint (Never Changes)
  │  (Master Copy)   │     - Loaded from CSV once
  └──────────────────┘     - Reused for all projects
           │
           │ Cloning (automatic on project creation)
           │ TaskTemplateClonerService
           ▼
  ┌──────────────────┐
  │     Project      │ ◄── Container for one construction project
  │  id: 1           │     - Name, dates, location, partner
  │  start: 2025-11  │     - Has many Tasks (cloned from templates)
  │  end: 2026-01    │     - Has many TaskProgress entries
  └──────────────────┘     - Belongs to Office, Partner, Location
           │
           ├── Has Many ──► ┌──────────────────┐
           │                │      Task        │ ◄── Project-specific tasks
           │                │  id: 102         │     - Cloned from template
           │                │  template_id: 3  │     - Nested hierarchy
           │                │  parent_id: 101  │     - Volume, price, weight
           │                │  _lft: 3, _rgt:4 │     - Auto-calc total_price
           │                └──────────────────┘
           │                         │
           │                         │ Has Many (if leaf task)
           │                         ▼
           └── Has Many ──► ┌──────────────────┐
                            │  TaskProgress    │ ◄── Daily % completion
                            │  task_id: 102    │     - Only for leaf tasks
                            │  date: 2025-11-15│     - One per task per day
                            │  percentage: 45% │     - Auto-backfilled
                            │  user_id: 5      │     - Notes from reporter
                            └──────────────────┘

  ---
  Key Business Rules

  | Rule                  | Description                                                        |
  |-----------------------|--------------------------------------------------------------------|
  | Template Immutability | TaskTemplates never change after seeding - they're the master copy |
  | Automatic Cloning     | Tasks are auto-created from templates when project is created      |
  | Nested Set Hierarchy  | Both templates and tasks use _lft/_rgt for efficient tree queries  |
  | Leaf Task Progress    | Only tasks without children can have progress entries              |
  | Parent Aggregation    | Parent tasks show average progress of all leaf descendants         |
  | One Entry Per Day     | Unique constraint prevents duplicate progress on same date         |
  | S-Curve Backfill      | First progress entry triggers automatic historical data creation   |
  | Price Calculation     | total_price = price × volume (auto-calculated on save)             |
  | Template Reference    | Each task links back to original template via template_task_id     |

  ---
  Summary

  1. TaskTemplate = Master blueprint (created once from CSV, never modified)
  2. Project = Container for one construction project (has dates, location, partner)
  3. Task = Project-specific clones of templates (hierarchical, with pricing)
  4. TaskProgress = Daily percentage tracking on leaf tasks (with S-curve auto-backfill)

  The system automatically clones the entire task hierarchy when you create a project, then allows reporters to track progress on leaf (actual work) tasks, while parent
  tasks aggregate progress from their children. The S-curve backfilling ensures realistic progress visualization even when reporters enter data sporadically.
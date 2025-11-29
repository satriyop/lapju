<?php

use App\Models\Location;
use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public ?int $selectedProjectId = null;

    // Cascading office filters
    public ?int $selectedKodamId = null;

    public ?int $selectedKoremId = null;

    public ?int $selectedKodimId = null;

    public ?int $selectedKoramilId = null;

    public ?int $selectedLocationId = null;

    public ?string $startDate = null;

    public ?string $endDate = null;

    // Lock filters for Managers to their Kodim coverage
    public bool $filtersLocked = false;

    // Performance optimization: Cache frequently accessed data
    private $cachedFilteredProjects = null;

    private $cachedSettings = null;

    private $cachedOfficeLevels = null;

    private $preloadedTasks = [];

    private $cachedLeafTasks = [];

    private $cachedBatchProgress = null;

    private $cachedHistoricalProgress = null;

    private $cachedLatestProgress = null;

    public function mount(): void
    {
        $currentUser = auth()->user();

        // Reporters don't use office filters - just their assigned projects
        if ($currentUser->hasRole('Reporter')) {
            // No project selected by default - user must select manually
            return;
        }

        // Managers at Kodim level - set defaults to their Kodim and lock filters
        if ($currentUser->office_id) {
            $userOffice = Office::with('level', 'parent.parent')->find($currentUser->office_id);

            if ($userOffice && $userOffice->level->level === 3) {
                // Manager at Kodim level - set their hierarchy as defaults
                $this->selectedKodimId = $userOffice->id;

                if ($userOffice->parent) {
                    $this->selectedKoremId = $userOffice->parent->id;

                    if ($userOffice->parent->parent) {
                        $this->selectedKodamId = $userOffice->parent->parent->id;
                    }
                }

                // Lock filters to prevent changing coverage
                $this->filtersLocked = true;
            }
        }

        // If not a Manager or no office assigned, set default filters to Kodam IV and Korem 074
        if (! $this->selectedKodamId) {
            $kodamIV = Office::whereHas('level', fn ($q) => $q->where('level', 1))
                ->where('name', 'like', '%Kodam IV%')
                ->first();

            $korem074 = Office::whereHas('level', fn ($q) => $q->where('level', 2))
                ->where('name', 'like', '%074%')
                ->first();

            if ($kodamIV) {
                $this->selectedKodamId = $kodamIV->id;
            }

            if ($korem074) {
                $this->selectedKoremId = $korem074->id;
            }
        }
    }

    public function updatedSelectedProjectId(): void
    {
        $this->setProjectDates();
    }

    public function updatedSelectedKodamId(): void
    {
        // Prevent changes if filters are locked
        if ($this->filtersLocked) {
            return;
        }

        // Clear caches when filter changes
        $this->clearCaches();

        // Reset child selections when parent changes
        $this->selectedKoremId = null;
        $this->selectedKodimId = null;
        $this->selectedKoramilId = null;
        $this->selectedProjectId = null;
    }

    public function updatedSelectedKoremId(): void
    {
        // Prevent changes if filters are locked
        if ($this->filtersLocked) {
            return;
        }

        // Clear caches when filter changes
        $this->clearCaches();

        // Reset child selections when parent changes
        $this->selectedKodimId = null;
        $this->selectedKoramilId = null;
        $this->selectedProjectId = null;
    }

    public function updatedSelectedKodimId(): void
    {
        // Prevent changes if filters are locked
        if ($this->filtersLocked) {
            return;
        }

        // Clear caches when filter changes
        $this->clearCaches();

        // Reset child selections when parent changes
        $this->selectedKoramilId = null;
        $this->selectedProjectId = null;
    }

    public function updatedSelectedKoramilId(): void
    {
        // Clear caches when filter changes
        $this->clearCaches();

        // Reset project selection when office filter changes
        $this->selectedProjectId = null;
    }

    public function updatedSelectedLocationId(): void
    {
        // Clear caches when filter changes
        $this->clearCaches();

        // Reset project selection when location filter changes
        $this->selectedProjectId = null;
    }

    private function setProjectDates(): void
    {
        if (! $this->selectedProjectId) {
            $this->startDate = null;
            $this->endDate = null;

            return;
        }

        $project = Project::find($this->selectedProjectId);
        if (! $project) {
            $this->startDate = null;
            $this->endDate = null;

            return;
        }

        // Use project's start and end dates
        if ($project->start_date && $project->end_date) {
            $this->startDate = Carbon::parse($project->start_date)->format('Y-m-d');
            $this->endDate = Carbon::parse($project->end_date)->format('Y-m-d');
        } else {
            // Fallback to actual progress dates
            $dateRange = TaskProgress::where('project_id', $this->selectedProjectId)
                ->selectRaw('MIN(progress_date) as min_date, MAX(progress_date) as max_date')
                ->first();

            if ($dateRange && $dateRange->min_date && $dateRange->max_date) {
                $this->startDate = Carbon::parse($dateRange->min_date)->format('Y-m-d');
                $this->endDate = Carbon::parse($dateRange->max_date)->format('Y-m-d');
            } else {
                $this->startDate = now()->format('Y-m-d');
                $this->endDate = now()->format('Y-m-d');
            }
        }
    }

    public function getFilteredProjects()
    {
        $query = Project::with('location', 'partner', 'office.parent');

        // Reporters can only see their assigned projects
        if (auth()->user()->hasRole('Reporter')) {
            $query->whereHas('users', fn ($q) => $q->where('users.id', auth()->id()));

            return $query->orderBy('name')->get();
        }

        // Managers at Kodim level can only see projects in Koramils under their Kodim
        $currentUser = auth()->user();
        if ($currentUser->hasRole('Manager') && $currentUser->office_id) {
            $userOffice = Office::with('level')->find($currentUser->office_id);
            if ($userOffice && $userOffice->level->level === 3) {
                // Limit to projects in Koramils under this Kodim
                $koramils = Office::where('parent_id', $currentUser->office_id)->pluck('id');
                $query->whereIn('office_id', $koramils);

                // If they've selected a specific Koramil, further filter to that
                if ($this->selectedKoramilId) {
                    $query->where('office_id', $this->selectedKoramilId);
                }

                // Location filtering still applies
                if ($this->selectedLocationId) {
                    $query->where('location_id', $this->selectedLocationId);
                }

                return $query->orderBy('name')->get();
            }
        }

        // Filter by the lowest selected office level (for non-Managers)
        if ($this->selectedKoramilId) {
            // Filter by specific Koramil
            $query->where('office_id', $this->selectedKoramilId);
        } elseif ($this->selectedKodimId) {
            // Filter by all Koramils under this Kodim
            $koramils = Office::where('parent_id', $this->selectedKodimId)->pluck('id');
            $query->whereIn('office_id', $koramils);
        } elseif ($this->selectedKoremId) {
            // Filter by all Kodims and their Koramils under this Korem
            $kodims = Office::where('parent_id', $this->selectedKoremId)->pluck('id');
            $koramils = Office::whereIn('parent_id', $kodims)->pluck('id');
            $query->whereIn('office_id', $koramils);
        } elseif ($this->selectedKodamId) {
            // Filter by all offices under this Kodam
            $korems = Office::where('parent_id', $this->selectedKodamId)->pluck('id');
            $kodims = Office::whereIn('parent_id', $korems)->pluck('id');
            $koramils = Office::whereIn('parent_id', $kodims)->pluck('id');
            $query->whereIn('office_id', $koramils);
        }

        // Filter by location if selected
        if ($this->selectedLocationId) {
            $query->where('location_id', $this->selectedLocationId);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Get filtered projects with caching to avoid repeated queries.
     * Cache is cleared when any filter changes.
     */
    private function getCachedFilteredProjects()
    {
        if ($this->cachedFilteredProjects === null) {
            $this->cachedFilteredProjects = $this->getFilteredProjects();
        }

        return $this->cachedFilteredProjects;
    }

    /**
     * Clear all caches when filters change
     */
    private function clearCaches(): void
    {
        $this->cachedFilteredProjects = null;
        $this->preloadedTasks = [];
        $this->cachedLeafTasks = [];
        $this->cachedBatchProgress = null;
        $this->cachedAllProgress = null;
        $this->cachedProjectProgress = null; // Clear project progress cache too
    }

    /**
     * Get cached project default dates from settings
     * OPTIMIZED: Use Laravel's persistent cache (5 minutes) instead of per-request cache
     */
    private function getCachedSettings(): array
    {
        if ($this->cachedSettings === null) {
            $this->cachedSettings = cache()->remember('dashboard_settings', 300, function () {
                return [
                    'start_date' => Setting::get('project.default_start_date', '2025-11-01'),
                    'end_date' => Setting::get('project.default_end_date', '2026-01-31'),
                ];
            });
        }

        return $this->cachedSettings;
    }

    /**
     * Get cached office levels
     * OPTIMIZED: Use Laravel's persistent cache (1 hour) - office levels rarely change
     */
    private function getCachedOfficeLevels(): object
    {
        if ($this->cachedOfficeLevels === null) {
            $this->cachedOfficeLevels = cache()->remember('dashboard_office_levels', 3600, function () {
                $levels = OfficeLevel::all()->keyBy('level');

                return (object) [
                    'kodam' => $levels->get(1),
                    'korem' => $levels->get(2),
                    'kodim' => $levels->get(3),
                    'koramil' => $levels->get(4),
                ];
            });
        }

        return $this->cachedOfficeLevels;
    }

    /**
     * Batch load latest task progress for multiple projects up to a date
     * Optimized to avoid correlated subqueries that cause thousands of duplicates
     */
    private function batchLoadTaskProgress($projectIds, $upToDate = null): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $upToDate = $upToDate ?? now()->format('Y-m-d');

        // OPTIMIZED: Use joinSub with proper parameter binding (prevents SQL injection)
        $latestDates = DB::table('task_progress')
            ->select('task_id', 'project_id', DB::raw('MAX(progress_date) as max_date'))
            ->whereIn('project_id', $projectIds)
            ->where('progress_date', '<=', $upToDate)
            ->groupBy('task_id', 'project_id');

        $latestProgress = DB::table('task_progress as tp1')
            ->select('tp1.task_id', 'tp1.project_id', 'tp1.percentage', 'tp1.progress_date')
            ->joinSub($latestDates, 'latest', function ($join) {
                $join->on('tp1.task_id', '=', 'latest.task_id')
                    ->on('tp1.project_id', '=', 'latest.project_id')
                    ->on('tp1.progress_date', '=', 'latest.max_date');
            })
            ->get()
            ->keyBy(fn ($item) => "{$item->project_id}_{$item->task_id}");

        return $latestProgress->all();
    }

    private $cachedAllProgress = null;

    /**
     * Load progress records for filtered projects within relevant date range (for S-curve historical lookups)
     * This enables in-memory filtering by date instead of per-date queries
     * OPTIMIZED: Only loads progress within organization date range to reduce memory usage
     */
    private function loadAllProgressData(array $projectIds): array
    {
        if ($this->cachedAllProgress !== null) {
            return $this->cachedAllProgress;
        }

        if (empty($projectIds)) {
            $this->cachedAllProgress = [];

            return [];
        }

        // OPTIMIZED: For single project view, reuse getCachedHistoricalProgress() data to avoid duplicate query
        if (count($projectIds) === 1 && $projectIds[0] == $this->selectedProjectId && $this->cachedHistoricalProgress !== null) {
            // Transform the cached historical progress data to the grouped array format expected by getProgressAtDate()
            $grouped = [];
            foreach ($this->cachedHistoricalProgress as $progress) {
                $key = "{$progress->project_id}_{$progress->task_id}";
                if (! isset($grouped[$key])) {
                    $grouped[$key] = [];
                }
                $grouped[$key][] = $progress;
            }
            $this->cachedAllProgress = $grouped;

            return $this->cachedAllProgress;
        }

        // Only load progress within the organization's date range (not ALL history)
        $settings = $this->getCachedSettings();
        $startDate = $settings['start_date'];
        $endDate = $settings['end_date'];

        // OPTIMIZED: Use nested set columns - leaf nodes have _rgt = _lft + 1 (no correlated subquery!)
        $allProgress = TaskProgress::whereIn('task_progress.project_id', $projectIds)
            ->whereBetween('task_progress.progress_date', [$startDate, $endDate])
            ->join('tasks', 'task_progress.task_id', '=', 'tasks.id')
            ->whereRaw('tasks._rgt = tasks._lft + 1')
            ->select('task_progress.task_id', 'task_progress.project_id', 'task_progress.percentage', 'task_progress.progress_date')
            ->orderBy('task_progress.progress_date', 'desc')
            ->get();

        // Group by project_id_task_id for efficient lookup
        // Each key contains all progress records for that task, sorted by date desc
        $grouped = [];
        foreach ($allProgress as $progress) {
            $key = "{$progress->project_id}_{$progress->task_id}";
            if (! isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $progress;
        }

        $this->cachedAllProgress = $grouped;

        return $this->cachedAllProgress;
    }

    /**
     * Get latest progress for a task up to a specific date from pre-loaded data
     */
    private function getProgressAtDate(array $allProgress, int $projectId, int $taskId, string $date): ?object
    {
        $key = "{$projectId}_{$taskId}";

        if (! isset($allProgress[$key])) {
            return null;
        }

        // FIXED: Find the latest progress on or before the given date
        // Data is sorted DESC (newest first), so we iterate BACKWARDS to find the
        // latest progress that is <= the target date
        $progressRecords = $allProgress[$key];
        for ($i = count($progressRecords) - 1; $i >= 0; $i--) {
            // Ensure both dates are strings for proper comparison
            $progressDate = is_string($progressRecords[$i]->progress_date)
                ? $progressRecords[$i]->progress_date
                : $progressRecords[$i]->progress_date->format('Y-m-d');

            if ($progressDate <= $date) {
                return $progressRecords[$i];
            }
        }

        return null;
    }

    /**
     * Pre-load all tasks for a project with full parent hierarchy
     * This eliminates N+1 queries when traversing task parents
     * OPTIMIZED: Load all tasks once, then traverse in memory (no duplicate parent loading)
     */
    private function preloadProjectTasks($projectId)
    {
        if (! isset($this->preloadedTasks[$projectId])) {
            // Load ALL tasks once without eager loading parents (to avoid duplicates)
            // Then we can traverse parent_id in memory using the keyed array
            $this->preloadedTasks[$projectId] = Task::where('project_id', $projectId)
                ->select('id', 'project_id', 'parent_id', 'name', 'weight', '_lft')
                ->get()
                ->keyBy('id');
        }

        return $this->preloadedTasks[$projectId];
    }

    /**
     * Get cached leaf tasks for a project
     * Eliminates repeated whereDoesntHave('children') queries
     */
    private function getCachedLeafTasks($projectId)
    {
        if (! isset($this->cachedLeafTasks[$projectId])) {
            // OPTIMIZED: Use nested set columns - leaf nodes have _rgt = _lft + 1 (no subquery needed!)
            // Includes name, parent_id, _lft for task display and breadcrumb generation
            $this->cachedLeafTasks[$projectId] = Task::select('id', 'project_id', 'name', 'weight', '_lft', '_rgt', 'parent_id')
                ->where('project_id', $projectId)
                ->whereRaw('_rgt = _lft + 1')
                ->get();
        }

        return $this->cachedLeafTasks[$projectId];
    }

    /**
     * Get cached batch progress for all filtered projects
     * Eliminates multiple batchLoadTaskProgress() calls
     */
    private function getCachedBatchProgress(): array
    {
        if ($this->cachedBatchProgress === null) {
            $projectIds = $this->getCachedFilteredProjects()->pluck('id')->toArray();
            $this->cachedBatchProgress = $this->batchLoadTaskProgress($projectIds);
        }

        return $this->cachedBatchProgress;
    }

    /**
     * Get cached HISTORICAL TaskProgress for selected project (for S-curve calculations)
     * OPTIMIZED: Load historical progress data across organization-wide date range
     * NOTE: For task breakdown display, use getCachedLatestProgress() instead
     */
    private function getCachedHistoricalProgress()
    {
        if ($this->cachedHistoricalProgress === null && $this->selectedProjectId) {
            // Load ALL historical progress within organization date range (needed for S-curve)
            // This ensures consistent data loading across all calculations
            $settings = $this->getCachedSettings();
            $startDate = $settings['start_date'];
            $endDate = $settings['end_date'];

            // OPTIMIZED: Use nested set columns - leaf nodes have _rgt = _lft + 1 (no subquery needed!)
            $this->cachedHistoricalProgress = TaskProgress::where('task_progress.project_id', $this->selectedProjectId)
                ->whereBetween('task_progress.progress_date', [$startDate, $endDate])
                ->join('tasks', 'task_progress.task_id', '=', 'tasks.id')
                ->whereRaw('tasks._rgt = tasks._lft + 1')
                ->select('task_progress.id', 'task_progress.task_id', 'task_progress.project_id', 'task_progress.percentage', 'task_progress.progress_date', 'task_progress.notes')
                ->with(['task' => function ($query) {
                    $query->select('id', 'project_id', 'parent_id', 'name', 'weight');
                }])
                ->get();
        }

        return $this->cachedHistoricalProgress ?? collect();
    }

    /**
     * Get cached LATEST TaskProgress for selected project (for task breakdown display)
     * OPTIMIZED: Load only the most recent progress entry per leaf task
     * This dramatically reduces data from ~16,572 records to ~213 records (one per task)
     */
    private function getCachedLatestProgress()
    {
        if ($this->cachedLatestProgress === null && $this->selectedProjectId) {
            // OPTIMIZED: Load only the latest progress entry for each leaf task
            // Step 1: Get the latest progress ID for each task using a subquery
            $latestProgressIds = DB::table('task_progress as tp')
                ->select(DB::raw('MAX(tp.id) as latest_id'))
                ->where('tp.project_id', $this->selectedProjectId)
                ->join('tasks', 'tp.task_id', '=', 'tasks.id')
                ->whereRaw('tasks._rgt = tasks._lft + 1') // Leaf tasks only
                ->groupBy('tp.task_id')
                ->pluck('latest_id');

            // Step 2: Load only those latest progress records with eager loaded tasks
            $this->cachedLatestProgress = TaskProgress::whereIn('id', $latestProgressIds)
                ->select('id', 'task_id', 'project_id', 'percentage', 'progress_date', 'notes')
                ->with(['task' => function ($query) {
                    $query->select('id', 'project_id', 'parent_id', 'name', 'weight');
                }])
                ->get();
        }

        return $this->cachedLatestProgress ?? collect();
    }

    /**
     * Batch load all leaf tasks for multiple projects in ONE query
     * Eliminates N queries for N projects
     */
    private function batchLoadAllLeafTasks(array $projectIds): void
    {
        if (empty($projectIds)) {
            return;
        }

        // FIXED: Only skip if we already have ALL required projects cached
        // Check which projects are missing from cache
        $missingProjectIds = array_diff($projectIds, array_keys($this->cachedLeafTasks));

        if (empty($missingProjectIds)) {
            // All projects already cached, nothing to do
            return;
        }

        // Load only the missing projects (or all if cache is empty)
        // OPTIMIZED: Use nested set columns - leaf nodes have _rgt = _lft + 1 (no subquery needed!)
        // Includes name, parent_id, _lft for task display and breadcrumb generation
        $allLeafTasks = Task::select('id', 'project_id', 'name', 'weight', '_lft', '_rgt', 'parent_id')
            ->whereIn('project_id', $missingProjectIds)
            ->whereRaw('_rgt = _lft + 1')
            ->get()
            ->groupBy('project_id');

        foreach ($allLeafTasks as $projectId => $tasks) {
            $this->cachedLeafTasks[$projectId] = $tasks;
        }
    }

    public function getFilteredLocations()
    {
        // OPTIMIZED: Only select columns needed for the dropdown to reduce memory usage
        // OPTIMIZED: Only load locations that are actually used by projects (not all 1,561!)
        // OPTIMIZED: Use subquery instead of loading all projects into memory
        $query = Location::select('id', 'village_name', 'district_name', 'city_name')
            ->whereIn('id', function ($subquery) {
                $subquery->select('location_id')
                    ->from('projects')
                    ->whereNotNull('location_id')
                    ->distinct();
            });

        // Reporters can only see locations from their assigned projects
        if (auth()->user()->hasRole('Reporter')) {
            $locationIds = auth()->user()->projects()->pluck('location_id')->filter();
            $query->whereIn('id', $locationIds);

            return $query->orderBy('city_name')->orderBy('village_name')->get();
        }

        // Managers at Kodim level - filter by their Kodim's coverage
        $currentUser = auth()->user();
        if ($currentUser->hasRole('Manager') && $currentUser->office_id) {
            $userOffice = Office::with('level')->find($currentUser->office_id);
            if ($userOffice && $userOffice->level->level === 3) {
                // Get all Koramils under this Kodim
                $koramils = Office::select('id', 'coverage_district', 'coverage_city')
                    ->where('parent_id', $currentUser->office_id)
                    ->get();

                // Collect all coverage areas
                $districts = $koramils->pluck('coverage_district')->filter();
                $cities = $koramils->pluck('coverage_city')->filter();

                $query->where(function ($q) use ($districts, $cities) {
                    if ($districts->isNotEmpty()) {
                        $q->orWhereIn('district_name', $districts);
                    }
                    if ($cities->isNotEmpty()) {
                        $q->orWhereIn('city_name', $cities);
                    }
                });

                return $query->orderBy('city_name')->orderBy('village_name')->get();
            }
        }

        // Only filter locations when Kodim or Koramil is selected
        // (projects are assigned to Koramils, so location filter should match)
        $selectedOffice = null;

        if ($this->selectedKoramilId) {
            $selectedOffice = Office::with('level')->find($this->selectedKoramilId);
        } elseif ($this->selectedKodimId) {
            $selectedOffice = Office::with('level')->find($this->selectedKodimId);
        }

        // Filter locations based on office level and coverage
        if ($selectedOffice) {
            $level = $selectedOffice->level->level;

            // Level 4 (Koramil) - filter by district if available, otherwise by city
            if ($level == 4) {
                if ($selectedOffice->coverage_district) {
                    $query->where('district_name', $selectedOffice->coverage_district);
                } elseif ($selectedOffice->coverage_city) {
                    $query->where('city_name', $selectedOffice->coverage_city);
                }
            }
            // Level 3 (Kodim) - filter by city
            elseif ($level == 3 && $selectedOffice->coverage_city) {
                $query->where('city_name', $selectedOffice->coverage_city);
            }
        }

        return $query->orderBy('city_name')->orderBy('village_name')->get();
    }

    public function calculateSCurveData(): array
    {
        // Only calculate S-curve when a project is selected
        if (! $this->selectedProjectId) {
            return ['labels' => [], 'planned' => [], 'actual' => []];
        }

        return $this->calculateSingleProjectSCurve();
    }

    private function calculateSingleProjectSCurve(): array
    {
        // Get the selected project's planned dates
        $project = Project::find($this->selectedProjectId);

        if (! $project || ! $project->start_date || ! $project->end_date) {
            // Fallback to actual progress dates if project dates not set
            $dateRange = TaskProgress::where('project_id', $this->selectedProjectId)
                ->selectRaw('MIN(progress_date) as min_date, MAX(progress_date) as max_date')
                ->first();

            if (! $dateRange || ! $dateRange->min_date || ! $dateRange->max_date) {
                return ['labels' => [], 'planned' => [], 'actual' => [], 'delayDays' => 0];
            }

            $organizationStartDate = Carbon::parse($dateRange->min_date);
            $projectStartDate = Carbon::parse($dateRange->min_date);
            $projectEndDate = Carbon::parse($dateRange->max_date);
        } else {
            // Use organizational default as timeline baseline
            $settings = $this->getCachedSettings();
            $organizationStartDate = Carbon::parse($settings['start_date']);
            $projectStartDate = Carbon::parse($project->start_date); // Actual project start
            $projectEndDate = Carbon::parse($project->end_date);
        }

        // Calculate total days from organizational start to project end
        $totalDays = $organizationStartDate->diffInDays($projectEndDate);

        if ($totalDays <= 0) {
            return ['labels' => [], 'planned' => [], 'actual' => [], 'delayDays' => 0];
        }

        // Calculate planned S-curve (linear distribution based on project timeline)
        $labels = [];
        $plannedData = [];
        $actualData = [];

        // Determine interval based on duration
        $intervalDays = $totalDays <= 30 ? 1 : ($totalDays <= 90 ? 7 : 14);

        // Start X-axis from organizational default, not project start
        $currentDate = $organizationStartDate->copy();

        while ($currentDate <= $projectEndDate) {
            // Calculate planned progress from organizational start
            $daysPassed = $organizationStartDate->diffInDays($currentDate);
            $labels[] = $currentDate->format('M d');

            // Planned: Linear distribution (percentage of time passed based on ORGANIZATIONAL timeline)
            $plannedPercentage = min(($daysPassed / $totalDays) * 100, 100);
            $plannedData[] = round($plannedPercentage, 2);

            // Actual: Show 0% before project actual start date
            if ($currentDate->lt($projectStartDate)) {
                $actualData[] = 0;
            } else {
                // After project start - calculate real progress
                $actualPercentage = $this->calculateActualProgress($currentDate);
                $actualData[] = round($actualPercentage, 2);
            }

            $currentDate->addDays($intervalDays);
        }

        // Ensure we have the end date at 100% planned progress
        // FIXED: Use copy() to avoid mutating $currentDate, and use <= to include end date
        $lastIterationDate = $currentDate->copy()->subDays($intervalDays);
        if ($lastIterationDate <= $projectEndDate) {
            $labels[] = $projectEndDate->format('M d');
            $plannedData[] = 100;

            if ($projectEndDate->lt($projectStartDate)) {
                $actualData[] = 0;
            } else {
                $actualData[] = round($this->calculateActualProgress($projectEndDate), 2);
            }
        }

        // Calculate delay for UI display
        $delayDays = $organizationStartDate->diffInDays($projectStartDate);

        return [
            'labels' => $labels,
            'planned' => $plannedData,
            'actual' => $actualData,
            'delayDays' => max(0, $delayDays),
            'actualStartDate' => $projectStartDate->format('M d, Y'),
            'plannedStartDate' => $organizationStartDate->format('M d, Y'),
        ];
    }

    private function calculateActualProgress(Carbon $date): float
    {
        // OPTIMIZATION: Use cached leaf tasks instead of querying every time
        $leafTasks = $this->getCachedLeafTasks($this->selectedProjectId);

        if ($leafTasks->isEmpty()) {
            return 0;
        }

        $totalWeightedProgress = 0;
        $totalWeight = $leafTasks->sum('weight');

        if ($totalWeight == 0) {
            return 0;
        }

        // Use cached all progress data for in-memory lookup
        $allProgress = $this->loadAllProgressData([$this->selectedProjectId]);
        $dateStr = $date->format('Y-m-d');

        foreach ($leafTasks as $task) {
            // Use in-memory lookup instead of database query
            $latestProgress = $this->getProgressAtDate($allProgress, $this->selectedProjectId, $task->id, $dateStr);

            if ($latestProgress) {
                // Weighted progress = (task percentage * task weight) / total weight
                $taskPercentage = min((float) $latestProgress->percentage, 100);
                $totalWeightedProgress += ($taskPercentage * $task->weight) / $totalWeight;
            }
        }

        return $totalWeightedProgress;
    }

    private function calculateProjectActualProgress(Project $project, $preloadedProgress = null): float
    {
        // OPTIMIZATION: Use cached leaf tasks instead of querying every time
        $leafTasks = $this->getCachedLeafTasks($project->id);

        if ($leafTasks->isEmpty()) {
            return 0;
        }

        $totalWeightedProgress = 0;
        $totalWeight = $leafTasks->sum('weight');

        if ($totalWeight == 0) {
            return 0;
        }

        foreach ($leafTasks as $task) {
            // Use pre-loaded progress - NO FALLBACK QUERY (tasks without progress = 0%)
            $progressKey = "{$project->id}_{$task->id}";
            $latestProgress = $preloadedProgress[$progressKey] ?? null;

            if ($latestProgress) {
                // Weighted progress = (task percentage * task weight) / total weight
                $taskPercentage = min((float) $latestProgress->percentage, 100);
                $totalWeightedProgress += ($taskPercentage * $task->weight) / $totalWeight;
            }
            // Tasks without progress contribute 0% (no query needed)
        }

        return $totalWeightedProgress;
    }

    private function getTop10Projects(): array
    {
        // Get filtered projects based on user role and selected filters
        $projects = $this->getCachedFilteredProjects();

        // OPTIMIZATION: Use cached batch progress instead of loading fresh
        $preloadedProgress = $this->getCachedBatchProgress();

        // Calculate progress for each project and prepare data
        $projectsWithProgress = [];

        foreach ($projects as $project) {
            $progress = $this->calculateProjectActualProgress($project, $preloadedProgress);

            // Only include projects with progress > 0
            if ($progress > 0) {
                $projectsWithProgress[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'progress' => $progress,
                    'location_village' => $project->location->village_name ?? '-',
                    'location_district' => $project->location->district_name ?? '-',
                    'office_name' => $project->office->name ?? null,
                    'kodim_name' => $project->office?->parent?->name ?? null,
                ];
            }
        }

        // Sort by progress descending and take top 10
        usort($projectsWithProgress, fn ($a, $b) => $b['progress'] <=> $a['progress']);

        return array_slice($projectsWithProgress, 0, 10);
    }

    /**
     * Calculate aggregated overall progress using pre-loaded data
     */
    private function calculateAggregatedOverallProgressWithData($projects, array $preloadedProgress): float
    {
        if ($projects->isEmpty()) {
            return 0;
        }

        $totalProgress = 0;

        foreach ($projects as $project) {
            $totalProgress += $this->calculateProjectActualProgress($project, $preloadedProgress);
        }

        return round($totalProgress / $projects->count(), 2);
    }

    /**
     * Calculate projects on track count using pre-loaded data
     */
    private function calculateProjectsOnTrackWithData($projects, array $preloadedProgress): int
    {
        if ($projects->isEmpty()) {
            return 0;
        }

        $settings = $this->getCachedSettings();
        $organizationStartDate = Carbon::parse($settings['start_date']);
        $organizationEndDate = Carbon::parse($settings['end_date']);
        $totalDays = $organizationStartDate->diffInDays($organizationEndDate);
        $today = Carbon::now();
        $daysPassed = $organizationStartDate->diffInDays($today);
        $plannedPercentage = min(($daysPassed / $totalDays) * 100, 100);

        $onTrackCount = 0;

        foreach ($projects as $project) {
            $actualProgress = $this->calculateProjectActualProgress($project, $preloadedProgress);
            if ($actualProgress >= $plannedPercentage) {
                $onTrackCount++;
            }
        }

        return $onTrackCount;
    }

    /**
     * Calculate projects behind schedule count using pre-loaded data
     */
    private function calculateProjectsBehindWithData($projects, array $preloadedProgress): int
    {
        if ($projects->isEmpty()) {
            return 0;
        }

        $settings = $this->getCachedSettings();
        $organizationStartDate = Carbon::parse($settings['start_date']);
        $organizationEndDate = Carbon::parse($settings['end_date']);
        $totalDays = $organizationStartDate->diffInDays($organizationEndDate);
        $today = Carbon::now();
        $daysPassed = $organizationStartDate->diffInDays($today);
        $plannedPercentage = min(($daysPassed / $totalDays) * 100, 100);

        $behindCount = 0;

        foreach ($projects as $project) {
            $actualProgress = $this->calculateProjectActualProgress($project, $preloadedProgress);
            if ($actualProgress < $plannedPercentage) {
                $behindCount++;
            }
        }

        return $behindCount;
    }

    /**
     * Calculate average delay days
     */
    private function calculateAverageDelayDays($projects): int
    {
        if ($projects->isEmpty()) {
            return 0;
        }

        $totalDelay = 0;
        $projectsWithDelay = 0;

        $settings = $this->getCachedSettings();
        $organizationStartDate = Carbon::parse($settings['start_date']);

        foreach ($projects as $project) {
            if ($project->start_date) {
                $actualStartDate = Carbon::parse($project->start_date);
                $delay = $organizationStartDate->diffInDays($actualStartDate);
                if ($delay > 0) {
                    $totalDelay += $delay;
                    $projectsWithDelay++;
                }
            }
        }

        return $projectsWithDelay > 0 ? round($totalDelay / $projectsWithDelay) : 0;
    }

    /**
     * Calculate progress distribution using pre-loaded data
     */
    private function calculateProgressDistributionWithData($projects, array $preloadedProgress): array
    {
        $distribution = [
            '0-25' => 0,
            '25-50' => 0,
            '50-75' => 0,
            '75-100' => 0,
        ];

        foreach ($projects as $project) {
            $progress = $this->calculateProjectActualProgress($project, $preloadedProgress);

            if ($progress < 25) {
                $distribution['0-25']++;
            } elseif ($progress < 50) {
                $distribution['25-50']++;
            } elseif ($progress < 75) {
                $distribution['50-75']++;
            } else {
                $distribution['75-100']++;
            }
        }

        return $distribution;
    }

    public function with(): array
    {
        $projects = $this->getCachedFilteredProjects();
        $projectIds = $projects->pluck('id')->toArray();

        // PRE-LOAD ALL CACHES ONCE to eliminate N+1 queries
        // OPTIMIZED: Only batch load when viewing ALL projects (aggregated view)
        // When a specific project is selected, load only that project's data
        if (empty($this->selectedProjectId)) {
            // Aggregated view: load data for all filtered projects
            $this->batchLoadAllLeafTasks($projectIds);
            $this->getCachedBatchProgress();
        } else {
            // Single project view: load data ONCE for each purpose
            $this->batchLoadAllLeafTasks([$this->selectedProjectId]);
            $this->getCachedLatestProgress(); // OPTIMIZED: Latest progress for task breakdown (~213 records)
            $this->getCachedHistoricalProgress(); // Historical progress for S-curve (~16,572 records when needed)
            $this->loadAllProgressData([$this->selectedProjectId]); // Transform historical data for S-curve lookups
        }

        $locations = $this->getFilteredLocations();

        // Get office levels
        $levels = $this->getCachedOfficeLevels();
        $kodamLevel = $levels->kodam;
        $koremLevel = $levels->korem;
        $kodimLevel = $levels->kodim;
        $koramilLevel = $levels->koramil;

        // Cascading office filters
        // OPTIMIZED: Only select columns needed for dropdowns to reduce memory usage
        $kodams = $kodamLevel ? Office::select('id', 'name')->where('level_id', $kodamLevel->id)->orderBy('name')->get() : collect();

        $korems = $koremLevel && $this->selectedKodamId
            ? Office::select('id', 'name')->where('level_id', $koremLevel->id)->where('parent_id', $this->selectedKodamId)->orderBy('name')->get()
            : collect();

        $kodims = $kodimLevel && $this->selectedKoremId
            ? Office::select('offices.id', 'offices.name')
                ->where('offices.level_id', $kodimLevel->id)
                ->where('offices.parent_id', $this->selectedKoremId)
                ->leftJoin('offices as koramils', 'koramils.parent_id', '=', 'offices.id')
                ->leftJoin('projects', 'projects.office_id', '=', 'koramils.id')
                ->groupBy('offices.id', 'offices.name')
                ->selectRaw('COUNT(DISTINCT projects.id) as projects_count')
                ->orderBy('offices.name')
                ->get()
            : collect();

        $koramils = $koramilLevel && $this->selectedKodimId
            ? Office::select('id', 'name')
                ->where('level_id', $koramilLevel->id)
                ->where('parent_id', $this->selectedKodimId)
                ->withCount('projects')
                ->orderBy('name')
                ->get()
            : collect();

        // Calculate statistics based on filtered data (always visible)
        $totalProjects = $projects->count();

        // Active projects: filtered projects that have progress records
        // OPTIMIZED: Use whereExists instead of whereHas for better performance
        $activeProjects = Project::whereIn('id', $projectIds)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('task_progress')
                    ->whereColumn('task_progress.project_id', 'projects.id');
            })
            ->count();

        $totalLocations = $locations->count();

        // Reporters: users assigned to filtered projects
        // OPTIMIZED: Use JOINs instead of double whereHas for better performance
        $totalReporters = User::join('project_user', 'users.id', '=', 'project_user.user_id')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->whereIn('project_user.project_id', $projectIds)
            ->where('roles.name', 'Reporter')
            ->distinct()
            ->count('users.id');

        $stats = [
            'total_projects' => $totalProjects,
            'active_projects' => $activeProjects,
            'total_locations' => $totalLocations,
            'total_reporters' => $totalReporters,
        ];

        // PRE-COMPUTE all statistics using cached data (eliminates Blade method calls)
        // OPTIMIZED: Only compute aggregated stats when viewing all projects
        if (empty($this->selectedProjectId)) {
            $preloadedProgress = $this->getCachedBatchProgress();
            $aggregatedOverallProgress = $this->calculateAggregatedOverallProgressWithData($projects, $preloadedProgress);
            $projectsOnTrackCount = $this->calculateProjectsOnTrackWithData($projects, $preloadedProgress);
            $projectsBehindCount = $this->calculateProjectsBehindWithData($projects, $preloadedProgress);
            $averageDelayDays = $this->calculateAverageDelayDays($projects);
            $progressDistribution = $this->calculateProgressDistributionWithData($projects, $preloadedProgress);
            $top10Projects = $this->getTop10Projects();
        } else {
            // When project selected, set empty defaults for aggregated stats
            $aggregatedOverallProgress = 0;
            $projectsOnTrackCount = 0;
            $projectsBehindCount = 0;
            $averageDelayDays = 0;
            $progressDistribution = [];
            $top10Projects = [];
        }

        // Calculate S-curve data (works for both single project and aggregated views)
        $sCurveData = $this->calculateSCurveData();

        // If no project selected, return early with aggregated data
        if (! $this->selectedProjectId) {
            return [
                'projects' => $projects,
                'kodams' => $kodams,
                'korems' => $korems,
                'kodims' => $kodims,
                'koramils' => $koramils,
                'locations' => $locations,
                'stats' => $stats,
                'taskProgress' => collect(),
                'taskProgressByRoot' => [],
                'sCurveData' => $sCurveData,
                'tasksWithoutProgress' => [],
                'top10Projects' => $top10Projects,
                'aggregatedOverallProgress' => $aggregatedOverallProgress,
                'projectsOnTrackCount' => $projectsOnTrackCount,
                'projectsBehindCount' => $projectsBehindCount,
                'averageDelayDays' => $averageDelayDays,
                'progressDistribution' => $progressDistribution,
            ];
        }

        // If project selected but no dates set, return early
        if (! $this->startDate || ! $this->endDate) {
            return [
                'projects' => $projects,
                'kodams' => $kodams,
                'korems' => $korems,
                'kodims' => $kodims,
                'koramils' => $koramils,
                'locations' => $locations,
                'stats' => $stats,
                'taskProgress' => collect(),
                'taskProgressByRoot' => [],
                'sCurveData' => $sCurveData,
                'tasksWithoutProgress' => [],
                'top10Projects' => $top10Projects,
                'aggregatedOverallProgress' => $aggregatedOverallProgress,
                'projectsOnTrackCount' => $projectsOnTrackCount,
                'projectsBehindCount' => $projectsBehindCount,
                'averageDelayDays' => $averageDelayDays,
                'progressDistribution' => $progressDistribution,
            ];
        }

        // Get all tasks with their latest progress
        // OPTIMIZED: Use latest progress cache (only ~213 records instead of 16,572!)
        $taskProgress = $this->getCachedLatestProgress()
            ->map(function ($progress) {
                // Each record is already the latest progress for each task
                $task = $progress->task;

                // Build breadcrumb hierarchy and get root task
                $breadcrumb = $this->getTaskBreadcrumb($task);
                $rootTaskInfo = $this->getRootTaskInfo($task);

                return [
                    'task' => $task,
                    'percentage' => (float) $progress->percentage,
                    'progress_date' => $progress->progress_date,
                    'notes' => $progress->notes,
                    'breadcrumb' => $breadcrumb,
                    'root_task_id' => $rootTaskInfo['id'],
                    'root_task_name' => $rootTaskInfo['name'],
                ];
            })
            ->values();

        // Group task progress by root task
        $taskProgressByRoot = $this->groupTasksByRoot($taskProgress);

        // Get tasks without progress for selected project
        $tasksWithoutProgress = $this->getTasksWithoutProgress();

        return [
            'projects' => $projects,
            'kodams' => $kodams,
            'korems' => $korems,
            'kodims' => $kodims,
            'koramils' => $koramils,
            'locations' => $locations,
            'stats' => $stats,
            'taskProgress' => $taskProgress,
            'taskProgressByRoot' => $taskProgressByRoot,
            'sCurveData' => $sCurveData,
            'tasksWithoutProgress' => $tasksWithoutProgress,
            'top10Projects' => $top10Projects,
            'aggregatedOverallProgress' => $aggregatedOverallProgress,
            'projectsOnTrackCount' => $projectsOnTrackCount,
            'projectsBehindCount' => $projectsBehindCount,
            'averageDelayDays' => $averageDelayDays,
            'progressDistribution' => $progressDistribution,
        ];
    }

    private function getTaskBreadcrumb(Task $task): string
    {
        // OPTIMIZATION: Use preloaded tasks instead of individual queries
        $tasks = $this->preloadProjectTasks($task->project_id);

        $breadcrumb = [$task->name];
        $current = $task;

        while ($current->parent_id) {
            $current = $tasks[$current->parent_id] ?? null;
            if (! $current) {
                break;
            }
            $breadcrumb[] = $current->name;
        }

        // Reverse to show root -> leaf
        return implode(' > ', array_reverse($breadcrumb));
    }

    private function getTasksWithoutProgress(): array
    {
        if (! $this->selectedProjectId) {
            return [];
        }

        // OPTIMIZATION: Preload all project tasks with parent hierarchy (needed for breadcrumb traversal)
        $tasks = $this->preloadProjectTasks($this->selectedProjectId);

        // OPTIMIZED: Get leaf tasks from cache (already loaded in with() method at line 1042)
        // This eliminates O(n²) manual filtering
        $leafTasks = $this->getCachedLeafTasks($this->selectedProjectId)
            ->sortBy('_lft')
            ->values();

        // OPTIMIZED: Get task IDs that have progress from already-loaded cache (loaded at line 1043)
        // This eliminates the database query entirely
        $taskIdsWithProgress = $this->getCachedLatestProgress()
            ->pluck('task_id')
            ->unique()
            ->toArray();

        // Filter leaf tasks without progress and group by root task
        $tasksWithoutProgress = [];

        foreach ($leafTasks as $task) {
            if (! in_array($task->id, $taskIdsWithProgress)) {
                // Find root task using preloaded tasks
                $rootTask = $task;
                $parentChain = [];

                while ($rootTask->parent_id) {
                    $rootTask = $tasks[$rootTask->parent_id] ?? null;
                    if (! $rootTask) {
                        break;
                    }
                    $parentChain[] = $rootTask->name;
                }

                $rootTaskName = $rootTask->name;

                if (! isset($tasksWithoutProgress[$rootTaskName])) {
                    $tasksWithoutProgress[$rootTaskName] = [];
                }

                // Build breadcrumb (reverse to show root -> parent hierarchy, excluding the leaf task itself)
                $breadcrumb = array_reverse($parentChain);

                $tasksWithoutProgress[$rootTaskName][] = [
                    'id' => $task->id,
                    'name' => $task->name,
                    'weight' => (float) $task->weight,
                    'breadcrumb' => implode(' > ', $breadcrumb),
                ];
            }
        }

        return $tasksWithoutProgress;
    }

    /**
     * Get root task information for a given task.
     *
     * @return array{id: int, name: string}
     */
    private function getRootTaskInfo(Task $task): array
    {
        // OPTIMIZATION: Use preloaded tasks instead of individual queries
        $tasks = $this->preloadProjectTasks($task->project_id);

        $current = $task;

        while ($current->parent_id) {
            $current = $tasks[$current->parent_id] ?? null;
            if (! $current) {
                break;
            }
        }

        return [
            'id' => $current->id,
            'name' => $current->name,
        ];
    }

    /**
     * Group task progress items by their root task.
     *
     * @param  \Illuminate\Support\Collection<int, array>  $taskProgress
     * @return array<string, array{root_id: int, root_name: string, tasks: array, avg_progress: float, task_count: int}>
     */
    private function groupTasksByRoot($taskProgress): array
    {
        $grouped = [];

        foreach ($taskProgress as $item) {
            $rootName = $item['root_task_name'];
            $rootId = $item['root_task_id'];

            if (! isset($grouped[$rootName])) {
                $grouped[$rootName] = [
                    'root_id' => $rootId,
                    'root_name' => $rootName,
                    'tasks' => [],
                    'avg_progress' => 0,
                    'task_count' => 0,
                ];
            }

            $grouped[$rootName]['tasks'][] = $item;
            $grouped[$rootName]['task_count']++;
        }

        // Calculate weighted average progress for each root task
        foreach ($grouped as $rootName => $data) {
            $totalWeight = 0;
            $totalWeightedProgress = 0;

            foreach ($data['tasks'] as $item) {
                $taskWeight = (float) $item['task']->weight;
                $totalWeight += $taskWeight;
                $totalWeightedProgress += $item['percentage'] * $taskWeight;
            }

            $grouped[$rootName]['avg_progress'] = $totalWeight > 0
                ? round($totalWeightedProgress / $totalWeight, 2)
                : 0;

            // Sort tasks by percentage descending
            usort($grouped[$rootName]['tasks'], fn ($a, $b) => $b['percentage'] <=> $a['percentage']);
        }

        // Sort root tasks by average progress descending
        uasort($grouped, fn ($a, $b) => $b['avg_progress'] <=> $a['avg_progress']);

        return $grouped;
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6" x-data="{ showFilters: window.innerWidth >= 1024 }">
    {{-- Custom Styles --}}
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

        .dashboard-page {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
        }

        .dashboard-page .stat-card {
            position: relative;
            overflow: hidden;
        }

        .dashboard-page .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--stat-accent, theme('colors.blue.500'));
        }

        .dashboard-page .progress-ring-bg {
            stroke: theme('colors.neutral.200');
        }

        .dark .dashboard-page .progress-ring-bg {
            stroke: theme('colors.neutral.700');
        }

        .dashboard-page .animate-in {
            animation: fadeSlideIn 0.4s ease-out forwards;
        }

        @keyframes fadeSlideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dashboard-page .project-card {
            transition: all 0.2s ease;
        }

        .dashboard-page .project-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -5px rgba(0, 0, 0, 0.1), 0 4px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .dark .dashboard-page .project-card:hover {
            box-shadow: 0 8px 25px -5px rgba(0, 0, 0, 0.4);
        }
    </style>

    {{-- Load Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <div class="dashboard-page">
        {{-- Header --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-neutral-900 dark:text-white sm:text-3xl">
                    Dashboard
                </h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                    {{ now()->translatedFormat('l, j F Y') }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                {{-- Filter Toggle --}}
                <button
                    @click="showFilters = !showFilters"
                    class="flex items-center gap-2 rounded-lg border border-neutral-300 bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-sm transition hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                >
                    <flux:icon.funnel class="h-4 w-4" />
                    <span x-text="showFilters ? 'Hide Filters' : 'Show Filters'"></span>
                    <flux:icon.chevron-down class="h-4 w-4 transition-transform" x-bind:class="showFilters ? 'rotate-180' : ''" />
                </button>
            </div>
        </div>

        {{-- Filters Panel --}}
        <div
            x-show="showFilters"
            x-collapse
            class="mb-6"
        >
            <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800/50">
                <div class="mb-4 flex items-center gap-2">
                    <flux:icon.adjustments-horizontal class="h-5 w-5 text-neutral-500" />
                    <span class="text-sm font-semibold text-neutral-700 dark:text-neutral-300">Filter Data</span>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    @if(!auth()->user()->hasRole('Reporter'))
                        {{-- Kodam --}}
                        <div>
                            <flux:select wire:model.live="selectedKodamId" label="Kodam" :disabled="$filtersLocked" size="sm">
                                <option value="">Semua Kodam</option>
                                @foreach($kodams as $kodam)
                                    <option value="{{ $kodam->id }}">{{ $kodam->name }}</option>
                                @endforeach
                            </flux:select>
                        </div>

                        {{-- Korem --}}
                        <div>
                            <flux:select wire:model.live="selectedKoremId" label="Korem" :disabled="$filtersLocked || !$this->selectedKodamId" size="sm">
                                <option value="">{{ $this->selectedKodamId ? 'Pilih Korem...' : 'Pilih Kodam dulu' }}</option>
                                @foreach($korems as $korem)
                                    <option value="{{ $korem->id }}">{{ $korem->name }}</option>
                                @endforeach
                            </flux:select>
                        </div>

                        {{-- Kodim --}}
                        <div>
                            <flux:select wire:model.live="selectedKodimId" label="Kodim" :disabled="$filtersLocked || !$this->selectedKoremId" size="sm">
                                <option value="">{{ $this->selectedKoremId ? 'Pilih Kodim...' : 'Pilih Korem dulu' }}</option>
                                @foreach($kodims as $kodim)
                                    <option value="{{ $kodim->id }}">{{ $kodim->name }} ({{ $kodim->projects_count }})</option>
                                @endforeach
                            </flux:select>
                        </div>

                        {{-- Koramil --}}
                        <div>
                            <flux:select wire:model.live="selectedKoramilId" label="Koramil" :disabled="!$this->selectedKodimId" size="sm">
                                <option value="">{{ $this->selectedKodimId ? 'Semua Koramil' : 'Pilih Kodim dulu' }}</option>
                                @foreach($koramils as $koramil)
                                    <option value="{{ $koramil->id }}">{{ $koramil->name }} ({{ $koramil->projects_count }})</option>
                                @endforeach
                            </flux:select>
                        </div>

                        {{-- Location --}}
                        <div>
                            <flux:select wire:model.live="selectedLocationId" label="Lokasi" size="sm">
                                <option value="">Semua Lokasi</option>
                                @foreach($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->village_name }} - {{ $location->district_name }}</option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endif

                    {{-- Project --}}
                    <div class="{{ auth()->user()->hasRole('Reporter') ? 'sm:col-span-2' : '' }}">
                        <flux:select wire:model.live="selectedProjectId" label="Project" size="sm">
                            <option value="">Pilih project untuk detail...</option>
                            @foreach($projects as $project)
                                <option value="{{ $project->id }}">{{ $project->name }} - {{ $project->location->city_name ?? '-' }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>
            </div>
        </div>

        @if($stats && empty($selectedProjectId))
            {{-- Hero Stats Section - Aggregated View --}}
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {{-- Overall Progress --}}
                <div class="stat-card col-span-2 flex items-center gap-4 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800" style="--stat-accent: theme('colors.blue.500')">
                    <div class="relative h-20 w-20 flex-shrink-0">
                        <svg class="h-20 w-20 -rotate-90 transform" viewBox="0 0 36 36">
                            <path
                                class="progress-ring-bg"
                                stroke-width="3"
                                fill="none"
                                d="M18 2.0845
                                    a 15.9155 15.9155 0 0 1 0 31.831
                                    a 15.9155 15.9155 0 0 1 0 -31.831"
                            />
                            <path
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                fill="none"
                                class="text-blue-500"
                                stroke-dasharray="{{ $aggregatedOverallProgress }}, 100"
                                d="M18 2.0845
                                    a 15.9155 15.9155 0 0 1 0 31.831
                                    a 15.9155 15.9155 0 0 1 0 -31.831"
                            />
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-lg font-bold text-neutral-900 dark:text-white">{{ number_format($aggregatedOverallProgress, 0) }}%</span>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-neutral-500 dark:text-neutral-400">Progres Keseluruhan</p>
                        <p class="text-2xl font-bold text-neutral-900 dark:text-white">{{ number_format($aggregatedOverallProgress, 1) }}%</p>
                        <p class="mt-1 text-xs text-neutral-500">Rata-rata dari {{ $stats['total_projects'] }} project</p>
                    </div>
                </div>

                {{-- Projects On Track --}}
                <div class="stat-card rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800" style="--stat-accent: theme('colors.emerald.500')">
                    <div class="flex items-center justify-between">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/30">
                            <flux:icon.check-circle class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $projectsOnTrackCount }}</span>
                    </div>
                    <p class="mt-3 text-sm font-medium text-neutral-700 dark:text-neutral-300">Sesuai Target</p>
                    <p class="mt-0.5 text-xs text-neutral-500">Project memenuhi target</p>
                </div>

                {{-- Projects Behind --}}
                <div class="stat-card rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800" style="--stat-accent: theme('colors.rose.500')">
                    <div class="flex items-center justify-between">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-rose-100 dark:bg-rose-900/30">
                            <flux:icon.exclamation-triangle class="h-5 w-5 text-rose-600 dark:text-rose-400" />
                        </div>
                        <span class="text-2xl font-bold text-rose-600 dark:text-rose-400">{{ $projectsBehindCount }}</span>
                    </div>
                    <p class="mt-3 text-sm font-medium text-neutral-700 dark:text-neutral-300">Terlambat</p>
                    <p class="mt-0.5 text-xs text-neutral-500">Project di bawah target</p>
                </div>
            </div>

            {{-- Secondary Stats Row --}}
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                            <flux:icon.folder class="h-4 w-4 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <p class="text-xl font-bold text-neutral-900 dark:text-white">{{ number_format($stats['total_projects']) }}</p>
                            <p class="text-xs text-neutral-500">Total Project</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-cyan-100 dark:bg-cyan-900/30">
                            <flux:icon.play class="h-4 w-4 text-cyan-600 dark:text-cyan-400" />
                        </div>
                        <div>
                            <p class="text-xl font-bold text-neutral-900 dark:text-white">{{ number_format($stats['active_projects']) }}</p>
                            <p class="text-xs text-neutral-500">Project Aktif</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/30">
                            <flux:icon.clock class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                        </div>
                        <div>
                            <p class="text-xl font-bold text-neutral-900 dark:text-white">{{ number_format($averageDelayDays) }}</p>
                            <p class="text-xs text-neutral-500">Hari Keterlambatan</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex items-center gap-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900/30">
                            <flux:icon.users class="h-4 w-4 text-purple-600 dark:text-purple-400" />
                        </div>
                        <div>
                            <p class="text-xl font-bold text-neutral-900 dark:text-white">{{ number_format($stats['total_reporters']) }}</p>
                            <p class="text-xs text-neutral-500">Reporter</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Charts Section --}}
            <div class="mb-6 grid gap-6 lg:grid-cols-2">
                {{-- Progress Distribution Chart --}}
                @if(array_sum($progressDistribution) > 0)
                    <div class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="mb-4">
                            <h3 class="text-base font-semibold text-neutral-900 dark:text-white">Distribusi Progres</h3>
                            <p class="text-xs text-neutral-500">Project berdasarkan persentase penyelesaian</p>
                        </div>

                        <div class="relative h-56">
                            <canvas
                                x-data="{
                                    chart: null,
                                    distributionData: @js($progressDistribution),
                                    init() {
                                        this.waitForChartJs();
                                    },
                                    waitForChartJs() {
                                        if (typeof Chart !== 'undefined') {
                                            this.renderChart();
                                        } else {
                                            setTimeout(() => this.waitForChartJs(), 50);
                                        }
                                    },
                                    renderChart() {
                                        if (typeof Chart === 'undefined') return;

                                        if (this.chart) {
                                            this.chart.destroy();
                                        }

                                        const isDark = document.documentElement.classList.contains('dark');
                                        const textColor = isDark ? '#a1a1aa' : '#52525b';

                                        this.chart = new Chart(this.$el, {
                                            type: 'doughnut',
                                            data: {
                                                labels: ['0-25%', '25-50%', '50-75%', '75-100%'],
                                                datasets: [{
                                                    data: [
                                                        this.distributionData['0-25'],
                                                        this.distributionData['25-50'],
                                                        this.distributionData['50-75'],
                                                        this.distributionData['75-100']
                                                    ],
                                                    backgroundColor: [
                                                        '#f43f5e',
                                                        '#f59e0b',
                                                        '#3b82f6',
                                                        '#10b981'
                                                    ],
                                                    borderWidth: 0,
                                                    borderRadius: 4
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                maintainAspectRatio: false,
                                                cutout: '65%',
                                                plugins: {
                                                    legend: {
                                                        position: 'right',
                                                        labels: {
                                                            color: textColor,
                                                            usePointStyle: true,
                                                            pointStyle: 'circle',
                                                            padding: 15,
                                                            font: { size: 12 }
                                                        }
                                                    }
                                                }
                                            }
                                        });
                                    }
                                }"
                                wire:ignore
                            ></canvas>
                        </div>
                    </div>
                @endif

                {{-- Quick Stats Cards --}}
                <div class="grid grid-cols-2 gap-4">
                    @php
                        $distColors = [
                            '0-25' => ['bg' => 'bg-rose-50 dark:bg-rose-900/20', 'text' => 'text-rose-600 dark:text-rose-400', 'label' => '0-25%'],
                            '25-50' => ['bg' => 'bg-amber-50 dark:bg-amber-900/20', 'text' => 'text-amber-600 dark:text-amber-400', 'label' => '25-50%'],
                            '50-75' => ['bg' => 'bg-blue-50 dark:bg-blue-900/20', 'text' => 'text-blue-600 dark:text-blue-400', 'label' => '50-75%'],
                            '75-100' => ['bg' => 'bg-emerald-50 dark:bg-emerald-900/20', 'text' => 'text-emerald-600 dark:text-emerald-400', 'label' => '75-100%'],
                        ];
                    @endphp
                    @foreach($progressDistribution as $range => $count)
                        <div class="rounded-xl border border-neutral-200 {{ $distColors[$range]['bg'] }} p-4 dark:border-neutral-700">
                            <p class="text-3xl font-bold {{ $distColors[$range]['text'] }}">{{ $count }}</p>
                            <p class="mt-1 text-sm font-medium text-neutral-600 dark:text-neutral-400">{{ $distColors[$range]['label'] }}</p>
                            <p class="text-xs text-neutral-500">project</p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Top 10 Projects --}}
            @if(!empty($top10Projects))
                <div class="rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="border-b border-neutral-200 p-5 dark:border-neutral-700">
                        <h3 class="text-base font-semibold text-neutral-900 dark:text-white">Top 10 Project</h3>
                        <p class="text-xs text-neutral-500">Diurutkan berdasarkan progres tertinggi</p>
                    </div>

                    <div class="divide-y divide-neutral-100 dark:divide-neutral-700">
                        @foreach($top10Projects as $index => $projectData)
                            <div class="project-card flex items-center gap-4 p-4 hover:bg-neutral-50 dark:hover:bg-neutral-700/50">
                                {{-- Rank --}}
                                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full font-bold
                                    @if($index === 0) bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400
                                    @elseif($index === 1) bg-neutral-200 text-neutral-600 dark:bg-neutral-600 dark:text-neutral-300
                                    @elseif($index === 2) bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-400
                                    @else bg-neutral-100 text-neutral-500 dark:bg-neutral-700 dark:text-neutral-400
                                    @endif">
                                    {{ $index + 1 }}
                                </div>

                                {{-- Project Info --}}
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium text-neutral-900 dark:text-white">{{ $projectData['name'] }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-neutral-500">
                                        <span>{{ $projectData['location_village'] }}, {{ $projectData['location_district'] }}</span>
                                        @if($projectData['kodim_name'])
                                            <flux:badge size="sm" color="zinc">{{ $projectData['kodim_name'] }}</flux:badge>
                                        @endif
                                    </div>
                                </div>

                                {{-- Progress --}}
                                <div class="flex-shrink-0 text-right">
                                    <p class="text-lg font-bold
                                        @if($projectData['progress'] >= 75) text-emerald-600 dark:text-emerald-400
                                        @elseif($projectData['progress'] >= 50) text-blue-600 dark:text-blue-400
                                        @elseif($projectData['progress'] >= 25) text-amber-600 dark:text-amber-400
                                        @else text-rose-600 dark:text-rose-400
                                        @endif">
                                        {{ number_format($projectData['progress'], 1) }}%
                                    </p>
                                    <div class="mt-1 h-1.5 w-20 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                                        <div
                                            class="h-full rounded-full transition-all
                                                @if($projectData['progress'] >= 75) bg-emerald-500
                                                @elseif($projectData['progress'] >= 50) bg-blue-500
                                                @elseif($projectData['progress'] >= 25) bg-amber-500
                                                @else bg-rose-500
                                                @endif"
                                            style="width: {{ min($projectData['progress'], 100) }}%"
                                        ></div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                {{-- Empty State --}}
                <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 py-16 dark:border-neutral-600 dark:bg-neutral-800/50">
                    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-neutral-200 dark:bg-neutral-700">
                        <flux:icon.chart-bar class="h-8 w-8 text-neutral-400" />
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-neutral-700 dark:text-neutral-300">Belum Ada Data Progres</h3>
                    <p class="mt-1 max-w-sm text-center text-sm text-neutral-500">
                        Project yang dipilih belum memiliki data progres. Mulai tambahkan progres untuk melihat statistik.
                    </p>
                </div>
            @endif
        @endif

        {{-- Single Project View --}}
        @if($selectedProjectId && !empty($sCurveData['labels']))
            {{-- S-Curve Chart --}}
            <div class="mb-6 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800" wire:key="scurve-{{ $selectedProjectId }}-{{ md5(json_encode($sCurveData)) }}">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-neutral-900 dark:text-white">Kurva S Progress</h3>
                        <p class="text-xs text-neutral-500">Perbandingan rencana vs realisasi</p>
                    </div>

                    @if($sCurveData && isset($sCurveData['delayDays']) && $sCurveData['delayDays'] > 0)
                        <div class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-1.5 dark:bg-amber-900/30">
                            <flux:icon.exclamation-triangle class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                            <span class="text-sm font-medium text-amber-700 dark:text-amber-300">
                                Terlambat {{ $sCurveData['delayDays'] }} hari
                            </span>
                        </div>
                    @endif
                </div>

                <div class="relative h-72">
                    <canvas
                        x-data="{
                            chart: null,
                            chartData: @js($sCurveData),
                            init() {
                                this.waitForChartJs();
                            },
                            waitForChartJs() {
                                if (typeof Chart !== 'undefined') {
                                    this.renderChart();
                                } else {
                                    setTimeout(() => this.waitForChartJs(), 50);
                                }
                            },
                            renderChart() {
                                if (typeof Chart === 'undefined') return;

                                if (this.chart) {
                                    this.chart.destroy();
                                }

                                const isDark = document.documentElement.classList.contains('dark');
                                const gridColor = isDark ? 'rgba(255, 255, 255, 0.06)' : 'rgba(0, 0, 0, 0.06)';
                                const textColor = isDark ? '#a1a1aa' : '#52525b';

                                this.chart = new Chart(this.$el, {
                                    type: 'line',
                                    data: {
                                        labels: this.chartData.labels,
                                        datasets: [
                                            {
                                                label: 'Rencana',
                                                data: this.chartData.planned,
                                                borderColor: '#3b82f6',
                                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                                borderWidth: 2,
                                                fill: true,
                                                tension: 0.4,
                                                pointRadius: 0,
                                                pointHoverRadius: 6,
                                            },
                                            {
                                                label: 'Realisasi',
                                                data: this.chartData.actual,
                                                borderColor: '#10b981',
                                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                                borderWidth: 2,
                                                fill: true,
                                                tension: 0.4,
                                                pointRadius: 0,
                                                pointHoverRadius: 6,
                                            }
                                        ]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        interaction: {
                                            intersect: false,
                                            mode: 'index',
                                        },
                                        plugins: {
                                            legend: {
                                                position: 'top',
                                                align: 'end',
                                                labels: {
                                                    color: textColor,
                                                    usePointStyle: true,
                                                    pointStyle: 'circle',
                                                    padding: 20,
                                                    font: { size: 12 }
                                                }
                                            },
                                            tooltip: {
                                                backgroundColor: isDark ? '#27272a' : '#ffffff',
                                                titleColor: textColor,
                                                bodyColor: textColor,
                                                borderColor: isDark ? '#3f3f46' : '#e4e4e7',
                                                borderWidth: 1,
                                                padding: 12,
                                                cornerRadius: 8,
                                                callbacks: {
                                                    label: function(context) {
                                                        return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '%';
                                                    }
                                                }
                                            }
                                        },
                                        scales: {
                                            x: {
                                                grid: { color: gridColor },
                                                ticks: {
                                                    color: textColor,
                                                    maxRotation: 45,
                                                    font: { size: 10 }
                                                }
                                            },
                                            y: {
                                                min: 0,
                                                max: 100,
                                                grid: { color: gridColor },
                                                ticks: {
                                                    color: textColor,
                                                    font: { size: 10 },
                                                    callback: function(value) {
                                                        return value + '%';
                                                    }
                                                }
                                            }
                                        }
                                    }
                                });
                            }
                        }"
                        wire:ignore
                    ></canvas>
                </div>
            </div>
        @endif

        {{-- Tasks Without Progress --}}
        @if($selectedProjectId && !empty($tasksWithoutProgress))
            <div class="rounded-xl border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20">
                <div class="border-b border-amber-200 p-5 dark:border-amber-800">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-800">
                            <flux:icon.exclamation-triangle class="h-5 w-5 text-amber-600 dark:text-amber-400" />
                        </div>
                        <div>
                            <h3 class="font-semibold text-amber-900 dark:text-amber-100">Pekerjaan Tanpa Progres</h3>
                            @php
                                $totalMissing = collect($tasksWithoutProgress)->flatten(1)->count();
                            @endphp
                            <p class="text-sm text-amber-700 dark:text-amber-300">{{ $totalMissing }} pekerjaan belum memiliki data progres</p>
                        </div>
                    </div>
                </div>

                <div class="max-h-80 overflow-y-auto p-5">
                    <div class="space-y-4">
                        @foreach($tasksWithoutProgress as $rootTaskName => $tasks)
                            <div class="rounded-lg border border-amber-200 bg-white p-4 dark:border-amber-700 dark:bg-amber-900/30">
                                <h4 class="mb-2 font-medium text-amber-900 dark:text-amber-100">
                                    {{ $rootTaskName }}
                                    <span class="ml-2 text-sm font-normal text-amber-600 dark:text-amber-400">({{ count($tasks) }} item)</span>
                                </h4>
                                <div class="space-y-1.5">
                                    @foreach($tasks as $task)
                                        <div class="flex items-center justify-between rounded bg-amber-50 px-3 py-1.5 text-sm dark:bg-amber-900/20">
                                            <span class="text-amber-800 dark:text-amber-200">{{ $task['name'] }}</span>
                                            <span class="text-xs text-amber-600 dark:text-amber-400">{{ number_format($task['weight'], 2) }}%</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

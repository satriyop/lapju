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

    private $cachedProjectProgress = null;

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
     */
    private function getCachedSettings(): array
    {
        if ($this->cachedSettings === null) {
            $this->cachedSettings = [
                'start_date' => Setting::get('project.default_start_date', '2025-11-01'),
                'end_date' => Setting::get('project.default_end_date', '2026-01-31'),
            ];
        }

        return $this->cachedSettings;
    }

    /**
     * Get cached office levels
     */
    private function getCachedOfficeLevels(): object
    {
        if ($this->cachedOfficeLevels === null) {
            $levels = OfficeLevel::all()->keyBy('level');
            $this->cachedOfficeLevels = (object) [
                'kodam' => $levels->get(1),
                'korem' => $levels->get(2),
                'kodim' => $levels->get(3),
                'koramil' => $levels->get(4),
            ];
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

        // Use JOIN instead of correlated subquery to avoid N+1 on subqueries
        $latestProgress = DB::table('task_progress as tp1')
            ->select('tp1.task_id', 'tp1.project_id', 'tp1.percentage', 'tp1.progress_date')
            ->join(DB::raw('(
                SELECT task_id, project_id, MAX(progress_date) as max_date
                FROM task_progress
                WHERE project_id IN ('.implode(',', array_map('intval', $projectIds)).')
                  AND progress_date <= '."'{$upToDate}'".'
                GROUP BY task_id, project_id
            ) latest'), function ($join) {
                $join->on('tp1.task_id', '=', 'latest.task_id')
                    ->on('tp1.project_id', '=', 'latest.project_id')
                    ->on('tp1.progress_date', '=', 'latest.max_date');
            })
            ->get()
            ->keyBy(function ($item) {
                return "{$item->project_id}_{$item->task_id}";
            });

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

        // OPTIMIZED: For single project view, reuse getCachedProjectProgress() data to avoid duplicate query
        if (count($projectIds) === 1 && $projectIds[0] == $this->selectedProjectId && $this->cachedProjectProgress !== null) {
            // Transform the cached project progress data to the grouped array format expected by getProgressAtDate()
            $grouped = [];
            foreach ($this->cachedProjectProgress as $progress) {
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

        // FIXED: Load progress records ONLY for LEAF tasks (where actual work happens)
        // Parent task progress should not be included in calculations
        $allProgress = TaskProgress::whereIn('project_id', $projectIds)
            ->whereBetween('progress_date', [$startDate, $endDate])
            ->whereHas('task', function ($query) {
                $query->whereDoesntHave('children');  // Only leaf tasks
            })
            ->select('task_id', 'project_id', 'percentage', 'progress_date')
            ->orderBy('progress_date', 'desc')
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
            // FIXED: Load with same structure as batch load for consistency
            $this->cachedLeafTasks[$projectId] = Task::select('id', 'project_id', 'weight')
                ->where('project_id', $projectId)
                ->whereDoesntHave('children')
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
     * Get cached TaskProgress for selected project
     * OPTIMIZED: Load once and reuse for both hierarchical view and task breakdown
     * FIXED: Use organization-wide date range for consistency with S-curve calculations
     */
    private function getCachedProjectProgress()
    {
        if ($this->cachedProjectProgress === null && $this->selectedProjectId) {
            // FIXED: Use organization-wide date range (same as loadAllProgressData and S-curve)
            // This ensures consistent data loading across all calculations
            $settings = $this->getCachedSettings();
            $startDate = $settings['start_date'];
            $endDate = $settings['end_date'];

            // FIXED: Only load progress for LEAF tasks (tasks without children) to avoid double-counting
            // Parent task progress should not be included in weighted averages
            $this->cachedProjectProgress = TaskProgress::where('project_id', $this->selectedProjectId)
                ->whereBetween('progress_date', [$startDate, $endDate])
                ->whereHas('task', function ($query) {
                    // Only include tasks that don't have children (leaf tasks)
                    $query->whereDoesntHave('children');
                })
                ->select('id', 'task_id', 'project_id', 'percentage', 'progress_date', 'notes')
                ->with(['task' => function ($query) {
                    $query->select('id', 'project_id', 'parent_id', 'name', 'weight');
                }])
                ->get();
        }

        return $this->cachedProjectProgress ?? collect();
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
        // FIXED: Load only LEAF tasks (tasks without children) since progress is entered there
        $allLeafTasks = Task::select('id', 'project_id', 'weight')
            ->whereIn('project_id', $missingProjectIds)
            ->whereDoesntHave('children')  // Only leaf tasks have progress data
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
        $query = Location::select('id', 'village_name', 'district_name', 'city_name')
            ->whereIn('id', Project::pluck('location_id')->unique());

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
            // Single project view: load project progress ONCE (shared by hierarchical view, task breakdown, and S-curve)
            $this->batchLoadAllLeafTasks([$this->selectedProjectId]);
            $this->getCachedProjectProgress(); // Loads TaskProgress with eager loaded tasks
            $this->loadAllProgressData([$this->selectedProjectId]); // Will reuse the above data, just transforms format
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
            ? Office::where('level_id', $kodimLevel->id)
                ->where('parent_id', $this->selectedKoremId)
                ->selectRaw('offices.id, offices.name, (
                    SELECT COUNT(*) FROM projects
                    WHERE projects.office_id IN (
                        SELECT id FROM offices AS koramils WHERE koramils.parent_id = offices.id
                    )
                ) as projects_count')
                ->orderBy('name')
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
        $activeProjects = Project::whereIn('id', $projectIds)
            ->whereHas('progress')
            ->distinct()
            ->count();

        $totalLocations = $locations->count();

        // Reporters: users assigned to filtered projects
        $totalReporters = User::whereHas('projects', function ($q) use ($projectIds) {
            $q->whereIn('projects.id', $projectIds);
        })
            ->whereHas('roles', fn ($q) => $q->where('name', 'Reporter'))
            ->distinct()
            ->count();

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

        // Get all tasks with their latest progress in the date range
        // OPTIMIZED: Use cached project progress data (same data as hierarchical view)
        $taskProgress = $this->getCachedProjectProgress()
            ->groupBy('task_id')
            ->map(function ($progressEntries) {
                // Get the latest entry for each task
                $latest = $progressEntries->sortByDesc('progress_date')->first();
                $task = $latest->task;

                // Build breadcrumb hierarchy and get root task
                $breadcrumb = $this->getTaskBreadcrumb($task);
                $rootTaskInfo = $this->getRootTaskInfo($task);

                return [
                    'task' => $task,
                    'percentage' => (float) $latest->percentage,
                    'progress_date' => $latest->progress_date,
                    'notes' => $latest->notes,
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

        // FIXED: Use organization-wide date range for consistency with all other calculations
        $settings = $this->getCachedSettings();
        $startDate = $settings['start_date'];
        $endDate = $settings['end_date'];

        // OPTIMIZATION: Preload all project tasks with parent hierarchy
        $tasks = $this->preloadProjectTasks($this->selectedProjectId);

        // OPTIMIZED: Get leaf tasks from already preloaded tasks (no separate query)
        $leafTasks = $tasks->filter(function ($task) use ($tasks) {
            // A task is a leaf if no other task has it as a parent
            return ! $tasks->contains('parent_id', $task->id);
        })->sortBy('_lft')->values();

        // Get task IDs that have progress within the organization date range
        $taskIdsWithProgress = TaskProgress::where('project_id', $this->selectedProjectId)
            ->whereBetween('progress_date', [$startDate, $endDate])
            ->distinct()
            ->pluck('task_id')
            ->toArray();

        // Filter leaf tasks without progress and group by root task
        $tasksWithoutProgress = [];

        foreach ($leafTasks as $task) {
            if (! in_array($task->id, $taskIdsWithProgress)) {
                // Find root task using preloaded tasks
                $rootTask = $task;
                $parentChain = [$task->name];

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

                // Build breadcrumb (reverse to show root -> leaf)
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

<div class="flex h-full w-full flex-1 flex-col gap-6">
        <!-- Load Chart.js for S-Curve -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

        <!-- Header -->
        <div class="flex items-center justify-between">
            <flux:heading size="xl">Dashboard</flux:heading>
            <div class="text-sm text-neutral-600 dark:text-neutral-400">
                {{ now()->format('l, F j, Y') }}
            </div>
        </div>

        <!-- Filters -->
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            @if(!auth()->user()->hasRole('Reporter'))
                <!-- Kodam Filter -->
                <flux:select wire:model.live="selectedKodamId" label="Kodam" :disabled="$filtersLocked">
                    <option value="">All Kodam</option>
                    @foreach($kodams as $kodam)
                        <option value="{{ $kodam->id }}">
                            {{ $kodam->name }}
                        </option>
                    @endforeach
                </flux:select>

                <!-- Korem Filter (enabled only if Kodam is selected) -->
                <flux:select wire:model.live="selectedKoremId" label="Korem" :disabled="$filtersLocked || !$this->selectedKodamId">
                    <option value="">{{ $this->selectedKodamId ? 'Select Korem...' : 'Select Kodam first' }}</option>
                    @foreach($korems as $korem)
                        <option value="{{ $korem->id }}">
                            {{ $korem->name }}
                        </option>
                    @endforeach
                </flux:select>

                <!-- Kodim Filter (enabled only if Korem is selected) -->
                <flux:select wire:model.live="selectedKodimId" label="Kodim" :disabled="$filtersLocked || !$this->selectedKoremId">
                    <option value="">{{ $this->selectedKoremId ? 'Select Kodim...' : 'Select Korem first' }}</option>
                    @foreach($kodims as $kodim)
                        <option value="{{ $kodim->id }}">
                            {{ $kodim->name }} ({{ $kodim->projects_count }})
                        </option>
                    @endforeach
                </flux:select>

                <!-- Koramil Filter (enabled only if Kodim is selected) -->
                <flux:select wire:model.live="selectedKoramilId" label="Koramil" :disabled="!$this->selectedKodimId">
                    <option value="">{{ $this->selectedKodimId ? 'All Koramil' : 'Select Kodim first' }}</option>
                    @foreach($koramils as $koramil)
                        <option value="{{ $koramil->id }}">
                            {{ $koramil->name }} ({{ $koramil->projects_count }})
                        </option>
                    @endforeach
                </flux:select>

                <!-- Location Filter -->
                <flux:select wire:model.live="selectedLocationId" label="Location">
                    <option value="">All Locations</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}">
                            {{ $location->village_name }} - {{ $location->district_name }}
                        </option>
                    @endforeach
                </flux:select>
            @endif

            <!-- Project Filter -->
            <flux:select wire:model.live="selectedProjectId" label="Project">
                <option value="">Select a project...</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}">
                        {{ $project->name }} - {{ $project->location->city_name }}
                    </option>
                @endforeach
            </flux:select>
        </div>

        @if($stats && empty($selectedProjectId))
            <!-- Aggregated Statistics Cards (Only shown when viewing all projects) -->
            <!-- Row 2: Resource Metrics -->
            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Projects</div>
                    <div class="mt-2 text-3xl font-bold text-neutral-900 dark:text-neutral-100">
                        {{ number_format($stats['total_projects']) }}
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Total projects in system</div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Active Projects</div>
                    <div class="mt-2 text-3xl font-bold text-blue-600 dark:text-blue-400">
                        {{ number_format($stats['active_projects']) }}
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Projects with progress</div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Locations</div>
                    <div class="mt-2 text-3xl font-bold text-green-600 dark:text-green-400">
                        {{ number_format($stats['total_locations']) }}
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Available project locations</div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Reporters</div>
                    <div class="mt-2 text-3xl font-bold text-purple-600 dark:text-purple-400">
                        {{ number_format($stats['total_reporters']) }}
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Users with reporter role</div>
                </div>
            </div>

            <!-- Row 1: Performance Metrics -->
            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Overall Progress</div>
                    <div class="mt-2 text-3xl font-bold text-blue-600 dark:text-blue-400">
                        {{ number_format($aggregatedOverallProgress, 1) }}%
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Average across all projects</div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">On Track</div>
                    <div class="mt-2 text-3xl font-bold text-green-600 dark:text-green-400">
                        {{ number_format($projectsOnTrackCount) }}
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Projects meeting targets</div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Behind Schedule</div>
                    <div class="mt-2 text-3xl font-bold text-red-600 dark:text-red-400">
                        {{ number_format($projectsBehindCount) }}
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Projects below targets</div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Avg Delay</div>
                    <div class="mt-2 text-3xl font-bold text-amber-600 dark:text-amber-400">
                        {{ number_format($averageDelayDays) }}
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Days behind schedule</div>
                </div>
            </div>


        @endif

        <!-- S-Curve Chart (Single Project Only) -->
        @if($selectedProjectId && !empty($sCurveData['labels']))
                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900" wire:key="scurve-{{ $selectedProjectId }}-{{ md5(json_encode($sCurveData)) }}">
                    <div class="mb-4">
                        <flux:heading size="lg">S-Curve Progress</flux:heading>
                    </div>

                    @if($sCurveData && isset($sCurveData['delayDays']) && $sCurveData['delayDays'] > 0)
                        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900/20">
                            <div class="flex items-center gap-2">
                                <svg class="h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                                <span class="text-sm font-medium text-amber-800 dark:text-amber-200">
                                    Project started {{ $sCurveData['delayDays'] }} day{{ $sCurveData['delayDays'] > 1 ? 's' : '' }} after planned date
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                Planned: {{ $sCurveData['plannedStartDate'] }} • Actual: {{ $sCurveData['actualStartDate'] }}
                            </p>
                        </div>
                    @endif

                    <div class="relative h-80">
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
                                    const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
                                    const textColor = isDark ? '#d1d5db' : '#374151';

                                    this.chart = new Chart(this.$el, {
                                        type: 'line',
                                        data: {
                                            labels: this.chartData.labels,
                                            datasets: [
                                                {
                                                    label: 'Planned',
                                                    data: this.chartData.planned,
                                                    borderColor: '#3b82f6',
                                                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                                    borderWidth: 3,
                                                    fill: false,
                                                    tension: 0.4,
                                                    pointRadius: 4,
                                                    pointHoverRadius: 6,
                                                },
                                                {
                                                    label: 'Actual',
                                                    data: this.chartData.actual,
                                                    borderColor: '#10b981',
                                                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                                    borderWidth: 3,
                                                    fill: false,
                                                    tension: 0.4,
                                                    pointRadius: 4,
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
                                                    labels: {
                                                        color: textColor,
                                                        usePointStyle: true,
                                                        padding: 20,
                                                    }
                                                },
                                                tooltip: {
                                                    backgroundColor: isDark ? '#1f2937' : '#ffffff',
                                                    titleColor: textColor,
                                                    bodyColor: textColor,
                                                    borderColor: isDark ? '#374151' : '#e5e7eb',
                                                    borderWidth: 1,
                                                    callbacks: {
                                                        label: function(context) {
                                                            return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + '%';
                                                        }
                                                    }
                                                }
                                            },
                                            scales: {
                                                x: {
                                                    grid: {
                                                        color: gridColor,
                                                    },
                                                    ticks: {
                                                        color: textColor,
                                                        maxRotation: 45,
                                                        minRotation: 0,
                                                    }
                                                },
                                                y: {
                                                    min: 0,
                                                    max: 100,
                                                    grid: {
                                                        color: gridColor,
                                                    },
                                                    ticks: {
                                                        color: textColor,
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

        <!-- Progress Distribution Chart (Aggregated Mode Only) -->
        @if(!$selectedProjectId)
            @if(array_sum($progressDistribution) > 0)
                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <flux:heading size="lg" class="mb-4">Project Completion Distribution</flux:heading>
                    <p class="mb-4 text-sm text-neutral-600 dark:text-neutral-400">
                        Projects grouped by completion percentage
                    </p>

                    <div class="relative h-64">
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
                                    const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
                                    const textColor = isDark ? '#d1d5db' : '#374151';

                                    this.chart = new Chart(this.$el, {
                                        type: 'bar',
                                        data: {
                                            labels: ['0-25%', '25-50%', '50-75%', '75-100%'],
                                            datasets: [{
                                                label: 'Number of Projects',
                                                data: [
                                                    this.distributionData['0-25'],
                                                    this.distributionData['25-50'],
                                                    this.distributionData['50-75'],
                                                    this.distributionData['75-100']
                                                ],
                                                backgroundColor: [
                                                    'rgba(239, 68, 68, 0.8)',    // Red
                                                    'rgba(251, 191, 36, 0.8)',   // Yellow
                                                    'rgba(59, 130, 246, 0.8)',   // Blue
                                                    'rgba(34, 197, 94, 0.8)'     // Green
                                                ],
                                                borderColor: [
                                                    'rgb(239, 68, 68)',
                                                    'rgb(251, 191, 36)',
                                                    'rgb(59, 130, 246)',
                                                    'rgb(34, 197, 94)'
                                                ],
                                                borderWidth: 2,
                                                borderRadius: 6
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            plugins: {
                                                legend: {
                                                    display: false
                                                },
                                                tooltip: {
                                                    callbacks: {
                                                        label: function(context) {
                                                            return context.parsed.y + ' project' + (context.parsed.y !== 1 ? 's' : '');
                                                        }
                                                    }
                                                }
                                            },
                                            scales: {
                                                y: {
                                                    beginAtZero: true,
                                                    ticks: {
                                                        stepSize: 1,
                                                        color: textColor
                                                    },
                                                    grid: {
                                                        color: gridColor
                                                    },
                                                    title: {
                                                        display: true,
                                                        text: 'Number of Projects',
                                                        color: textColor
                                                    }
                                                },
                                                x: {
                                                    ticks: {
                                                        color: textColor
                                                    },
                                                    grid: {
                                                        display: false
                                                    },
                                                    title: {
                                                        display: true,
                                                        text: 'Completion Range',
                                                        color: textColor
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
        @endif

        <!-- Top 10 Projects with Best Actual Progress (Only shown when viewing all projects) -->
        @if(empty($selectedProjectId))
        <div class="rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            <div class="border-b border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg">Top 10 Projects with Best Actual Progress</flux:heading>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                    Ranked by weighted average progress of all tasks
                </p>
            </div>
            <div class="p-6">
                @if(empty($top10Projects))
                    <div class="py-12 text-center text-neutral-600 dark:text-neutral-400">
                        No projects with progress data available.
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($top10Projects as $index => $projectData)
                            <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="flex items-start gap-4">
                                    <!-- Rank Badge -->
                                    <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full
                                        @if($index === 0) bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300
                                        @elseif($index === 1) bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300
                                        @elseif($index === 2) bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300
                                        @else bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300
                                        @endif">
                                        <span class="text-xl font-bold">{{ $index + 1 }}</span>
                                    </div>

                                    <!-- Project Info -->
                                    <div class="flex-1">
                                        <div class="mb-2">
                                            <h4 class="font-semibold text-neutral-900 dark:text-neutral-100">
                                                {{ $projectData['name'] }}
                                            </h4>
                                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                                <span class="text-sm text-neutral-600 dark:text-neutral-400">
                                                    {{ $projectData['location_village'] }}, {{ $projectData['location_district'] }}
                                                </span>
                                                @if($projectData['kodim_name'])
                                                    <flux:badge size="sm" color="blue" class="font-medium">
                                                        {{ $projectData['kodim_name'] }}
                                                    </flux:badge>
                                                @endif
                                                @if($projectData['office_name'])
                                                    <flux:badge size="sm" color="green" class="font-medium">                                                
                                                        {{ $projectData['office_name'] }}
                                                    </flux:badge>
                                                @endif
                                            </div>
                                        </div>

                                        <!-- Progress Bar -->
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between">
                                                <span class="text-sm font-medium text-neutral-600 dark:text-neutral-400">
                                                    Actual Progress
                                                </span>
                                                <span class="text-lg font-bold
                                                    @if($projectData['progress'] >= 90) text-green-600 dark:text-green-400
                                                    @elseif($projectData['progress'] >= 70) text-blue-600 dark:text-blue-400
                                                    @elseif($projectData['progress'] >= 50) text-yellow-600 dark:text-yellow-400
                                                    @else text-red-600 dark:text-red-400
                                                    @endif">
                                                    {{ number_format($projectData['progress'], 2) }}%
                                                </span>
                                            </div>
                                            <div class="h-3 w-full overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                                                <div
                                                    class="h-full rounded-full transition-all
                                                        @if($projectData['progress'] >= 90) bg-green-500
                                                        @elseif($projectData['progress'] >= 70) bg-blue-500
                                                        @elseif($projectData['progress'] >= 50) bg-yellow-500
                                                        @else bg-red-500
                                                        @endif"
                                                    style="width: {{ min($projectData['progress'], 100) }}%"
                                                ></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        @endif

        @if($selectedProjectId)
            <!-- Tasks Without Progress -->
            @if(!empty($tasksWithoutProgress))
                <div class="rounded-xl border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950">
                    <div class="border-b border-amber-200 p-6 dark:border-amber-800">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900">
                                <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                            <div>
                                <flux:heading size="lg" class="text-amber-900 dark:text-amber-100">Tasks Without Progress</flux:heading>
                                <p class="text-sm text-amber-700 dark:text-amber-300">
                                    @php
                                        $totalMissing = collect($tasksWithoutProgress)->flatten(1)->count();
                                        $totalWeight = collect($tasksWithoutProgress)->flatten(1)->sum('weight');
                                    @endphp
                                    {{ $totalMissing }} leaf tasks ({{ number_format($totalWeight, 2) }}% of total weight) have no progress recorded
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="max-h-96 overflow-y-auto p-6">
                        <div class="space-y-4">
                            @foreach($tasksWithoutProgress as $rootTaskName => $tasks)
                                <div class="rounded-lg border border-amber-200 bg-white p-4 dark:border-amber-800 dark:bg-amber-900/30">
                                    <h4 class="mb-3 font-semibold text-amber-900 dark:text-amber-100">
                                        {{ $rootTaskName }}
                                        <span class="text-sm font-normal text-amber-700 dark:text-amber-300">
                                            ({{ count($tasks) }} tasks)
                                        </span>
                                    </h4>
                                    <div class="space-y-2">
                                        @foreach($tasks as $task)
                                            <div class="rounded border border-amber-100 bg-amber-50 p-2 dark:border-amber-700 dark:bg-amber-900/50">
                                                <div class="flex items-start justify-between">
                                                    <div class="flex-1">
                                                        <div class="text-sm font-medium text-amber-900 dark:text-amber-100">
                                                            {{ $task['name'] }}
                                                        </div>
                                                        <div class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                                            {{ $task['breadcrumb'] }}
                                                        </div>
                                                    </div>
                                                    <div class="ml-2 text-right">
                                                        <span class="text-sm font-medium text-amber-700 dark:text-amber-300">
                                                            {{ number_format($task['weight'], 2) }}%
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        @endif
</div>

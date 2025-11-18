<?php

use App\Models\Location;
use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
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

    // Expandable progress trend state
    public array $expandedMonths = [];

    public array $expandedWeeks = [];

    public function mount(): void
    {
        // Auto-select first project
        $firstProject = Project::first();
        if ($firstProject) {
            $this->selectedProjectId = $firstProject->id;
            $this->setProjectDates();
        }
    }

    public function updatedSelectedProjectId(): void
    {
        $this->setProjectDates();
    }

    public function updatedSelectedKodamId(): void
    {
        // Reset child selections when parent changes
        $this->selectedKoremId = null;
        $this->selectedKodimId = null;
        $this->selectedKoramilId = null;
        $this->selectedProjectId = null;
    }

    public function updatedSelectedKoremId(): void
    {
        // Reset child selections when parent changes
        $this->selectedKodimId = null;
        $this->selectedKoramilId = null;
        $this->selectedProjectId = null;
    }

    public function updatedSelectedKodimId(): void
    {
        // Reset child selections when parent changes
        $this->selectedKoramilId = null;
        $this->selectedProjectId = null;
    }

    public function updatedSelectedKoramilId(): void
    {
        // Reset project selection when office filter changes
        $this->selectedProjectId = null;
    }

    public function updatedSelectedLocationId(): void
    {
        // Reset project selection when location filter changes
        $this->selectedProjectId = null;
    }

    public function toggleMonth(string $monthKey): void
    {
        if (isset($this->expandedMonths[$monthKey])) {
            unset($this->expandedMonths[$monthKey]);
            // Also collapse all weeks in this month
            foreach (array_keys($this->expandedWeeks) as $weekKey) {
                if (str_starts_with($weekKey, $monthKey)) {
                    unset($this->expandedWeeks[$weekKey]);
                }
            }
        } else {
            $this->expandedMonths[$monthKey] = true;
        }
    }

    public function toggleWeek(string $weekKey): void
    {
        if (isset($this->expandedWeeks[$weekKey])) {
            unset($this->expandedWeeks[$weekKey]);
        } else {
            $this->expandedWeeks[$weekKey] = true;
        }
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
        $query = Project::with('location', 'partner', 'office');

        // Filter by the lowest selected office level
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

    public function calculateSCurveData(): array
    {
        if (! $this->selectedProjectId) {
            return ['labels' => [], 'planned' => [], 'actual' => []];
        }

        // Get the selected project's planned dates
        $project = Project::find($this->selectedProjectId);

        if (! $project || ! $project->start_date || ! $project->end_date) {
            // Fallback to actual progress dates if project dates not set
            $dateRange = TaskProgress::where('project_id', $this->selectedProjectId)
                ->selectRaw('MIN(progress_date) as min_date, MAX(progress_date) as max_date')
                ->first();

            if (! $dateRange || ! $dateRange->min_date || ! $dateRange->max_date) {
                return ['labels' => [], 'planned' => [], 'actual' => []];
            }

            $projectStartDate = Carbon::parse($dateRange->min_date);
            $projectEndDate = Carbon::parse($dateRange->max_date);
        } else {
            // Use project's planned start and end dates
            $projectStartDate = Carbon::parse($project->start_date);
            $projectEndDate = Carbon::parse($project->end_date);
        }

        $totalDays = $projectStartDate->diffInDays($projectEndDate);

        if ($totalDays <= 0) {
            return ['labels' => [], 'planned' => [], 'actual' => []];
        }

        // Calculate planned S-curve (linear distribution based on project timeline)
        $labels = [];
        $plannedData = [];
        $actualData = [];

        // Determine interval based on duration
        $intervalDays = $totalDays <= 30 ? 1 : ($totalDays <= 90 ? 7 : 14);
        $currentDate = $projectStartDate->copy();

        while ($currentDate <= $projectEndDate) {
            $daysPassed = $projectStartDate->diffInDays($currentDate);
            $labels[] = $currentDate->format('M d');

            // Planned: Linear distribution (percentage of time passed based on PROJECT timeline)
            $plannedPercentage = min(($daysPassed / $totalDays) * 100, 100);
            $plannedData[] = round($plannedPercentage, 2);

            // Actual: Calculate cumulative weighted progress up to this date
            $actualPercentage = $this->calculateActualProgress($currentDate);
            $actualData[] = round($actualPercentage, 2);

            $currentDate->addDays($intervalDays);
        }

        // Ensure we have the end date
        if ($currentDate->subDays($intervalDays) < $projectEndDate) {
            $labels[] = $projectEndDate->format('M d');
            $plannedData[] = 100;
            $actualData[] = round($this->calculateActualProgress($projectEndDate), 2);
        }

        return [
            'labels' => $labels,
            'planned' => $plannedData,
            'actual' => $actualData,
        ];
    }

    private function calculateActualProgress(Carbon $date): float
    {
        // Get all leaf tasks (tasks with no children) and their weights
        $leafTasks = Task::whereDoesntHave('children')->get();

        if ($leafTasks->isEmpty()) {
            return 0;
        }

        $totalWeightedProgress = 0;
        $totalWeight = $leafTasks->sum('weight');

        if ($totalWeight == 0) {
            return 0;
        }

        foreach ($leafTasks as $task) {
            // Get the latest progress for this task up to the given date
            $latestProgress = TaskProgress::where('project_id', $this->selectedProjectId)
                ->where('task_id', $task->id)
                ->where('progress_date', '<=', $date->format('Y-m-d'))
                ->orderBy('progress_date', 'desc')
                ->first();

            if ($latestProgress) {
                // Weighted progress = (task percentage * task weight) / total weight
                $taskPercentage = min((float) $latestProgress->percentage, 100);
                $totalWeightedProgress += ($taskPercentage * $task->weight) / $totalWeight;
            }
        }

        return $totalWeightedProgress;
    }

    private function calculateHierarchicalProgress(): array
    {
        if (! $this->selectedProjectId || ! $this->startDate || ! $this->endDate) {
            return [];
        }

        // Get daily progress data
        $dailyData = TaskProgress::where('project_id', $this->selectedProjectId)
            ->whereBetween('progress_date', [$this->startDate, $this->endDate])
            ->select('progress_date', DB::raw('AVG(percentage) as avg_percentage'), DB::raw('COUNT(DISTINCT task_id) as task_count'))
            ->groupBy('progress_date')
            ->orderBy('progress_date')
            ->get();

        $hierarchical = [];

        foreach ($dailyData as $day) {
            $date = Carbon::parse($day->progress_date);
            $monthKey = $date->format('Y-m');
            $monthName = $date->format('F Y');
            // Calculate week number: divide month into exactly 4 weeks
            $daysInMonth = $date->daysInMonth;
            $weekNumber = min(4, (int) ceil(($date->day / $daysInMonth) * 4));
            $weekKey = $monthKey.'-W'.$weekNumber;
            $weekName = 'Week '.$weekNumber;

            // Initialize month if not exists
            if (! isset($hierarchical[$monthKey])) {
                $hierarchical[$monthKey] = [
                    'key' => $monthKey,
                    'name' => $monthName,
                    'start_date' => $date->copy()->startOfMonth(),
                    'end_date' => $date->copy()->endOfMonth(),
                    'avg_percentage' => 0,
                    'task_count' => 0,
                    'day_count' => 0,
                    'weeks' => [],
                ];
            }

            // Initialize week if not exists
            if (! isset($hierarchical[$monthKey]['weeks'][$weekKey])) {
                $hierarchical[$monthKey]['weeks'][$weekKey] = [
                    'key' => $weekKey,
                    'name' => $weekName,
                    'avg_percentage' => 0,
                    'task_count' => 0,
                    'day_count' => 0,
                    'days' => [],
                ];
            }

            // Add day data
            $hierarchical[$monthKey]['weeks'][$weekKey]['days'][] = [
                'date' => $date,
                'formatted_date' => $date->format('M d, Y'),
                'avg_percentage' => (float) $day->avg_percentage,
                'task_count' => (int) $day->task_count,
            ];

            // Update week aggregates
            $hierarchical[$monthKey]['weeks'][$weekKey]['day_count']++;
            $hierarchical[$monthKey]['weeks'][$weekKey]['avg_percentage'] += (float) $day->avg_percentage;
            $hierarchical[$monthKey]['weeks'][$weekKey]['task_count'] += (int) $day->task_count;

            // Update month aggregates
            $hierarchical[$monthKey]['day_count']++;
            $hierarchical[$monthKey]['avg_percentage'] += (float) $day->avg_percentage;
            $hierarchical[$monthKey]['task_count'] += (int) $day->task_count;
        }

        // Calculate averages
        foreach ($hierarchical as $monthKey => $month) {
            if ($month['day_count'] > 0) {
                $hierarchical[$monthKey]['avg_percentage'] = round($month['avg_percentage'] / $month['day_count'], 1);
            }

            foreach ($month['weeks'] as $weekKey => $week) {
                if ($week['day_count'] > 0) {
                    $hierarchical[$monthKey]['weeks'][$weekKey]['avg_percentage'] = round($week['avg_percentage'] / $week['day_count'], 1);
                }
            }
        }

        return $hierarchical;
    }

    public function with(): array
    {
        $projects = $this->getFilteredProjects();
        $locations = Location::orderBy('city_name')->get();

        // Get office levels
        $kodamLevel = OfficeLevel::where('level', 1)->first();
        $koremLevel = OfficeLevel::where('level', 2)->first();
        $kodimLevel = OfficeLevel::where('level', 3)->first();
        $koramilLevel = OfficeLevel::where('level', 4)->first();

        // Cascading office filters
        $kodams = $kodamLevel ? Office::where('level_id', $kodamLevel->id)->orderBy('name')->get() : collect();

        $korems = $koremLevel && $this->selectedKodamId
            ? Office::where('level_id', $koremLevel->id)->where('parent_id', $this->selectedKodamId)->orderBy('name')->get()
            : collect();

        $kodims = $kodimLevel && $this->selectedKoremId
            ? Office::where('level_id', $kodimLevel->id)->where('parent_id', $this->selectedKoremId)->orderBy('name')->get()
            : collect();

        $koramils = $koramilLevel && $this->selectedKodimId
            ? Office::where('level_id', $koramilLevel->id)->where('parent_id', $this->selectedKodimId)->orderBy('name')->get()
            : collect();

        if (! $this->selectedProjectId || ! $this->startDate || ! $this->endDate) {
            return [
                'projects' => $projects,
                'kodams' => $kodams,
                'korems' => $korems,
                'kodims' => $kodims,
                'koramils' => $koramils,
                'locations' => $locations,
                'stats' => null,
                'taskProgress' => collect(),
                'taskProgressByRoot' => [],
                'hierarchicalProgress' => [],
                'sCurveData' => ['labels' => [], 'planned' => [], 'actual' => []],
                'tasksWithoutProgress' => [],
            ];
        }

        // Get all tasks with their latest progress in the date range
        $taskProgress = TaskProgress::where('project_id', $this->selectedProjectId)
            ->whereBetween('progress_date', [$this->startDate, $this->endDate])
            ->with('task')
            ->get()
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

        // Calculate statistics - count only leaf tasks (tasks with no children)
        $totalLeafTasks = Task::whereDoesntHave('children')->count();
        $tasksWithProgress = $taskProgress->count();
        $averageProgress = $taskProgress->avg('percentage') ?? 0;
        $completedTasks = $taskProgress->filter(fn ($t) => $t['percentage'] >= 100)->count();

        $stats = [
            'total_tasks' => $totalLeafTasks,
            'tasks_with_progress' => $tasksWithProgress,
            'average_progress' => round($averageProgress, 2),
            'completed_tasks' => $completedTasks,
            'completion_rate' => $totalLeafTasks > 0 ? round(($completedTasks / $totalLeafTasks) * 100, 2) : 0,
        ];

        // Calculate hierarchical progress (month -> week -> day)
        $hierarchicalProgress = $this->calculateHierarchicalProgress();

        // Calculate S-curve data
        $sCurveData = $this->calculateSCurveData();

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
            'hierarchicalProgress' => $hierarchicalProgress,
            'sCurveData' => $sCurveData,
            'tasksWithoutProgress' => $tasksWithoutProgress,
        ];
    }

    private function getTaskBreadcrumb(Task $task): string
    {
        $breadcrumb = [$task->name];
        $current = $task;

        while ($current->parent_id) {
            $parent = Task::find($current->parent_id);
            if (! $parent) {
                break;
            }
            $breadcrumb[] = $parent->name;
            $current = $parent;
        }

        // Reverse to show root -> leaf
        return implode(' > ', array_reverse($breadcrumb));
    }

    private function getTasksWithoutProgress(): array
    {
        if (! $this->selectedProjectId) {
            return [];
        }

        // Get all leaf tasks
        $leafTasks = Task::whereDoesntHave('children')
            ->with('parent')
            ->orderBy('_lft')
            ->get();

        // Get task IDs that have progress for this project
        $taskIdsWithProgress = TaskProgress::where('project_id', $this->selectedProjectId)
            ->distinct()
            ->pluck('task_id')
            ->toArray();

        // Filter leaf tasks without progress and group by root task
        $tasksWithoutProgress = [];

        foreach ($leafTasks as $task) {
            if (! in_array($task->id, $taskIdsWithProgress)) {
                // Find root task
                $rootTask = $task;
                $parentChain = [$task->name];

                while ($rootTask->parent_id) {
                    $parent = Task::find($rootTask->parent_id);
                    if (! $parent) {
                        break;
                    }
                    $parentChain[] = $parent->name;
                    $rootTask = $parent;
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
        $current = $task;

        while ($current->parent_id) {
            $parent = Task::find($current->parent_id);
            if (! $parent) {
                break;
            }
            $current = $parent;
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

        // Calculate average progress for each root task
        foreach ($grouped as $rootName => $data) {
            $totalPercentage = array_sum(array_column($data['tasks'], 'percentage'));
            $grouped[$rootName]['avg_progress'] = $data['task_count'] > 0
                ? round($totalPercentage / $data['task_count'], 2)
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
            <!-- Kodam Filter -->
            <flux:select wire:model.live="selectedKodamId" label="Kodam">
                <option value="">All Kodam</option>
                @foreach($kodams as $kodam)
                    <option value="{{ $kodam->id }}">
                        {{ $kodam->name }}
                    </option>
                @endforeach
            </flux:select>

            <!-- Korem Filter (enabled only if Kodam is selected) -->
            <flux:select wire:model.live="selectedKoremId" label="Korem" :disabled="!$this->selectedKodamId">
                <option value="">{{ $this->selectedKodamId ? 'Select Korem...' : 'Select Kodam first' }}</option>
                @foreach($korems as $korem)
                    <option value="{{ $korem->id }}">
                        {{ $korem->name }}
                    </option>
                @endforeach
            </flux:select>

            <!-- Kodim Filter (enabled only if Korem is selected) -->
            <flux:select wire:model.live="selectedKodimId" label="Kodim" :disabled="!$this->selectedKoremId">
                <option value="">{{ $this->selectedKoremId ? 'Select Kodim...' : 'Select Korem first' }}</option>
                @foreach($kodims as $kodim)
                    <option value="{{ $kodim->id }}">
                        {{ $kodim->name }}
                    </option>
                @endforeach
            </flux:select>

            <!-- Koramil Filter (enabled only if Kodim is selected) -->
            <flux:select wire:model.live="selectedKoramilId" label="Koramil" :disabled="!$this->selectedKodimId">
                <option value="">{{ $this->selectedKodimId ? 'All Koramil' : 'Select Kodim first' }}</option>
                @foreach($koramils as $koramil)
                    <option value="{{ $koramil->id }}">
                        {{ $koramil->name }}
                    </option>
                @endforeach
            </flux:select>

            <!-- Location Filter -->
            <flux:select wire:model.live="selectedLocationId" label="Location">
                <option value="">All Locations</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}">
                        {{ $location->city_name }}
                    </option>
                @endforeach
            </flux:select>

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

        @if($selectedProjectId && $stats)
            <!-- Statistics Cards -->
            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Total Leaf Tasks</div>
                    <div class="mt-2 text-3xl font-bold text-neutral-900 dark:text-neutral-100">
                        {{ number_format($stats['total_tasks']) }}
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Work items to track</div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Tasks with Progress</div>
                    <div class="mt-2 text-3xl font-bold text-blue-600 dark:text-blue-400">
                        {{ number_format($stats['tasks_with_progress']) }}
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Work items started</div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Average Progress</div>
                    <div class="mt-2 text-3xl font-bold text-green-600 dark:text-green-400">
                        {{ number_format($stats['average_progress'], 1) }}%
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Mean completion per task</div>
                </div>

                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Completion Rate</div>
                    <div class="mt-2 text-3xl font-bold text-purple-600 dark:text-purple-400">
                        {{ number_format($stats['completion_rate'], 1) }}%
                    </div>
                    <div class="mt-1 text-xs text-neutral-500">Tasks 100% complete</div>
                </div>
            </div>

            <!-- S-Curve Chart -->
            @if(!empty($sCurveData['labels']))
                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900" wire:key="scurve-{{ $selectedProjectId }}-{{ md5(json_encode($sCurveData)) }}">
                    <flux:heading size="lg" class="mb-4">S-Curve Progress</flux:heading>
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

            <!-- Hierarchical Progress Trend (Monthly -> Weekly -> Daily) -->
            @if(!empty($hierarchicalProgress))
                <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                    <flux:heading size="lg" class="mb-4">Progress Trend</flux:heading>
                    <div class="space-y-3">
                        @foreach($hierarchicalProgress as $month)
                            <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                                <!-- Month Header (Clickable) -->
                                <button
                                    wire:click="toggleMonth('{{ $month['key'] }}')"
                                    class="flex w-full items-center justify-between bg-neutral-100 p-4 text-left transition-colors hover:bg-neutral-200 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                                >
                                    <div class="flex items-center gap-3">
                                        <svg
                                            class="h-5 w-5 transform text-neutral-500 transition-transform duration-200 dark:text-neutral-400 {{ isset($this->expandedMonths[$month['key']]) ? 'rotate-90' : '' }}"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                        <div>
                                            <div class="font-semibold text-neutral-900 dark:text-neutral-100">
                                                {{ $month['name'] }}
                                            </div>
                                            <div class="text-sm text-neutral-600 dark:text-neutral-400">
                                                {{ $month['day_count'] }} {{ Str::plural('day', $month['day_count']) }} with data
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <span class="text-sm font-medium text-neutral-600 dark:text-neutral-400">
                                            {{ number_format($month['avg_percentage'], 1) }}%
                                        </span>
                                        <div class="h-3 w-32 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                                            <div
                                                class="h-full rounded-full bg-gradient-to-r from-blue-500 to-green-500 transition-all"
                                                style="width: {{ min($month['avg_percentage'], 100) }}%"
                                            ></div>
                                        </div>
                                    </div>
                                </button>

                                <!-- Week Content (Expandable) -->
                                @if(isset($this->expandedMonths[$month['key']]))
                                    <div class="border-t border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="space-y-2">
                                            @foreach($month['weeks'] as $week)
                                                <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-600">
                                                    <!-- Week Header (Clickable) -->
                                                    <button
                                                        wire:click="toggleWeek('{{ $week['key'] }}')"
                                                        class="flex w-full items-center justify-between bg-neutral-50 p-3 text-left transition-colors hover:bg-neutral-100 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                                                    >
                                                        <div class="flex items-center gap-2">
                                                            <svg
                                                                class="h-4 w-4 transform text-neutral-500 transition-transform duration-200 dark:text-neutral-400 {{ isset($this->expandedWeeks[$week['key']]) ? 'rotate-90' : '' }}"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                                            </svg>
                                                            <div>
                                                                <div class="font-medium text-neutral-900 dark:text-neutral-100">
                                                                    {{ $week['name'] }}
                                                                </div>
                                                                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                                                    {{ $week['day_count'] }} {{ Str::plural('day', $week['day_count']) }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-center gap-3">
                                                            <span class="text-sm font-medium text-neutral-600 dark:text-neutral-400">
                                                                {{ number_format($week['avg_percentage'], 1) }}%
                                                            </span>
                                                            <div class="h-2 w-24 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                                                                <div
                                                                    class="h-full rounded-full bg-gradient-to-r from-blue-500 to-green-500 transition-all"
                                                                    style="width: {{ min($week['avg_percentage'], 100) }}%"
                                                                ></div>
                                                            </div>
                                                        </div>
                                                    </button>

                                                    <!-- Day Content (Expandable) -->
                                                    @if(isset($this->expandedWeeks[$week['key']]))
                                                        <div class="border-t border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-600 dark:bg-neutral-800">
                                                            <div class="space-y-2">
                                                                @foreach($week['days'] as $day)
                                                                    <div class="flex items-center justify-between rounded bg-white p-2 dark:bg-neutral-900">
                                                                        <span class="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                                                            {{ $day['formatted_date'] }}
                                                                        </span>
                                                                        <div class="flex items-center gap-3">
                                                                            <span class="text-sm text-neutral-600 dark:text-neutral-400">
                                                                                {{ number_format($day['avg_percentage'], 1) }}% ({{ $day['task_count'] }} tasks)
                                                                            </span>
                                                                            <div class="h-2 w-20 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                                                                                <div
                                                                                    class="h-full rounded-full bg-gradient-to-r from-blue-500 to-green-500 transition-all"
                                                                                    style="width: {{ min($day['avg_percentage'], 100) }}%"
                                                                                ></div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Task Progress Details -->
            <div
                class="rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900"
                x-data="{
                    expandedRoots: {},
                    showAllRoots: false,
                    defaultVisibleCount: 5,
                    toggleRoot(rootId) {
                        this.expandedRoots[rootId] = !this.expandedRoots[rootId];
                    },
                    isExpanded(rootId) {
                        return this.expandedRoots[rootId] || false;
                    }
                }"
            >
                <div class="border-b border-neutral-200 p-6 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">Task Progress Details</flux:heading>
                        @if(!empty($taskProgressByRoot))
                            <div class="text-sm text-neutral-600 dark:text-neutral-400">
                                {{ count($taskProgressByRoot) }} root task groups
                            </div>
                        @endif
                    </div>
                </div>
                <div class="p-6">
                    @if(empty($taskProgressByRoot))
                        <div class="py-12 text-center text-neutral-600 dark:text-neutral-400">
                            No progress data available for the selected period.
                        </div>
                    @else
                        <div class="space-y-4">
                            @php $rootIndex = 0; @endphp
                            @foreach($taskProgressByRoot as $rootName => $rootData)
                                <div
                                    x-show="showAllRoots || {{ $rootIndex }} < defaultVisibleCount"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 transform -translate-y-2"
                                    x-transition:enter-end="opacity-100 transform translate-y-0"
                                    class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700"
                                    wire:key="root-{{ $rootData['root_id'] }}"
                                >
                                    <!-- Root Task Header (Collapsible) -->
                                    <button
                                        @click="toggleRoot({{ $rootData['root_id'] }})"
                                        class="flex w-full items-center justify-between bg-neutral-100 p-4 text-left transition-colors hover:bg-neutral-200 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                                    >
                                        <div class="flex items-center gap-3">
                                            <svg
                                                class="h-5 w-5 transform text-neutral-500 transition-transform duration-200 dark:text-neutral-400"
                                                :class="{ 'rotate-90': isExpanded({{ $rootData['root_id'] }}) }"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                            </svg>
                                            <div>
                                                <div class="font-semibold text-neutral-900 dark:text-neutral-100">
                                                    {{ $rootName }}
                                                </div>
                                                <div class="text-sm text-neutral-600 dark:text-neutral-400">
                                                    {{ $rootData['task_count'] }} {{ Str::plural('task', $rootData['task_count']) }} with progress
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-4">
                                            <div class="text-right">
                                                <div class="text-lg font-bold
                                                    @if($rootData['avg_progress'] >= 100) text-green-600 dark:text-green-400
                                                    @elseif($rootData['avg_progress'] >= 75) text-blue-600 dark:text-blue-400
                                                    @elseif($rootData['avg_progress'] >= 50) text-yellow-600 dark:text-yellow-400
                                                    @else text-red-600 dark:text-red-400
                                                    @endif">
                                                    {{ number_format($rootData['avg_progress'], 1) }}%
                                                </div>
                                                <div class="text-xs text-neutral-500">avg progress</div>
                                            </div>
                                            <div class="h-2 w-24 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-600">
                                                <div
                                                    class="h-full rounded-full transition-all
                                                        @if($rootData['avg_progress'] >= 100) bg-green-500
                                                        @elseif($rootData['avg_progress'] >= 75) bg-blue-500
                                                        @elseif($rootData['avg_progress'] >= 50) bg-yellow-500
                                                        @else bg-red-500
                                                        @endif"
                                                    style="width: {{ min($rootData['avg_progress'], 100) }}%"
                                                ></div>
                                            </div>
                                        </div>
                                    </button>

                                    <!-- Collapsible Task Cards -->
                                    <div
                                        x-show="isExpanded({{ $rootData['root_id'] }})"
                                        x-collapse
                                        class="border-t border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <div class="space-y-3">
                                            @foreach($rootData['tasks'] as $item)
                                                <div
                                                    class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-600 dark:bg-neutral-800"
                                                    wire:key="task-{{ $item['task']->id }}"
                                                >
                                                    <div class="mb-2 flex items-start justify-between">
                                                        <div class="flex-1">
                                                            <div class="font-medium text-neutral-900 dark:text-neutral-100">
                                                                {{ $item['task']->name }}
                                                            </div>
                                                            <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                                                {{ $item['breadcrumb'] }}
                                                            </div>
                                                            <div class="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                                                                Last updated: {{ $item['progress_date']->format('M d, Y') }}
                                                                @if($item['notes'])
                                                                    <br>Notes: {{ $item['notes'] }}
                                                                @endif
                                                            </div>
                                                        </div>
                                                        <div class="ml-4 text-right">
                                                            <div class="text-2xl font-bold
                                                                @if($item['percentage'] >= 100) text-green-600 dark:text-green-400
                                                                @elseif($item['percentage'] >= 75) text-blue-600 dark:text-blue-400
                                                                @elseif($item['percentage'] >= 50) text-yellow-600 dark:text-yellow-400
                                                                @else text-red-600 dark:text-red-400
                                                                @endif">
                                                                {{ number_format($item['percentage'], 1) }}%
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="h-2 w-full overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                                                        <div
                                                            class="h-full rounded-full transition-all
                                                                @if($item['percentage'] >= 100) bg-green-500
                                                                @elseif($item['percentage'] >= 75) bg-blue-500
                                                                @elseif($item['percentage'] >= 50) bg-yellow-500
                                                                @else bg-red-500
                                                                @endif"
                                                            style="width: {{ min($item['percentage'], 100) }}%"
                                                        ></div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                @php $rootIndex++; @endphp
                            @endforeach
                        </div>

                        <!-- Show More / Show Less Button -->
                        @if(count($taskProgressByRoot) > 5)
                            <div class="mt-4 text-center">
                                <flux:button
                                    variant="outline"
                                    size="sm"
                                    @click="showAllRoots = !showAllRoots"
                                >
                                    <span x-show="!showAllRoots">
                                        Show {{ count($taskProgressByRoot) - 5 }} More Root Tasks
                                    </span>
                                    <span x-show="showAllRoots">
                                        Show Less
                                    </span>
                                </flux:button>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

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
        @else
            <div class="rounded-xl border border-neutral-200 bg-neutral-50 p-12 text-center dark:border-neutral-700 dark:bg-neutral-800">
                <p class="text-neutral-600 dark:text-neutral-400">
                    Please select a project to view the dashboard.
                </p>
            </div>
        @endif
</div>

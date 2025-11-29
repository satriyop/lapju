<?php

use App\Exports\LapjusikExport;
use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Maatwebsite\Excel\Facades\Excel;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public ?int $projectId = null;

    public int $selectedMonth;

    public int $selectedYear;

    // Cascading office filters
    public ?int $selectedKodamId = null;

    public ?int $selectedKoremId = null;

    public ?int $selectedKodimId = null;

    public ?int $selectedKoramilId = null;

    // Lock filters for Managers to their Kodim coverage
    public bool $filtersLocked = false;

    public function mount(): void
    {
        $this->selectedMonth = now()->month;
        $this->selectedYear = now()->year;

        $currentUser = auth()->user();

        // Reporters don't use office filters - just their assigned projects
        if ($currentUser->hasRole('Reporter')) {
            return;
        }

        // Managers at Kodim level - set defaults to their Kodim and lock filters
        if ($currentUser->office_id) {
            $userOffice = Office::with('level', 'parent.parent')->find($currentUser->office_id);

            if ($userOffice && $userOffice->level->level === 3) {
                $this->selectedKodimId = $userOffice->id;

                if ($userOffice->parent) {
                    $this->selectedKoremId = $userOffice->parent->id;

                    if ($userOffice->parent->parent) {
                        $this->selectedKodamId = $userOffice->parent->parent->id;
                    }
                }

                $this->filtersLocked = true;
            }
        }

        // If not a Manager or no office assigned, set default filters
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

    public function updatedSelectedKodamId(): void
    {
        if ($this->filtersLocked) {
            return;
        }

        $this->selectedKoremId = null;
        $this->selectedKodimId = null;
        $this->selectedKoramilId = null;
        $this->projectId = null;
    }

    public function updatedSelectedKoremId(): void
    {
        if ($this->filtersLocked) {
            return;
        }

        $this->selectedKodimId = null;
        $this->selectedKoramilId = null;
        $this->projectId = null;
    }

    public function updatedSelectedKodimId(): void
    {
        if ($this->filtersLocked) {
            return;
        }

        $this->selectedKoramilId = null;
        $this->projectId = null;
    }

    public function updatedSelectedKoramilId(): void
    {
        $this->projectId = null;
    }

    // Cache for office levels
    private ?object $cachedOfficeLevels = null;

    /**
     * Get office levels (cached)
     */
    private function getOfficeLevels(): object
    {
        if ($this->cachedOfficeLevels === null) {
            $levels = OfficeLevel::all();

            $this->cachedOfficeLevels = (object) [
                'kodam' => $levels->firstWhere('level', 1),
                'korem' => $levels->firstWhere('level', 2),
                'kodim' => $levels->firstWhere('level', 3),
                'koramil' => $levels->firstWhere('level', 4),
            ];
        }

        return $this->cachedOfficeLevels;
    }

    // Cache for user office level check
    private ?int $cachedUserOfficeLevel = null;

    /**
     * Get accessible projects based on user role and office filters
     */
    private function getAccessibleProjects()
    {
        $currentUser = Auth::user();

        // Reporters only see their assigned projects
        if ($currentUser->hasRole('Reporter')) {
            return $currentUser->projects()
                ->select(['projects.id', 'projects.name', 'projects.office_id', 'projects.location_id', 'projects.partner_id'])
                ->orderBy('name')
                ->get();
        }

        $query = Project::select(['id', 'name', 'office_id', 'location_id', 'partner_id']);

        // Managers at Kodim level - show Koramils under their Kodim
        if ($currentUser->hasRole('Manager') && $currentUser->office_id) {
            // Cache the user office level check
            if ($this->cachedUserOfficeLevel === null) {
                $this->cachedUserOfficeLevel = Office::where('id', $currentUser->office_id)
                    ->join('office_levels', 'offices.level_id', '=', 'office_levels.id')
                    ->value('office_levels.level') ?? 0;
            }

            if ($this->cachedUserOfficeLevel === 3) {
                $koramils = Office::where('parent_id', $currentUser->office_id)->pluck('id');
                $query->whereIn('office_id', $koramils);

                if ($this->selectedKoramilId) {
                    $query->where('office_id', $this->selectedKoramilId);
                }

                return $query->orderBy('name')->get();
            }
        }

        // Apply cascading office filters
        if ($this->selectedKoramilId) {
            $query->where('office_id', $this->selectedKoramilId);
        } elseif ($this->selectedKodimId) {
            $koramils = Office::where('parent_id', $this->selectedKodimId)->pluck('id');
            $query->whereIn('office_id', $koramils);
        } elseif ($this->selectedKoremId) {
            $kodims = Office::where('parent_id', $this->selectedKoremId)->pluck('id');
            $koramils = Office::whereIn('parent_id', $kodims)->pluck('id');
            $query->whereIn('office_id', $koramils);
        } elseif ($this->selectedKodamId) {
            $korems = Office::where('parent_id', $this->selectedKodamId)->pluck('id');
            $kodims = Office::whereIn('parent_id', $korems)->pluck('id');
            $koramils = Office::whereIn('parent_id', $kodims)->pluck('id');
            $query->whereIn('office_id', $koramils);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Get calendar days for the selected month
     */
    private function getCalendarDays(): array
    {
        $startOfMonth = Carbon::create($this->selectedYear, $this->selectedMonth, 1);
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $days = [];
        $period = CarbonPeriod::create($startOfMonth, $endOfMonth);

        foreach ($period as $date) {
            $days[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->day,
                'dayName' => strtoupper(substr($date->locale('id')->dayName, 0, 3)),
                'isWeekend' => $date->isWeekend(),
                'isSunday' => $date->isSunday(),
            ];
        }

        return $days;
    }

    /**
     * Get hierarchical tasks with progress data (daily incremental values)
     */
    private function getTasksWithProgress(): array
    {
        if (! $this->projectId) {
            return [];
        }

        // Get all tasks for the project (select only needed columns)
        $tasks = Task::where('project_id', $this->projectId)
            ->select(['id', 'project_id', 'parent_id', 'name', 'volume', 'unit', 'weight', '_lft', '_rgt'])
            ->orderBy('_lft')
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        // Pre-compute which tasks have children using in-memory lookup (no N+1)
        $parentIds = $tasks->pluck('parent_id')->filter()->unique()->toArray();

        $startOfMonth = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

        // Get last progress entry BEFORE the month starts for each task (for cross-month calculation)
        $lastBeforeMonth = TaskProgress::where('project_id', $this->projectId)
            ->where('progress_date', '<', $startOfMonth)
            ->select(['task_id', 'percentage', 'progress_date'])
            ->orderBy('progress_date', 'desc')
            ->get()
            ->unique('task_id')
            ->keyBy('task_id');

        // Get all progress entries for the current month
        $monthProgress = TaskProgress::where('project_id', $this->projectId)
            ->whereBetween('progress_date', [$startOfMonth, $endOfMonth])
            ->select(['task_id', 'progress_date', 'percentage'])
            ->orderBy('progress_date')
            ->get()
            ->groupBy('task_id');

        // Pre-compute calendar days once (not inside loop)
        $calendarDays = $this->getCalendarDays();

        // Index tasks by ID for fast parent lookup
        $tasksById = $tasks->keyBy('id');

        // Build hierarchical structure
        $result = [];
        $leafCounters = [];

        foreach ($tasks as $task) {
            // Calculate depth using indexed lookup
            $depth = 0;
            $currentId = $task->parent_id;

            while ($currentId !== null) {
                $depth++;
                $parent = $tasksById[$currentId] ?? null;
                $currentId = $parent?->parent_id;
            }

            // Check if task has children using pre-computed parent IDs (no query)
            $hasChildren = in_array($task->id, $parentIds);

            // Generate numbering only for leaf tasks
            $numbering = '';
            if (! $hasChildren && $task->parent_id !== null) {
                if (! isset($leafCounters[$task->parent_id])) {
                    $leafCounters[$task->parent_id] = 0;
                }
                $leafCounters[$task->parent_id]++;
                $numbering = (string) $leafCounters[$task->parent_id];
            }

            // Calculate daily incremental progress for this task
            $dailyProgress = [];

            // Parent tasks always show blank
            if ($hasChildren) {
                foreach ($calendarDays as $day) {
                    $dailyProgress[$day['date']] = null;
                }
            } else {
                // Get task's progress entries for the month
                $taskMonthProgress = $monthProgress[$task->id] ?? collect();

                // Index by date for O(1) lookup
                $progressByDate = $taskMonthProgress->keyBy(function ($p) {
                    $date = $p->progress_date;

                    return is_string($date) ? Carbon::parse($date)->format('Y-m-d') : $date->format('Y-m-d');
                });

                // Get the baseline (last entry before this month, or 0)
                $lastBefore = $lastBeforeMonth[$task->id] ?? null;

                foreach ($calendarDays as $day) {
                    $dateKey = $day['date'];

                    // No entry for this date = blank
                    if (! isset($progressByDate[$dateKey])) {
                        $dailyProgress[$dateKey] = null;
                        continue;
                    }

                    // Get current cumulative percentage (cap at 100%)
                    $currentPercentage = min(100, (float) $progressByDate[$dateKey]->percentage);

                    // Find previous entry (could be earlier in month OR before month)
                    $previousPercentage = 0;

                    // Check for earlier entries this month
                    $previousEntry = $taskMonthProgress
                        ->filter(function ($p) use ($dateKey) {
                            $pDate = $p->progress_date;
                            $pDateStr = is_string($pDate) ? Carbon::parse($pDate)->format('Y-m-d') : $pDate->format('Y-m-d');

                            return $pDateStr < $dateKey;
                        })
                        ->sortByDesc(function ($p) {
                            return is_string($p->progress_date) ? $p->progress_date : $p->progress_date->format('Y-m-d');
                        })
                        ->first();

                    if ($previousEntry) {
                        $previousPercentage = min(100, (float) $previousEntry->percentage);
                    } elseif ($lastBefore) {
                        // Use last entry before month as baseline
                        $previousPercentage = min(100, (float) $lastBefore->percentage);
                    }

                    // Calculate daily change (can be negative for corrections)
                    $dailyProgress[$dateKey] = round($currentPercentage - $previousPercentage, 2);
                }
            }

            $result[] = [
                'id' => $task->id,
                'name' => $task->name,
                'numbering' => $numbering,
                'depth' => $depth,
                'hasChildren' => $hasChildren,
                'volume' => $task->volume,
                'unit' => $task->unit,
                'weight' => $task->weight,
                'dailyProgress' => $dailyProgress,
            ];
        }

        return $result;
    }

    /**
     * Navigate to previous month
     */
    public function previousMonth(): void
    {
        $date = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->subMonth();
        $this->selectedMonth = $date->month;
        $this->selectedYear = $date->year;
    }

    /**
     * Navigate to next month
     */
    public function nextMonth(): void
    {
        $date = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->addMonth();
        $this->selectedMonth = $date->month;
        $this->selectedYear = $date->year;
    }

    /**
     * Export to Excel (.xlsx)
     */
    public function exportExcel()
    {
        if (! $this->projectId) {
            return;
        }

        $project = Project::with(['location', 'partner'])->find($this->projectId);
        if (! $project) {
            return;
        }

        $monthName = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->locale('id')->translatedFormat('F');
        $filename = 'lapjusik_harian_'.Str::slug($project->name).'_'.$monthName.'_'.$this->selectedYear.'.xlsx';

        return Excel::download(
            new LapjusikExport($project, $this->selectedMonth, $this->selectedYear),
            $filename
        );
    }

    public function with(): array
    {
        // Only load project with minimal relations when selected
        $project = null;
        if ($this->projectId) {
            $project = Project::select(['id', 'name', 'location_id', 'partner_id', 'office_id'])
                ->with([
                    'location:id,province_name',
                ])
                ->find($this->projectId);
        }

        // Get office levels (cached)
        $levels = $this->getOfficeLevels();
        $kodamLevel = $levels->kodam;
        $koremLevel = $levels->korem;
        $kodimLevel = $levels->kodim;
        $koramilLevel = $levels->koramil;

        // Cascading office filters - only query what's needed
        $kodams = $kodamLevel
            ? Office::select('id', 'name')->where('level_id', $kodamLevel->id)->orderBy('name')->get()
            : collect();

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

        return [
            'projects' => $this->getAccessibleProjects(),
            'calendarDays' => $this->getCalendarDays(),
            'tasksWithProgress' => $this->getTasksWithProgress(),
            'selectedProject' => $project,
            'monthName' => Carbon::create($this->selectedYear, $this->selectedMonth, 1)->locale('id')->translatedFormat('F'),
            'kodams' => $kodams,
            'korems' => $korems,
            'kodims' => $kodims,
            'koramils' => $koramils,
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6" x-data="{ showFilters: !@js($projectId) && window.innerWidth >= 1024 }" x-init="$watch('$wire.projectId', value => { if (value) showFilters = false })">
    {{-- Header with Filter Toggle --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-neutral-900 dark:text-white sm:text-3xl">
                Lapjusik
            </h1>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                Laporan Kemajuan Fisik
            </p>
        </div>

        <div class="flex items-center gap-3">
            {{-- Filter Toggle Button --}}
            <button
                @click="showFilters = !showFilters"
                class="flex items-center gap-2 rounded-lg border border-neutral-300 bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-sm transition hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
            >
                <flux:icon.funnel class="h-4 w-4" />
                <span x-text="showFilters ? 'Sembunyikan Filter' : 'Tampilkan Filter'"></span>
                <flux:icon.chevron-down class="h-4 w-4 transition-transform" x-bind:class="showFilters ? 'rotate-180' : ''" />
            </button>
        </div>
    </div>

    {{-- Filters Panel --}}
    <div
        x-show="showFilters"
        x-collapse
    >
        <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800/50">
            <div class="mb-4 flex items-center gap-2">
                <flux:icon.adjustments-horizontal class="h-5 w-5 text-neutral-500" />
                <span class="text-sm font-semibold text-neutral-700 dark:text-neutral-300">Filter Data</span>
            </div>

            @if(!auth()->user()->hasRole('Reporter'))
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
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
                        <flux:select wire:model.live="selectedKoremId" label="Korem" :disabled="$filtersLocked || !$selectedKodamId" size="sm">
                            <option value="">{{ $selectedKodamId ? 'Pilih Korem...' : 'Pilih Kodam dulu' }}</option>
                            @foreach($korems as $korem)
                                <option value="{{ $korem->id }}">{{ $korem->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>

                    {{-- Kodim --}}
                    <div>
                        <flux:select wire:model.live="selectedKodimId" label="Kodim" :disabled="$filtersLocked || !$selectedKoremId" size="sm">
                            <option value="">{{ $selectedKoremId ? 'Semua Kodim' : 'Pilih Korem dulu' }}</option>
                            @foreach($kodims as $kodim)
                                <option value="{{ $kodim->id }}">{{ $kodim->name }} ({{ $kodim->projects_count }})</option>
                            @endforeach
                        </flux:select>
                    </div>

                    {{-- Koramil --}}
                    <div>
                        <flux:select wire:model.live="selectedKoramilId" label="Koramil" :disabled="!$selectedKodimId" size="sm">
                            <option value="">{{ $selectedKodimId ? 'Semua Koramil' : 'Pilih Kodim dulu' }}</option>
                            @foreach($koramils as $koramil)
                                <option value="{{ $koramil->id }}">{{ $koramil->name }} ({{ $koramil->projects_count }})</option>
                            @endforeach
                        </flux:select>
                    </div>

                    {{-- Project --}}
                    <div>
                        <flux:select wire:model.live="projectId" label="Project" size="sm">
                            <option value="">Pilih Project...</option>
                            @foreach($projects as $proj)
                                <option value="{{ $proj->id }}">{{ $proj->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>

                    {{-- Period --}}
                    <div>
                        <flux:field>
                            <flux:label>Periode</flux:label>
                            <div class="flex items-center gap-1">
                                <flux:button wire:click="previousMonth" size="sm" variant="ghost" icon="chevron-left" />
                                <div class="flex-1 rounded-lg border border-neutral-200 bg-white px-2 py-1.5 text-center text-sm font-semibold text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    {{ strtoupper($monthName) }}
                                </div>
                                <flux:button wire:click="nextMonth" size="sm" variant="ghost" icon="chevron-right" />
                            </div>
                        </flux:field>
                    </div>
                </div>
            @else
                {{-- Reporter: Only project and period filter --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {{-- Project --}}
                    <div>
                        <flux:select wire:model.live="projectId" label="Project" size="sm">
                            <option value="">Pilih Project...</option>
                            @foreach($projects as $proj)
                                <option value="{{ $proj->id }}">{{ $proj->name }}</option>
                            @endforeach
                        </flux:select>
                    </div>

                    {{-- Period --}}
                    <div>
                        <flux:field>
                            <flux:label>Periode</flux:label>
                            <div class="flex items-center gap-1">
                                <flux:button wire:click="previousMonth" size="sm" variant="ghost" icon="chevron-left" />
                                <div class="flex-1 rounded-lg border border-neutral-200 bg-white px-2 py-1.5 text-center text-sm font-semibold text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                                    {{ strtoupper($monthName) }} 
                                </div>
                                <flux:button wire:click="nextMonth" size="sm" variant="ghost" icon="chevron-right" />
                            </div>
                        </flux:field>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Project Info Cards --}}
    @if($selectedProject)
        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex items-start justify-between">
                <div class="grid flex-1 grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Pekerjaan</p>
                        <p class="mt-1 font-semibold text-neutral-900 dark:text-neutral-100">{{ $selectedProject->name }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Lokasi</p>
                        <p class="mt-1 font-semibold text-neutral-900 dark:text-neutral-100">{{ $selectedProject->location?->province_name ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wider text-neutral-500 dark:text-neutral-400">Tahun Anggaran</p>
                        <p class="mt-1 font-semibold text-neutral-900 dark:text-neutral-100">{{ $selectedYear }}</p>
                    </div>
                </div>
                <flux:button wire:click="exportExcel" variant="primary" icon="arrow-down-tray">
                    Export Excel
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Main Content --}}
    @if(!$projectId)
        {{-- Empty State --}}
        <div class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-neutral-300 bg-neutral-50 py-12 dark:border-neutral-700 dark:bg-neutral-800/50">
            <svg class="h-12 w-12 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="mt-4 text-lg font-medium text-neutral-600 dark:text-neutral-400">
                {{ __('Select a Project') }}
            </p>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-500">
                {{ __('Please select a project to view daily progress report') }}
            </p>
        </div>
    @else
        {{-- Spreadsheet Table --}}
        <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-xs" style="min-width: {{ 48 + 250 + (count($calendarDays) * 56) }}px;">
                {{-- Table Header --}}
                <thead class="sticky top-0 z-30">
                    {{-- Month Header Row --}}
                    <tr class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                        <th rowspan="2" class="w-12 min-w-[48px] border-r border-neutral-200 px-2 py-3 text-center text-sm font-semibold text-neutral-900 dark:border-neutral-700 dark:text-neutral-100">
                            NO
                        </th>
                        <th rowspan="2" class="sticky left-0 z-40 w-[250px] min-w-[250px] border-r border-neutral-200 bg-neutral-50 px-3 py-3 text-left text-sm font-semibold text-neutral-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100">
                            URAIAN PEKERJAAN
                        </th>
                        <th colspan="{{ count($calendarDays) }}" class="px-2 py-2 text-center text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            {{ strtoupper($monthName) }}
                        </th>
                    </tr>
                    {{-- Days Header Row --}}
                    <tr class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                        @foreach($calendarDays as $day)
                            <th class="w-14 min-w-[56px] border-r border-neutral-200 px-1 py-2 text-center dark:border-neutral-700
                                {{ $day['isSunday'] ? 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400' : ($day['isWeekend'] ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400' : '') }}">
                                <div class="text-[10px] font-medium text-neutral-500 dark:text-neutral-400">{{ $day['dayName'] }}</div>
                                <div class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $day['day'] }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                {{-- Table Body --}}
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse($tasksWithProgress as $index => $task)
                        @php
                            $isParent = $task['hasChildren'];
                            $depth = $task['depth'];
                            $bgClass = $isParent
                                ? 'bg-neutral-100 dark:bg-neutral-800'
                                : '';
                        @endphp
                        <tr class="{{ $bgClass }} hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            {{-- NO Column - Only show sequential number for leaf tasks --}}
                            <td class="w-12 min-w-[48px] border-r border-neutral-200 px-2 py-2 text-center text-sm dark:border-neutral-700
                                {{ $isParent ? 'bg-neutral-100 dark:bg-neutral-800' : '' }}">
                                @if(!$isParent && $task['numbering'])
                                    <span class="text-neutral-600 dark:text-neutral-400">
                                        {{ $task['numbering'] }}
                                    </span>
                                @endif
                            </td>
                            {{-- URAIAN PEKERJAAN Column --}}
                            <td class="sticky left-0 z-10 w-[250px] min-w-[250px] border-r border-neutral-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900
                                {{ $isParent ? '!bg-neutral-100 dark:!bg-neutral-800' : '' }}"
                                style="padding-left: {{ 12 + ($depth * 16) }}px">
                                @if($isParent)
                                    <span class="font-semibold text-neutral-900 dark:text-neutral-100">
                                        {{ $task['name'] }}
                                    </span>
                                @else
                                    <div class="flex items-start gap-2">
                                        <span class="text-neutral-400">-</span>
                                        <span class="text-neutral-700 dark:text-neutral-300">{{ $task['name'] }}</span>
                                    </div>
                                @endif
                            </td>
                            {{-- Daily Progress Columns (showing daily incremental values) --}}
                            @foreach($calendarDays as $day)
                                @php
                                    $progress = $task['dailyProgress'][$day['date']] ?? null;
                                    $hasProgress = $progress !== null;
                                    $cellBg = $day['isSunday']
                                        ? 'bg-red-50/50 dark:bg-red-900/10'
                                        : ($day['isWeekend'] ? 'bg-amber-50/30 dark:bg-amber-900/5' : '');

                                    // Determine color based on daily progress value
                                    $progressClass = '';
                                    if ($hasProgress && !$isParent) {
                                        if ($progress < 0) {
                                            $progressClass = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
                                        } elseif ($progress == 0) {
                                            $progressClass = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
                                        } elseif ($progress > 0 && $progress < 5) {
                                            $progressClass = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
                                        } elseif ($progress >= 5 && $progress < 10) {
                                            $progressClass = 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300';
                                        } elseif ($progress >= 10 && $progress < 20) {
                                            $progressClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300';
                                        } elseif ($progress >= 20 && $progress < 50) {
                                            $progressClass = 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300';
                                        } else {
                                            $progressClass = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
                                        }
                                    }
                                @endphp
                                <td class="border-r border-neutral-200 px-1 py-2 text-center dark:border-neutral-700 {{ $cellBg }}">
                                    @if($hasProgress && !$isParent)
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $progressClass }}">
                                            {{ number_format($progress, 2) }}
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 2 + count($calendarDays) }}" class="px-4 py-12 text-center">
                                <div class="flex flex-col items-center">
                                    <svg class="mb-2 h-8 w-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                                    </svg>
                                    <span class="text-sm text-neutral-500 dark:text-neutral-400">Tidak ada data task untuk proyek ini</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Legend --}}
        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Keterangan Progress Harian</h3>
            <div class="mt-3 flex flex-wrap gap-4">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-green-100 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-300">50%+</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">&ge; 50%</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-blue-100 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-300">20%+</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">20-49%</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-yellow-100 text-xs font-medium text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300">10%+</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">10-19%</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-orange-100 text-xs font-medium text-orange-800 dark:bg-orange-900 dark:text-orange-300">5%+</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">5-9%</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-gray-100 text-xs font-medium text-gray-800 dark:bg-gray-800 dark:text-gray-300">0%+</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">0-4%</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-5 w-12 items-center justify-center rounded-full bg-red-100 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-300">-</span>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">Koreksi (negatif)</span>
                </div>
            </div>
            <div class="mt-3 border-t border-neutral-200 pt-3 dark:border-neutral-700">
                <div class="flex flex-wrap gap-4">
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-5 w-8 rounded bg-red-50 dark:bg-red-900/20"></span>
                        <span class="text-sm text-neutral-600 dark:text-neutral-400">Minggu</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-5 w-8 rounded bg-amber-50 dark:bg-amber-900/20"></span>
                        <span class="text-sm text-neutral-600 dark:text-neutral-400">Sabtu</span>
                    </div>
                </div>
            </div>
            <div class="mt-3 border-t border-neutral-200 pt-3 text-sm text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                <strong>Catatan:</strong> Nilai yang ditampilkan adalah progress harian (selisih dari hari sebelumnya), bukan nilai kumulatif.
            </div>
        </div>
    @endif
</div>

<?php

use App\Models\Office;
use App\Models\Project;
use App\Models\TaskProgress;
use Carbon\Carbon;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public ?int $kodamId = null;

    public ?int $koremId = null;

    public ?int $kodimId = null;

    public ?int $koramilId = null;

    public ?int $projectId = null;

    public int $year;

    public int $month;

    public function mount(): void
    {
        $this->year = now()->year;
        $this->month = now()->month;

        $currentUser = auth()->user();

        // Reporters don't use office filters - just their assigned projects
        if ($currentUser->hasRole('Reporter')) {
            return;
        }

        // Managers at Kodim level - set defaults to their Kodim
        if ($currentUser->office_id) {
            $userOffice = Office::with('level', 'parent.parent')->find($currentUser->office_id);

            if ($userOffice && $userOffice->level->level === 3) {
                // Manager at Kodim level - set their hierarchy as defaults
                $this->kodimId = $userOffice->id;

                if ($userOffice->parent) {
                    $this->koremId = $userOffice->parent->id;

                    if ($userOffice->parent->parent) {
                        $this->kodamId = $userOffice->parent->parent->id;
                    }
                }
            }
        }

        // Don't auto-select filters - let user choose manually
    }

    public function updatedKodamId(): void
    {
        $this->koremId = null;
        $this->kodimId = null;
        $this->koramilId = null;
    }

    public function updatedKoremId(): void
    {
        $this->kodimId = null;
        $this->koramilId = null;
    }

    public function updatedKodimId(): void
    {
        $this->koramilId = null;
    }

    public function previousMonth(): void
    {
        $date = Carbon::create($this->year, $this->month, 1)->subMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function nextMonth(): void
    {
        $date = Carbon::create($this->year, $this->month, 1)->addMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function goToToday(): void
    {
        $this->year = now()->year;
        $this->month = now()->month;
    }

    public function with(): array
    {
        // Get office hierarchy
        $kodams = Office::whereHas('level', fn ($q) => $q->where('level', 1))
            ->orderBy('name')
            ->get();

        $korems = $this->kodamId
            ? Office::where('parent_id', $this->kodamId)
                ->whereHas('level', fn ($q) => $q->where('level', 2))
                ->orderBy('name')
                ->get()
            : collect();

        $kodims = $this->koremId
            ? Office::where('parent_id', $this->koremId)
                ->whereHas('level', fn ($q) => $q->where('level', 3))
                ->orderBy('name')
                ->get()
            : collect();

        // For Managers, load koramils under their Kodim; otherwise use selected kodimId
        $currentUser = auth()->user();
        if ($currentUser->hasRole('Manager') && $currentUser->office_id) {
            $userOffice = Office::with('level')->find($currentUser->office_id);
            if ($userOffice && $userOffice->level->level === 3) {
                $koramils = Office::where('parent_id', $currentUser->office_id)
                    ->whereHas('level', fn ($q) => $q->where('level', 4))
                    ->orderBy('name')
                    ->get();
            } else {
                $koramils = collect();
            }
        } else {
            $koramils = $this->kodimId
                ? Office::where('parent_id', $this->kodimId)
                    ->whereHas('level', fn ($q) => $q->where('level', 4))
                    ->orderBy('name')
                    ->get()
                : collect();
        }

        // Get projects based on filters
        $projectsQuery = Project::with('location', 'partner')->orderBy('name');

        // Reporters can only see their assigned projects
        if ($currentUser->hasRole('Reporter')) {
            $projectsQuery->whereHas('users', fn ($q) => $q->where('users.id', auth()->id()));
        }
        // Managers at Kodim level can only see projects in Koramils under their Kodim
        elseif ($currentUser->hasRole('Manager') && $currentUser->office_id) {
            $userOffice = Office::with('level')->find($currentUser->office_id);
            if ($userOffice && $userOffice->level->level === 3) {
                $projectsQuery->whereHas('office', function ($q) use ($currentUser) {
                    $q->where('parent_id', $currentUser->office_id);
                });
            }
        } else {
            // Build office filter for non-reporters/non-managers
            $officeId = $this->koramilId ?? $this->kodimId ?? $this->koremId ?? $this->kodamId;

            if ($officeId) {
                $projectsQuery->whereHas('location', function ($query) use ($officeId) {
                    $office = Office::find($officeId);
                    if ($office && $office->coverage_district) {
                        $query->where('district_name', $office->coverage_district);
                    } elseif ($office && $office->coverage_city) {
                        $query->where('city_name', $office->coverage_city);
                    } elseif ($office && $office->coverage_province) {
                        $query->where('province_name', $office->coverage_province);
                    }
                });
            }
        }

        $projects = $projectsQuery->get();

        $startOfMonth = Carbon::create($this->year, $this->month, 1)->startOfMonth();
        $endOfMonth = Carbon::create($this->year, $this->month, 1)->endOfMonth();

        // Generate calendar grid
        $startOfWeek = $startOfMonth->copy()->startOfWeek(Carbon::SUNDAY);
        $endOfWeek = $endOfMonth->copy()->endOfWeek(Carbon::SUNDAY);

        $calendar = [];
        $currentDate = $startOfWeek->copy();

        // Get project end date for planned calculation
        $project = $this->projectId ? Project::find($this->projectId) : null;

        // Calculate summary stats
        $totalPlanned = 0;
        $totalActual = 0;
        $daysWithData = 0;
        $behindDays = 0;
        $onTrackDays = 0;
        $aheadDays = 0;

        while ($currentDate <= $endOfWeek) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $dateKey = $currentDate->format('Y-m-d');

                // Calculate planned progress (cumulative percentage based on project timeline)
                $plannedProgress = 0;
                if ($project && $project->start_date && $project->end_date) {
                    $startDate = Carbon::parse($project->start_date);
                    $endDate = Carbon::parse($project->end_date);
                    $totalDays = $startDate->diffInDays($endDate);

                    if ($currentDate->gte($startDate) && $totalDays > 0) {
                        $daysPassed = $startDate->diffInDays($currentDate);
                        $plannedProgress = min(100, ($daysPassed / $totalDays) * 100);
                    } elseif ($currentDate->gt($endDate)) {
                        $plannedProgress = 100;
                    }
                }

                // Calculate actual progress
                $actualProgress = 0;
                if ($this->projectId) {
                    $progressQuery = TaskProgress::where('project_id', $this->projectId)
                        ->where('progress_date', '<=', $dateKey);

                    $actualProgress = $progressQuery->avg('percentage') ?? 0;
                }

                // Calculate variance
                $variance = $actualProgress - $plannedProgress;

                // Track stats for current month only
                if ($currentDate->month === $this->month && $this->projectId && $currentDate->lte(now())) {
                    $totalPlanned += $plannedProgress;
                    $totalActual += $actualProgress;
                    $daysWithData++;

                    if ($variance < -5) {
                        $behindDays++;
                    } elseif ($variance > 5) {
                        $aheadDays++;
                    } else {
                        $onTrackDays++;
                    }
                }

                $week[] = [
                    'date' => $currentDate->copy(),
                    'isCurrentMonth' => $currentDate->month === $this->month,
                    'isToday' => $currentDate->isToday(),
                    'isPast' => $currentDate->lt(now()->startOfDay()),
                    'isFuture' => $currentDate->gt(now()),
                    'plannedProgress' => $plannedProgress,
                    'actualProgress' => $actualProgress,
                    'variance' => $variance,
                ];
                $currentDate->addDay();
            }
            $calendar[] = $week;
        }

        return [
            'kodams' => $kodams,
            'korems' => $korems,
            'kodims' => $kodims,
            'koramils' => $koramils,
            'projects' => $projects,
            'project' => $project,
            'calendar' => $calendar,
            'monthName' => Carbon::create($this->year, $this->month, 1)->format('F Y'),
            'currentDate' => now()->format('l, F j, Y'),
            'stats' => [
                'avgPlanned' => $daysWithData > 0 ? $totalPlanned / $daysWithData : 0,
                'avgActual' => $daysWithData > 0 ? $totalActual / $daysWithData : 0,
                'behindDays' => $behindDays,
                'onTrackDays' => $onTrackDays,
                'aheadDays' => $aheadDays,
            ],
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4 p-4 lg:p-6" x-data="{ showFilters: false }">
    {{-- Inline Styles for Custom Fonts & Effects --}}
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Outfit:wght@300;400;500;600;700&display=swap');

        .calendar-progress-page {
            font-family: 'Outfit', sans-serif;
        }

        .calendar-progress-page .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }

        /* Light mode stat card */
        .calendar-progress-page .stat-card {
            background: linear-gradient(135deg, rgba(250, 250, 250, 0.9) 0%, rgba(244, 244, 245, 0.95) 100%);
            border: 1px solid rgba(228, 228, 231, 0.8);
            backdrop-filter: blur(10px);
        }

        /* Dark mode stat card */
        .dark .calendar-progress-page .stat-card {
            background: linear-gradient(135deg, rgba(39, 39, 42, 0.8) 0%, rgba(24, 24, 27, 0.9) 100%);
            border: 1px solid rgba(63, 63, 70, 0.5);
        }

        .calendar-progress-page .calendar-cell {
            transition: all 0.2s ease;
        }

        .calendar-progress-page .calendar-cell:hover {
            transform: scale(1.02);
            z-index: 10;
        }

        .calendar-progress-page .variance-indicator {
            animation: pulse-subtle 2s ease-in-out infinite;
        }

        @keyframes pulse-subtle {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .calendar-progress-page .glow-green {
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.3);
        }

        .calendar-progress-page .glow-red {
            box-shadow: 0 0 20px rgba(244, 63, 94, 0.3);
        }

        .calendar-progress-page .glow-blue {
            box-shadow: 0 0 20px rgba(34, 211, 238, 0.3);
        }

        /* Light mode grid pattern */
        .calendar-progress-page .grid-pattern {
            background-image:
                linear-gradient(rgba(228, 228, 231, 0.5) 1px, transparent 1px),
                linear-gradient(90deg, rgba(228, 228, 231, 0.5) 1px, transparent 1px);
            background-size: 20px 20px;
        }

        /* Dark mode grid pattern */
        .dark .calendar-progress-page .grid-pattern {
            background-image:
                linear-gradient(rgba(63, 63, 70, 0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(63, 63, 70, 0.1) 1px, transparent 1px);
            background-size: 20px 20px;
        }
    </style>

    <div class="calendar-progress-page grid-pattern min-h-full rounded-2xl bg-white p-4 dark:bg-zinc-900 lg:p-6">
        {{-- Header Section --}}
        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-800 dark:text-zinc-100 lg:text-3xl">
                    Calendar Progress
                </h1>
                <p class="font-mono text-sm text-zinc-500">{{ $currentDate }}</p>
            </div>

            <div class="flex items-center gap-3">
                {{-- Filter Toggle (Mobile) --}}
                <button
                    @click="showFilters = !showFilters"
                    class="flex items-center gap-2 rounded-lg border border-zinc-300 bg-zinc-100 px-4 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-200 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 lg:hidden"
                >
                    <flux:icon.funnel class="h-4 w-4" />
                    <span x-text="showFilters ? 'Hide Filters' : 'Filters'"></span>
                </button>

                {{-- Month Navigation --}}
                <div class="flex items-center gap-1 rounded-xl border border-zinc-300 bg-zinc-100/50 p-1 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <button
                        wire:click="previousMonth"
                        class="flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-200 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                    >
                        <flux:icon.chevron-left class="h-5 w-5" />
                    </button>
                    <span class="min-w-[140px] px-3 text-center font-mono text-sm font-semibold tracking-wide text-zinc-700 dark:text-zinc-200">
                        {{ $monthName }}
                    </span>
                    <button
                        wire:click="goToToday"
                        class="rounded-lg bg-cyan-500/20 px-3 py-1.5 text-xs font-semibold text-cyan-600 transition hover:bg-cyan-500/30 dark:text-cyan-400"
                    >
                        TODAY
                    </button>
                    <button
                        wire:click="nextMonth"
                        class="flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-200 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                    >
                        <flux:icon.chevron-right class="h-5 w-5" />
                    </button>
                </div>
            </div>
        </div>

        {{-- Filters Section --}}
        <div
            x-show="showFilters"
            x-collapse
            class="mb-6 lg:!block"
            :class="{ 'hidden': !showFilters }"
            style="display: none;"
            x-init="showFilters = window.innerWidth >= 1024"
        >
            <div class="grid grid-cols-1 gap-3 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700/50 dark:bg-zinc-800/30 sm:grid-cols-2 lg:grid-cols-5 lg:gap-4">
                @if(!auth()->user()->hasRole('Reporter') && !auth()->user()->hasRole('Manager'))
                    {{-- Kodam --}}
                    <div>
                        <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-zinc-500">Kodam</label>
                        <select
                            wire:model.live="kodamId"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-700 transition focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
                        >
                            <option value="">All Kodam</option>
                            @foreach($kodams as $kodam)
                                <option value="{{ $kodam->id }}">{{ $kodam->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Korem --}}
                    <div>
                        <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-zinc-500">Korem</label>
                        <select
                            wire:model.live="koremId"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-700 transition focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
                            @if(!$kodamId) disabled @endif
                        >
                            <option value="">All Korem</option>
                            @foreach($korems as $korem)
                                <option value="{{ $korem->id }}">{{ $korem->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Kodim --}}
                    <div>
                        <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-zinc-500">Kodim</label>
                        <select
                            wire:model.live="kodimId"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-700 transition focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
                            @if(!$koremId) disabled @endif
                        >
                            <option value="">All Kodim</option>
                            @foreach($kodims as $kodim)
                                <option value="{{ $kodim->id }}">{{ $kodim->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Koramil --}}
                    <div>
                        <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-zinc-500">Koramil</label>
                        <select
                            wire:model.live="koramilId"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-700 transition focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
                            @if(!$kodimId) disabled @endif
                        >
                            <option value="">{{ $kodimId ? 'All Koramil' : 'Select Kodim' }}</option>
                            @foreach($koramils as $koramil)
                                <option value="{{ $koramil->id }}">{{ $koramil->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if(auth()->user()->hasRole('Manager'))
                    {{-- Koramil (Manager) --}}
                    <div>
                        <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-zinc-500">Koramil</label>
                        <select
                            wire:model.live="koramilId"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-700 transition focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
                        >
                            <option value="">All Koramil</option>
                            @foreach($koramils as $koramil)
                                <option value="{{ $koramil->id }}">{{ $koramil->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Project Selector (Always Visible) --}}
                <div class="{{ auth()->user()->hasRole('Reporter') ? 'sm:col-span-2 lg:col-span-5' : '' }}">
                    <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-zinc-500">Project</label>
                    <select
                        wire:model.live="projectId"
                        class="w-full rounded-lg border border-cyan-400/50 bg-white px-3 py-2 text-sm text-zinc-700 transition focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500 dark:border-cyan-600/50 dark:bg-zinc-700 dark:text-zinc-200"
                    >
                        <option value="">Select Project to View Progress</option>
                        @foreach($projects as $proj)
                            <option value="{{ $proj->id }}">{{ $proj->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        @if($projectId && $project)
            {{-- Stats Cards --}}
            <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5 lg:gap-4">
                {{-- Project Info --}}
                <div class="stat-card col-span-2 rounded-xl p-4 lg:col-span-1">
                    <div class="mb-2 text-xs font-medium uppercase tracking-wider text-zinc-500">Project</div>
                    <div class="truncate text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ $project->name }}</div>
                    @if($project->start_date && $project->end_date)
                        <div class="mt-2 font-mono text-xs text-zinc-500">
                            {{ \Carbon\Carbon::parse($project->start_date)->format('M d') }} - {{ \Carbon\Carbon::parse($project->end_date)->format('M d, Y') }}
                        </div>
                    @endif
                </div>

                {{-- Average Planned --}}
                <div class="stat-card rounded-xl p-4">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="h-2 w-2 rounded-full bg-cyan-500 dark:bg-cyan-400"></div>
                        <span class="text-xs font-medium uppercase tracking-wider text-zinc-500">Avg Planned</span>
                    </div>
                    <div class="font-mono text-2xl font-bold text-cyan-600 dark:text-cyan-400">
                        {{ number_format($stats['avgPlanned'], 1) }}<span class="text-lg">%</span>
                    </div>
                </div>

                {{-- Average Actual --}}
                <div class="stat-card rounded-xl p-4">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="h-2 w-2 rounded-full bg-emerald-500 dark:bg-emerald-400"></div>
                        <span class="text-xs font-medium uppercase tracking-wider text-zinc-500">Avg Actual</span>
                    </div>
                    <div class="font-mono text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {{ number_format($stats['avgActual'], 1) }}<span class="text-lg">%</span>
                    </div>
                </div>

                {{-- Days Behind --}}
                <div class="stat-card rounded-xl p-4 {{ $stats['behindDays'] > 0 ? 'glow-red' : '' }}">
                    <div class="mb-2 flex items-center gap-2">
                        <flux:icon.arrow-trending-down class="h-4 w-4 text-rose-500 dark:text-rose-400" />
                        <span class="text-xs font-medium uppercase tracking-wider text-zinc-500">Behind</span>
                    </div>
                    <div class="font-mono text-2xl font-bold {{ $stats['behindDays'] > 0 ? 'text-rose-500 dark:text-rose-400' : 'text-zinc-400 dark:text-zinc-500' }}">
                        {{ $stats['behindDays'] }}<span class="text-sm font-normal text-zinc-500"> days</span>
                    </div>
                </div>

                {{-- Days On Track / Ahead --}}
                <div class="stat-card rounded-xl p-4 {{ $stats['aheadDays'] > 0 ? 'glow-green' : '' }}">
                    <div class="mb-2 flex items-center gap-2">
                        <flux:icon.arrow-trending-up class="h-4 w-4 text-emerald-500 dark:text-emerald-400" />
                        <span class="text-xs font-medium uppercase tracking-wider text-zinc-500">On Track / Ahead</span>
                    </div>
                    <div class="font-mono text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {{ $stats['onTrackDays'] + $stats['aheadDays'] }}<span class="text-sm font-normal text-zinc-500"> days</span>
                    </div>
                </div>
            </div>

            {{-- Legend --}}
            <div class="mb-4 flex flex-wrap items-center justify-center gap-4 rounded-lg border border-zinc-200 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700/50 dark:bg-zinc-800/30 lg:gap-8">
                <div class="flex items-center gap-2">
                    <div class="h-3 w-3 rounded-full bg-cyan-500 dark:bg-cyan-400"></div>
                    <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Planned Progress</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="h-3 w-3 rounded-full bg-emerald-500 dark:bg-emerald-400"></div>
                    <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Actual Progress</span>
                </div>
                <div class="hidden items-center gap-4 border-l border-zinc-300 pl-4 dark:border-zinc-700 lg:flex">
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded border border-rose-400/50 bg-rose-500/20 dark:border-rose-500/50"></div>
                        <span class="text-xs text-zinc-500">Behind (&gt;5%)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded border border-zinc-300 bg-zinc-200 dark:border-zinc-600 dark:bg-zinc-700"></div>
                        <span class="text-xs text-zinc-500">On Track</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded border border-emerald-400/50 bg-emerald-500/20 dark:border-emerald-500/50"></div>
                        <span class="text-xs text-zinc-500">Ahead (&gt;5%)</span>
                    </div>
                </div>
            </div>
        @endif

        {{-- Calendar Grid --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700/50 dark:bg-zinc-800/50">
            {{-- Day Headers --}}
            <div class="grid grid-cols-7 border-b border-zinc-200 bg-zinc-100 dark:border-zinc-700/50 dark:bg-zinc-800">
                @foreach(['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'] as $index => $day)
                    <div class="px-1 py-3 text-center text-[10px] font-bold uppercase tracking-widest {{ $index === 0 ? 'text-rose-500 dark:text-rose-400/70' : 'text-zinc-500' }}">
                        {{ $day }}
                    </div>
                @endforeach
            </div>

            {{-- Calendar Body --}}
            <div class="grid grid-cols-7">
                @foreach($calendar as $weekIndex => $week)
                    @foreach($week as $dayIndex => $day)
                        @php
                            $varianceClass = '';
                            $varianceBg = '';
                            if ($projectId && $day['isPast'] && $day['isCurrentMonth']) {
                                if ($day['variance'] < -5) {
                                    $varianceClass = 'border-rose-400/30 dark:border-rose-500/30';
                                    $varianceBg = 'bg-rose-500/5';
                                } elseif ($day['variance'] > 5) {
                                    $varianceClass = 'border-emerald-400/30 dark:border-emerald-500/30';
                                    $varianceBg = 'bg-emerald-500/5';
                                }
                            }
                        @endphp
                        <div
                            class="calendar-cell relative min-h-[100px] border-b border-r border-zinc-200 p-1.5 transition-all dark:border-zinc-700/30 lg:min-h-[120px] lg:p-2
                                {{ $day['isCurrentMonth'] ? 'bg-white dark:bg-zinc-800/30' : 'bg-zinc-50 dark:bg-zinc-900/50' }}
                                {{ $varianceBg }}
                                {{ $dayIndex === 6 ? '!border-r-0' : '' }}
                                {{ $day['isToday'] ? 'ring-2 ring-inset ring-cyan-500/50' : '' }}
                                {{ $varianceClass }}"
                        >
                            {{-- Date Number --}}
                            <div class="mb-1 flex items-start justify-between">
                                <span class="flex h-6 w-6 items-center justify-center rounded-md text-xs font-semibold
                                    {{ $day['isToday'] ? 'bg-cyan-500 text-white dark:text-zinc-900' : '' }}
                                    {{ !$day['isToday'] && $day['isCurrentMonth'] ? 'text-zinc-700 dark:text-zinc-300' : '' }}
                                    {{ !$day['isToday'] && !$day['isCurrentMonth'] ? 'text-zinc-400 dark:text-zinc-600' : '' }}
                                    {{ $dayIndex === 0 && $day['isCurrentMonth'] && !$day['isToday'] ? 'text-rose-500 dark:text-rose-400/70' : '' }}">
                                    {{ $day['date']->format('j') }}
                                </span>

                                @if($projectId && $day['isCurrentMonth'] && $day['isPast'])
                                    {{-- Variance Badge --}}
                                    @if($day['variance'] < -5)
                                        <span class="variance-indicator flex items-center gap-0.5 rounded bg-rose-500/20 px-1 py-0.5 font-mono text-[9px] font-bold text-rose-500 dark:text-rose-400">
                                            <flux:icon.arrow-down class="h-2.5 w-2.5" />
                                            {{ number_format(abs($day['variance']), 0) }}%
                                        </span>
                                    @elseif($day['variance'] > 5)
                                        <span class="variance-indicator flex items-center gap-0.5 rounded bg-emerald-500/20 px-1 py-0.5 font-mono text-[9px] font-bold text-emerald-600 dark:text-emerald-400">
                                            <flux:icon.arrow-up class="h-2.5 w-2.5" />
                                            +{{ number_format($day['variance'], 0) }}%
                                        </span>
                                    @endif
                                @endif
                            </div>

                            @if($projectId && $day['isCurrentMonth'])
                                {{-- Progress Display --}}
                                <div class="space-y-1.5">
                                    {{-- Planned Progress Bar --}}
                                    <div class="group/bar">
                                        <div class="mb-0.5 flex items-center justify-between">
                                            <span class="text-[8px] font-medium uppercase tracking-wider text-cyan-600/70 dark:text-cyan-500/70">Plan</span>
                                            <span class="font-mono text-[9px] font-semibold text-cyan-600 dark:text-cyan-400">{{ number_format($day['plannedProgress'], 0) }}%</span>
                                        </div>
                                        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700/50">
                                            <div
                                                class="h-full rounded-full bg-gradient-to-r from-cyan-600 to-cyan-400 transition-all duration-500"
                                                style="width: {{ $day['plannedProgress'] }}%"
                                            ></div>
                                        </div>
                                    </div>

                                    {{-- Actual Progress Bar --}}
                                    <div class="group/bar">
                                        <div class="mb-0.5 flex items-center justify-between">
                                            <span class="text-[8px] font-medium uppercase tracking-wider text-emerald-600/70 dark:text-emerald-500/70">Actual</span>
                                            <span class="font-mono text-[9px] font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($day['actualProgress'], 0) }}%</span>
                                        </div>
                                        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700/50">
                                            <div
                                                class="h-full rounded-full bg-gradient-to-r from-emerald-600 to-emerald-400 transition-all duration-500"
                                                style="width: {{ $day['actualProgress'] }}%"
                                            ></div>
                                        </div>
                                    </div>
                                </div>
                            @elseif(!$projectId && $day['isCurrentMonth'])
                                {{-- Empty State for Day --}}
                                <div class="flex h-12 items-center justify-center">
                                    <span class="text-[9px] text-zinc-400 dark:text-zinc-600">—</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>

        @if(!$projectId)
            {{-- Empty State --}}
            <div class="mt-8 flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 bg-zinc-50/50 py-12 dark:border-zinc-700 dark:bg-zinc-800/30">
                <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-zinc-200 dark:bg-zinc-700/50">
                    <flux:icon.calendar-days class="h-8 w-8 text-zinc-500" />
                </div>
                <h3 class="mb-2 text-lg font-semibold text-zinc-700 dark:text-zinc-300">Select a Project</h3>
                <p class="max-w-sm text-center text-sm text-zinc-500">
                    Choose a project from the dropdown above to view its planned vs actual progress on the calendar.
                </p>
            </div>
        @endif
    </div>
</div>

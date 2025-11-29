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

<div class="flex h-full w-full flex-1 flex-col gap-6" x-data="{ showFilters: !@js($projectId) && window.innerWidth >= 1024 }" x-init="$watch('$wire.projectId', value => { if (value) showFilters = false })">
    {{-- Inline Styles for Effects --}}
    <style>
        .calendar-progress-page .stat-card {
            position: relative;
            overflow: hidden;
        }

        .calendar-progress-page .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--stat-accent, theme('colors.blue.500'));
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
    </style>

    <div class="calendar-progress-page">
        {{-- Header Section --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-neutral-900 dark:text-white sm:text-3xl">
                    Kalender Kemajuan
                </h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400"> {{ now()->translatedFormat('l, j F Y') }}</p>
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

                {{-- Month Navigation --}}
                <div class="flex items-center gap-1 rounded-lg border border-neutral-200 bg-white p-1 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <flux:button wire:click="previousMonth" size="sm" variant="ghost" icon="chevron-left" />
                    <span class="min-w-[120px] px-2 text-center text-sm font-semibold text-neutral-900 dark:text-white">
                        {{ $monthName }}
                    </span>
                    <button
                        wire:click="goToToday"
                        class="rounded-md bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-600 transition hover:bg-blue-200 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50"
                    >
                        HARI INI
                    </button>
                    <flux:button wire:click="nextMonth" size="sm" variant="ghost" icon="chevron-right" />
                </div>
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

                @if(!auth()->user()->hasRole('Reporter') && !auth()->user()->hasRole('Manager'))
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                        {{-- Kodam --}}
                        <div>
                            <flux:select wire:model.live="kodamId" label="Kodam" size="sm">
                                <option value="">Semua Kodam</option>
                                @foreach($kodams as $kodam)
                                    <option value="{{ $kodam->id }}">{{ $kodam->name }}</option>
                                @endforeach
                            </flux:select>
                        </div>

                        {{-- Korem --}}
                        <div>
                            <flux:select wire:model.live="koremId" label="Korem" :disabled="!$kodamId" size="sm">
                                <option value="">{{ $kodamId ? 'Pilih Korem...' : 'Pilih Kodam dulu' }}</option>
                                @foreach($korems as $korem)
                                    <option value="{{ $korem->id }}">{{ $korem->name }}</option>
                                @endforeach
                            </flux:select>
                        </div>

                        {{-- Kodim --}}
                        <div>
                            <flux:select wire:model.live="kodimId" label="Kodim" :disabled="!$koremId" size="sm">
                                <option value="">{{ $koremId ? 'Semua Kodim' : 'Pilih Korem dulu' }}</option>
                                @foreach($kodims as $kodim)
                                    <option value="{{ $kodim->id }}">{{ $kodim->name }}</option>
                                @endforeach
                            </flux:select>
                        </div>

                        {{-- Koramil --}}
                        <div>
                            <flux:select wire:model.live="koramilId" label="Koramil" :disabled="!$kodimId" size="sm">
                                <option value="">{{ $kodimId ? 'Semua Koramil' : 'Pilih Kodim dulu' }}</option>
                                @foreach($koramils as $koramil)
                                    <option value="{{ $koramil->id }}">{{ $koramil->name }}</option>
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
                    </div>
                @elseif(auth()->user()->hasRole('Manager'))
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- Koramil (Manager) --}}
                        <div>
                            <flux:select wire:model.live="koramilId" label="Koramil" size="sm">
                                <option value="">Semua Koramil</option>
                                @foreach($koramils as $koramil)
                                    <option value="{{ $koramil->id }}">{{ $koramil->name }}</option>
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
                    </div>
                @else
                    {{-- Reporter: Only project filter --}}
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <flux:select wire:model.live="projectId" label="Project" size="sm">
                                <option value="">Pilih Project...</option>
                                @foreach($projects as $proj)
                                    <option value="{{ $proj->id }}">{{ $proj->name }}</option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if($projectId && $project)
            {{-- Stats Cards --}}
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
                {{-- Project Info --}}
                <div class="stat-card col-span-2 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800 lg:col-span-1" style="--stat-accent: theme('colors.blue.500')">
                    <div class="mb-2 text-xs font-medium text-neutral-500">Project</div>
                    <div class="truncate text-sm font-semibold text-neutral-900 dark:text-white">{{ $project->name }}</div>
                    @if($project->start_date && $project->end_date)
                        <div class="mt-2 text-xs text-neutral-500">
                            {{ \Carbon\Carbon::parse($project->start_date)->format('M d') }} - {{ \Carbon\Carbon::parse($project->end_date)->format('M d, Y') }}
                        </div>
                    @endif
                </div>

                {{-- Average Planned --}}
                <div class="stat-card rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800" style="--stat-accent: theme('colors.cyan.500')">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="h-2 w-2 rounded-full bg-cyan-500"></div>
                        <span class="text-xs font-medium text-neutral-500">Rata-rata Rencana</span>
                    </div>
                    <div class="text-2xl font-bold text-cyan-600 dark:text-cyan-400">
                        {{ number_format($stats['avgPlanned'], 1) }}<span class="text-lg">%</span>
                    </div>
                </div>

                {{-- Average Actual --}}
                <div class="stat-card rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800" style="--stat-accent: theme('colors.emerald.500')">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="h-2 w-2 rounded-full bg-emerald-500"></div>
                        <span class="text-xs font-medium text-neutral-500">Rata-rata Aktual</span>
                    </div>
                    <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {{ number_format($stats['avgActual'], 1) }}<span class="text-lg">%</span>
                    </div>
                </div>

                {{-- Days Behind --}}
                <div class="stat-card rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800 {{ $stats['behindDays'] > 0 ? 'glow-red' : '' }}" style="--stat-accent: theme('colors.rose.500')">
                    <div class="mb-2 flex items-center gap-2">
                        <flux:icon.arrow-trending-down class="h-4 w-4 text-rose-500" />
                        <span class="text-xs font-medium text-neutral-500">Terlambat</span>
                    </div>
                    <div class="text-2xl font-bold {{ $stats['behindDays'] > 0 ? 'text-rose-500' : 'text-neutral-400' }}">
                        {{ $stats['behindDays'] }}<span class="text-sm font-normal text-neutral-500"> hari</span>
                    </div>
                </div>

                {{-- Days On Track / Ahead --}}
                <div class="stat-card rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800 {{ $stats['aheadDays'] > 0 ? 'glow-green' : '' }}" style="--stat-accent: theme('colors.emerald.500')">
                    <div class="mb-2 flex items-center gap-2">
                        <flux:icon.arrow-trending-up class="h-4 w-4 text-emerald-500" />
                        <span class="text-xs font-medium text-neutral-500">Sesuai / Lebih Cepat</span>
                    </div>
                    <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {{ $stats['onTrackDays'] + $stats['aheadDays'] }}<span class="text-sm font-normal text-neutral-500"> hari</span>
                    </div>
                </div>
            </div>

            {{-- Legend --}}
            <div class="mb-4 flex flex-wrap items-center justify-center gap-4 rounded-xl border border-neutral-200 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800 lg:gap-8">
                <div class="flex items-center gap-2">
                    <div class="h-3 w-3 rounded-full bg-cyan-500"></div>
                    <span class="text-xs font-medium text-neutral-600 dark:text-neutral-400">Progres Rencana</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="h-3 w-3 rounded-full bg-emerald-500"></div>
                    <span class="text-xs font-medium text-neutral-600 dark:text-neutral-400">Progres Aktual</span>
                </div>
                <div class="hidden items-center gap-4 border-l border-neutral-300 pl-4 dark:border-neutral-700 lg:flex">
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded border border-rose-400/50 bg-rose-500/20"></div>
                        <span class="text-xs text-neutral-500">Terlambat (&gt;5%)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded border border-neutral-300 bg-neutral-200 dark:border-neutral-600 dark:bg-neutral-700"></div>
                        <span class="text-xs text-neutral-500">Sesuai Target</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded border border-emerald-400/50 bg-emerald-500/20"></div>
                        <span class="text-xs text-neutral-500">Lebih Cepat (&gt;5%)</span>
                    </div>
                </div>
            </div>
        @endif

        {{-- Calendar Grid --}}
        <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-800">
            {{-- Day Headers --}}
            <div class="grid grid-cols-7 border-b border-neutral-200 bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800">
                @foreach(['MIN', 'SEN', 'SEL', 'RAB', 'KAM', 'JUM', 'SAB'] as $index => $day)
                    <div class="px-1 py-3 text-center text-[10px] font-bold uppercase tracking-widest {{ $index === 0 ? 'text-rose-500 dark:text-rose-400' : 'text-neutral-500' }}">
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
                            class="calendar-cell relative min-h-[100px] border-b border-r border-neutral-200 p-1.5 transition-all dark:border-neutral-700 lg:min-h-[120px] lg:p-2
                                {{ $day['isCurrentMonth'] ? 'bg-white dark:bg-neutral-800' : 'bg-neutral-50 dark:bg-neutral-900' }}
                                {{ $varianceBg }}
                                {{ $dayIndex === 6 ? '!border-r-0' : '' }}
                                {{ $day['isToday'] ? 'ring-2 ring-inset ring-blue-500/50' : '' }}
                                {{ $varianceClass }}"
                        >
                            {{-- Date Number --}}
                            <div class="mb-1 flex items-start justify-between">
                                <span class="flex h-6 w-6 items-center justify-center rounded-md text-xs font-semibold
                                    {{ $day['isToday'] ? 'bg-blue-500 text-white' : '' }}
                                    {{ !$day['isToday'] && $day['isCurrentMonth'] ? 'text-neutral-700 dark:text-neutral-300' : '' }}
                                    {{ !$day['isToday'] && !$day['isCurrentMonth'] ? 'text-neutral-400 dark:text-neutral-600' : '' }}
                                    {{ $dayIndex === 0 && $day['isCurrentMonth'] && !$day['isToday'] ? 'text-rose-500 dark:text-rose-400' : '' }}">
                                    {{ $day['date']->format('j') }}
                                </span>

                                @if($projectId && $day['isCurrentMonth'] && $day['isPast'])
                                    {{-- Variance Badge --}}
                                    @if($day['variance'] < -5)
                                        <span class="variance-indicator flex items-center gap-0.5 rounded bg-rose-500/20 px-1 py-0.5 text-[9px] font-bold text-rose-500 dark:text-rose-400">
                                            <flux:icon.arrow-down class="h-2.5 w-2.5" />
                                            {{ number_format(abs($day['variance']), 0) }}%
                                        </span>
                                    @elseif($day['variance'] > 5)
                                        <span class="variance-indicator flex items-center gap-0.5 rounded bg-emerald-500/20 px-1 py-0.5 text-[9px] font-bold text-emerald-600 dark:text-emerald-400">
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
                                            <span class="text-[8px] font-medium uppercase tracking-wider text-cyan-600/70 dark:text-cyan-500/70">Rencana</span>
                                            <span class="text-[9px] font-semibold text-cyan-600 dark:text-cyan-400">{{ number_format($day['plannedProgress'], 0) }}%</span>
                                        </div>
                                        <div class="h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                                            <div
                                                class="h-full rounded-full bg-gradient-to-r from-cyan-600 to-cyan-400 transition-all duration-500"
                                                style="width: {{ $day['plannedProgress'] }}%"
                                            ></div>
                                        </div>
                                    </div>

                                    {{-- Actual Progress Bar --}}
                                    <div class="group/bar">
                                        <div class="mb-0.5 flex items-center justify-between">
                                            <span class="text-[8px] font-medium uppercase tracking-wider text-emerald-600/70 dark:text-emerald-500/70">Aktual</span>
                                            <span class="text-[9px] font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($day['actualProgress'], 0) }}%</span>
                                        </div>
                                        <div class="h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
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
                                    <span class="text-[9px] text-neutral-400 dark:text-neutral-600">—</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>

        @if(!$projectId)
            {{-- Empty State --}}
            <div class="mt-6 flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-neutral-300 bg-neutral-50 py-12 dark:border-neutral-700 dark:bg-neutral-800/50">
                <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-neutral-200 dark:bg-neutral-700">
                    <flux:icon.calendar-days class="h-8 w-8 text-neutral-400" />
                </div>
                <h3 class="mb-2 text-lg font-semibold text-neutral-700 dark:text-neutral-300">Pilih Project</h3>
                <p class="max-w-sm text-center text-sm text-neutral-500">
                    Pilih project dari dropdown di atas untuk melihat progres rencana vs aktual pada kalender.
                </p>
            </div>
        @endif
    </div>
</div>

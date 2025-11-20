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

                $week[] = [
                    'date' => $currentDate->copy(),
                    'isCurrentMonth' => $currentDate->month === $this->month,
                    'isToday' => $currentDate->isToday(),
                    'plannedProgress' => $plannedProgress,
                    'actualProgress' => $actualProgress,
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
            'calendar' => $calendar,
            'monthName' => Carbon::create($this->year, $this->month, 1)->format('F Y'),
            'currentDate' => now()->format('l, F j, Y'),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Calendar Progress</flux:heading>
        <div class="text-sm text-neutral-600">{{ $currentDate }}</div>
    </div>

    <!-- Filters -->
    <div class="grid grid-cols-1 gap-4 md:flex md:gap-4">
        @if(!auth()->user()->hasRole('Reporter') && !auth()->user()->hasRole('Manager'))
            <!-- Kodam -->
            <div class="md:flex-1">
                <flux:select wire:model.live="kodamId" label="Kodam">
                    <flux:select.option value="">All Kodam</flux:select.option>
                    @foreach($kodams as $kodam)
                        <flux:select.option value="{{ $kodam->id }}">
                            {{ $kodam->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <!-- Korem -->
            <div class="md:flex-1">
                <flux:select wire:model.live="koremId" label="Korem" :disabled="!$kodamId">
                    <flux:select.option value="">All Korem</flux:select.option>
                    @foreach($korems as $korem)
                        <flux:select.option value="{{ $korem->id }}">
                            {{ $korem->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <!-- Kodim -->
            <div class="md:flex-1">
                <flux:select wire:model.live="kodimId" label="Kodim" :disabled="!$koremId">
                    <flux:select.option value="">All Kodim</flux:select.option>
                    @foreach($kodims as $kodim)
                        <flux:select.option value="{{ $kodim->id }}">
                            {{ $kodim->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <!-- Koramil -->
            <div class="md:flex-1">
                <flux:select wire:model.live="koramilId" label="Koramil" :disabled="!$kodimId">
                    <flux:select.option value="">{{ $kodimId ? 'All Koramil' : 'Select Kodim first' }}</flux:select.option>
                    @foreach($koramils as $koramil)
                        <flux:select.option value="{{ $koramil->id }}">
                            {{ $koramil->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        @if(auth()->user()->hasRole('Manager'))
            <!-- Koramil (Manager sees only Koramils under their Kodim) -->
            <div class="md:flex-1">
                <flux:select wire:model.live="koramilId" label="Koramil">
                    <flux:select.option value="">All Koramil</flux:select.option>
                    @foreach($koramils as $koramil)
                        <flux:select.option value="{{ $koramil->id }}">
                            {{ $koramil->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <!-- Project -->
        <div class="md:flex-1">
            <flux:select wire:model.live="projectId" label="Project">
                <flux:select.option value="">Select Project</flux:select.option>
                @foreach($projects as $project)
                    <flux:select.option value="{{ $project->id }}">
                        {{ $project->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <!-- Calendar Navigation -->
    <div class="flex items-center justify-center gap-4 rounded-lg border border-neutral-200 bg-white p-4">
        <flux:button wire:click="previousMonth" variant="ghost" size="sm">
            <flux:icon.chevron-left class="h-5 w-5" />
        </flux:button>
        <span class="min-w-[180px] text-center text-lg font-semibold text-neutral-900">{{ $monthName }}</span>
        <flux:button wire:click="goToToday" variant="primary" size="sm">
            Today
        </flux:button>
        <flux:button wire:click="nextMonth" variant="ghost" size="sm">
            <flux:icon.chevron-right class="h-5 w-5" />
        </flux:button>
    </div>

    <!-- Calendar Grid -->
    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
        <!-- Calendar Header -->
        <div class="grid grid-cols-7 border-b border-neutral-200 bg-neutral-50">
            @foreach(['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'] as $day)
                <div class="px-2 py-2 text-center text-[11px] font-medium tracking-wide text-neutral-500">
                    {{ $day }}
                </div>
            @endforeach
        </div>

        <!-- Calendar Body -->
        <div class="grid grid-cols-7">
            @foreach($calendar as $week)
                @foreach($week as $day)
                    <div class="group relative min-h-[110px] border-b border-r border-neutral-100 p-2 transition-colors hover:bg-neutral-50 {{ $day['isCurrentMonth'] ? 'bg-white' : 'bg-neutral-50/50' }} {{ ($loop->parent->index + 1) % 7 == 0 ? '!border-r-0' : '' }}">
                        <!-- Date Number -->
                        <div class="mb-1.5 flex items-start justify-between">
                            <span class="inline-flex items-center justify-center {{ $day['isToday'] ? 'h-6 w-6 rounded-full bg-blue-500 text-xs font-semibold text-white' : 'text-sm font-medium ' . ($day['isCurrentMonth'] ? 'text-neutral-700' : 'text-neutral-400') }}">
                                {{ $day['date']->format('j') }}
                            </span>
                        </div>

                        @if($projectId)
                            <div class="space-y-1">
                                <!-- Planned Progress -->
                                <div class="rounded bg-blue-50 px-1 py-0.5">
                                    <div class="mb-0.5 flex items-center justify-end">
                                        <span class="text-[7px] font-medium text-blue-700">{{ number_format($day['plannedProgress'], 1) }}%</span>
                                    </div>
                                    <div class="h-0.5 overflow-hidden rounded-full bg-blue-100">
                                        <div
                                            class="h-full rounded-full bg-blue-500"
                                            style="width: {{ $day['plannedProgress'] }}%"
                                        ></div>
                                    </div>
                                </div>

                                <!-- Actual Progress -->
                                <div class="rounded bg-green-50 px-1 py-0.5">
                                    <div class="mb-0.5 flex items-center justify-end">
                                        <span class="text-[7px] font-medium text-green-700">{{ number_format($day['actualProgress'], 1) }}%</span>
                                    </div>
                                    <div class="h-0.5 overflow-hidden rounded-full bg-green-100">
                                        <div
                                            class="h-full rounded-full bg-green-500"
                                            style="width: {{ $day['actualProgress'] }}%"
                                        ></div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    <!-- Legend -->
    @if($projectId)
        <div class="flex items-center justify-center gap-6 rounded-lg border border-neutral-200 bg-white p-3">
            <div class="flex items-center gap-2">
                <div class="h-3 w-3 rounded-full bg-blue-500"></div>
                <span class="text-sm text-neutral-700">Planned Progress</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="h-3 w-3 rounded-full bg-green-500"></div>
                <span class="text-sm text-neutral-700">Actual Progress</span>
            </div>
        </div>
    @endif
</div>

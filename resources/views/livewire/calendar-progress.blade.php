<?php

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Task;
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

        $koramils = $this->kodimId
            ? Office::where('parent_id', $this->kodimId)
                ->whereHas('level', fn ($q) => $q->where('level', 4))
                ->orderBy('name')
                ->get()
            : collect();

        // Build office filter
        $officeId = $this->koramilId ?? $this->kodimId ?? $this->koremId ?? $this->kodamId;

        // Get projects based on filters
        $projectsQuery = Project::with('location', 'partner')->orderBy('name');

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
        <flux:heading size="xl" class="text-white">Calendar Progress</flux:heading>
        <div class="text-sm text-neutral-400">{{ $currentDate }}</div>
    </div>

    <!-- Office Hierarchy Filters -->
    <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
        <!-- Kodam -->
        <div>
            <label class="mb-2 block text-xs font-medium text-neutral-400">Kodam</label>
            <flux:select wire:model.live="kodamId" class="bg-neutral-800 text-white">
                <flux:select.option value="">All Kodam</flux:select.option>
                @foreach($kodams as $kodam)
                    <flux:select.option value="{{ $kodam->id }}">
                        {{ $kodam->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <!-- Korem -->
        <div>
            <label class="mb-2 block text-xs font-medium text-neutral-400">Korem</label>
            <flux:select wire:model.live="koremId" class="bg-neutral-800 text-white" :disabled="!$kodamId">
                <flux:select.option value="">All Korem</flux:select.option>
                @foreach($korems as $korem)
                    <flux:select.option value="{{ $korem->id }}">
                        {{ $korem->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <!-- Kodim -->
        <div>
            <label class="mb-2 block text-xs font-medium text-neutral-400">Kodim</label>
            <flux:select wire:model.live="kodimId" class="bg-neutral-800 text-white" :disabled="!$koremId">
                <flux:select.option value="">All Kodim</flux:select.option>
                @foreach($kodims as $kodim)
                    <flux:select.option value="{{ $kodim->id }}">
                        {{ $kodim->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <!-- Koramil -->
        <div>
            <label class="mb-2 block text-xs font-medium text-neutral-400">Koramil</label>
            <flux:select wire:model.live="koramilId" class="bg-neutral-800 text-white" :disabled="!$kodimId">
                <flux:select.option value="">{{ $kodimId ? 'All Koramil' : 'Select Kodim first' }}</flux:select.option>
                @foreach($koramils as $koramil)
                    <flux:select.option value="{{ $koramil->id }}">
                        {{ $koramil->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <!-- Project -->
        <div>
            <label class="mb-2 block text-xs font-medium text-neutral-400">Project</label>
            <flux:select wire:model.live="projectId" class="bg-neutral-800 text-white">
                <flux:select.option value="">All Projects</flux:select.option>
                @foreach($projects as $project)
                    <flux:select.option value="{{ $project->id }}">
                        {{ $project->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <!-- Calendar Navigation -->
    <div class="flex items-center justify-center gap-4 rounded-xl bg-neutral-900 p-4">
        <flux:button wire:click="previousMonth" variant="ghost" size="sm" class="text-white">
            <flux:icon.chevron-left class="h-5 w-5" />
        </flux:button>
        <span class="min-w-[180px] text-center text-lg font-semibold text-white">{{ $monthName }}</span>
        <flux:button wire:click="goToToday" variant="outline" size="sm" class="text-white">
            Today
        </flux:button>
        <flux:button wire:click="nextMonth" variant="ghost" size="sm" class="text-white">
            <flux:icon.chevron-right class="h-5 w-5" />
        </flux:button>
    </div>

    <!-- Calendar Grid -->
    <div class="overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900">
        <!-- Calendar Header -->
        <div class="grid grid-cols-7 gap-px bg-neutral-800">
            @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day)
                <div class="bg-neutral-900 px-4 py-3 text-center text-sm font-semibold text-neutral-400">
                    {{ $day }}
                </div>
            @endforeach
        </div>

        <!-- Calendar Body -->
        <div class="grid grid-cols-7 gap-px bg-neutral-800">
            @foreach($calendar as $week)
                @foreach($week as $day)
                    <div class="min-h-[140px] bg-black p-3 {{ $day['isCurrentMonth'] ? '' : 'bg-neutral-950' }}">
                        <div class="mb-3 text-lg {{ $day['isCurrentMonth'] ? 'font-medium text-neutral-300' : 'text-neutral-700' }}">
                            {{ $day['date']->format('j') }}
                        </div>

                        @if($projectId && ($day['plannedProgress'] > 0 || $day['actualProgress'] > 0))
                            <div class="space-y-3">
                                <!-- Planned Progress -->
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-neutral-400">Planned</span>
                                        <span class="font-medium text-blue-400">{{ number_format($day['plannedProgress'], 1) }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-neutral-800">
                                        <div
                                            class="h-full rounded-full bg-blue-500 transition-all"
                                            style="width: {{ $day['plannedProgress'] }}%"
                                        ></div>
                                    </div>
                                </div>

                                <!-- Actual Progress -->
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-neutral-400">Actual</span>
                                        <span class="font-medium text-green-400">{{ number_format($day['actualProgress'], 1) }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-neutral-800">
                                        <div
                                            class="h-full rounded-full bg-green-500 transition-all"
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
</div>

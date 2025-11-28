<?php

use App\Exports\LapjusikExport;
use App\Models\Office;
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

    public function mount(): void
    {
        $this->selectedMonth = now()->month;
        $this->selectedYear = now()->year;
    }

    /**
     * Get accessible projects based on user role
     */
    private function getAccessibleProjects()
    {
        $currentUser = Auth::user();
        $query = Project::with(['location', 'partner', 'office']);

        if ($currentUser->isAdmin() || $currentUser->hasPermission('*')) {
            return $query->orderBy('name')->get();
        }

        if (! $currentUser->office_id) {
            return $currentUser->projects()->with(['location', 'partner', 'office'])->orderBy('name')->get();
        }

        $currentOffice = Office::with('level')->find($currentUser->office_id);

        if (! $currentOffice) {
            return $currentUser->projects()->with(['location', 'partner', 'office'])->orderBy('name')->get();
        }

        if ($currentOffice->level->level === 3) {
            $query->whereHas('office', function ($q) use ($currentUser) {
                $q->where('parent_id', $currentUser->office_id);
            });
        } elseif ($currentOffice->level->level === 4) {
            $query->where('office_id', $currentUser->office_id);
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
     * Get hierarchical tasks with progress data
     */
    private function getTasksWithProgress(): array
    {
        if (! $this->projectId) {
            return [];
        }

        $project = Project::find($this->projectId);
        if (! $project) {
            return [];
        }

        // Get all tasks for the project
        $tasks = Task::where('project_id', $this->projectId)
            ->orderBy('_lft')
            ->get();

        // Get all progress for the month
        $startOfMonth = Carbon::create($this->selectedYear, $this->selectedMonth, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

        $progressRecords = TaskProgress::where('project_id', $this->projectId)
            ->whereBetween('progress_date', [$startOfMonth, $endOfMonth])
            ->get()
            ->groupBy(function ($progress) {
                $date = $progress->progress_date;
                if (is_string($date)) {
                    return Carbon::parse($date)->format('Y-m-d');
                }

                return $date->format('Y-m-d');
            })
            ->map(function ($dateGroup) {
                return $dateGroup->keyBy('task_id');
            });

        // Build hierarchical structure - numbering comes from task names (already has Roman numerals)
        $result = [];
        $leafCounters = []; // Track sequential numbers for leaf tasks under each parent

        foreach ($tasks as $task) {
            $depth = 0;
            $currentId = $task->parent_id;

            while ($currentId !== null) {
                $depth++;
                $parent = $tasks->firstWhere('id', $currentId);
                $currentId = $parent?->parent_id;
            }

            $hasChildren = $task->children()->exists();

            // Generate numbering only for leaf tasks (sequential under their parent)
            $numbering = '';
            if (! $hasChildren && $task->parent_id !== null) {
                if (! isset($leafCounters[$task->parent_id])) {
                    $leafCounters[$task->parent_id] = 0;
                }
                $leafCounters[$task->parent_id]++;
                $numbering = (string) $leafCounters[$task->parent_id];
            }

            // Get daily progress for this task
            $dailyProgress = [];
            $calendarDays = $this->getCalendarDays();

            foreach ($calendarDays as $day) {
                $dateKey = $day['date'];
                $progress = null;

                if (isset($progressRecords[$dateKey]) && isset($progressRecords[$dateKey][$task->id])) {
                    $progress = $progressRecords[$dateKey][$task->id]->percentage;
                }

                $dailyProgress[$dateKey] = $progress;
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
        $project = $this->projectId ? Project::with(['location', 'partner', 'office'])->find($this->projectId) : null;

        return [
            'projects' => $this->getAccessibleProjects(),
            'calendarDays' => $this->getCalendarDays(),
            'tasksWithProgress' => $this->getTasksWithProgress(),
            'selectedProject' => $project,
            'monthName' => Carbon::create($this->selectedYear, $this->selectedMonth, 1)->locale('id')->translatedFormat('F'),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col">
    {{-- Document Header - Mimics official document style --}}
    <div class="border-b-4 border-double border-emerald-700 bg-gradient-to-br from-emerald-50 via-white to-teal-50 px-6 py-5 dark:border-emerald-500 dark:from-zinc-900 dark:via-zinc-800 dark:to-zinc-900">
        {{-- Title Banner --}}
        <div class="mb-4 text-center">
            <h1 class="font-mono text-2xl font-black tracking-[0.2em] text-emerald-800 dark:text-emerald-400">
                LAPJUSIK HARIAN
            </h1>
            <p class="mt-1 font-mono text-xs tracking-widest text-emerald-600 dark:text-emerald-500">
                LAPORAN KEMAJUAN FISIK HARIAN
            </p>
        </div>

        {{-- Project Selection --}}
        <div class="mx-auto max-w-4xl">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label class="mb-1 block font-mono text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                        Pilih Proyek
                    </label>
                    <flux:select wire:model.live="projectId" class="w-full font-mono">
                        <option value="">-- Pilih Proyek --</option>
                        @foreach($projects as $proj)
                            <option value="{{ $proj->id }}">{{ $proj->name }}</option>
                        @endforeach
                    </flux:select>
                </div>
                <div>
                    <label class="mb-1 block font-mono text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                        Periode
                    </label>
                    <div class="flex items-center gap-2">
                        <flux:button wire:click="previousMonth" size="sm" variant="ghost" class="!px-2">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </flux:button>
                        <div class="flex-1 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-center font-mono text-sm font-bold text-emerald-800 dark:border-emerald-700 dark:bg-zinc-800 dark:text-emerald-400">
                            {{ strtoupper($monthName) }} {{ $selectedYear }}
                        </div>
                        <flux:button wire:click="nextMonth" size="sm" variant="ghost" class="!px-2">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Project Info Cards --}}
        @if($selectedProject)
            <div class="mx-auto mt-4 max-w-4xl">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    {{-- PEKERJAAN --}}
                    <div class="rounded-lg border-l-4 border-emerald-600 bg-white/80 px-4 py-3 shadow-sm dark:bg-zinc-800/80">
                        <div class="font-mono text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-500">
                            Pekerjaan
                        </div>
                        <div class="mt-1 font-mono text-sm font-semibold leading-tight text-zinc-800 dark:text-zinc-200">
                            {{ $selectedProject->name }}
                        </div>
                    </div>
                    {{-- LOKASI --}}
                    <div class="rounded-lg border-l-4 border-teal-600 bg-white/80 px-4 py-3 shadow-sm dark:bg-zinc-800/80">
                        <div class="font-mono text-[10px] font-bold uppercase tracking-wider text-teal-600 dark:text-teal-500">
                            Lokasi
                        </div>
                        <div class="mt-1 font-mono text-sm font-semibold leading-tight text-zinc-800 dark:text-zinc-200">
                            {{ $selectedProject->location?->province_name ?? '-' }}
                        </div>
                    </div>
                    {{-- TAHUN ANGGARAN --}}
                    <div class="rounded-lg border-l-4 border-cyan-600 bg-white/80 px-4 py-3 shadow-sm dark:bg-zinc-800/80">
                        <div class="font-mono text-[10px] font-bold uppercase tracking-wider text-cyan-600 dark:text-cyan-500">
                            Tahun Anggaran
                        </div>
                        <div class="mt-1 font-mono text-sm font-semibold leading-tight text-zinc-800 dark:text-zinc-200">
                            {{ $selectedYear }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- Export Button --}}
            <div class="mx-auto mt-4 flex max-w-4xl justify-end">
                <flux:button wire:click="exportExcel" variant="primary" size="sm" class="bg-emerald-600 hover:bg-emerald-700">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span>Export Excel</span>
                    </div>
                </flux:button>
            </div>
        @endif
    </div>

    {{-- Main Content --}}
    @if(!$projectId)
        {{-- Empty State --}}
        <div class="flex flex-1 items-center justify-center p-8">
            <div class="text-center">
                <div class="mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                    <svg class="h-10 w-10 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <h3 class="font-mono text-lg font-bold text-zinc-700 dark:text-zinc-300">
                    Pilih Proyek
                </h3>
                <p class="mt-2 font-mono text-sm text-zinc-500 dark:text-zinc-400">
                    Silakan pilih proyek untuk melihat Lapjusik Harian
                </p>
            </div>
        </div>
    @else
        {{-- Spreadsheet Table --}}
        <div class="flex-1 overflow-x-auto overflow-y-auto bg-zinc-100 p-4 dark:bg-zinc-900">
            <div class="rounded-lg border border-zinc-300 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-800">
                {{-- Table --}}
                <table class="w-full border-collapse font-mono text-xs" style="min-width: {{ 48 + 250 + (count($calendarDays) * 56) }}px;">
                    {{-- Table Header --}}
                    <thead class="sticky top-0 z-30">
                        {{-- Month Header Row --}}
                        <tr>
                            <th rowspan="2" class="w-12 min-w-[48px] border border-zinc-400 bg-emerald-700 px-2 py-3 text-center font-bold text-white dark:border-zinc-600 dark:bg-emerald-800">
                                NO
                            </th>
                            <th rowspan="2" class="sticky left-0 z-40 w-[250px] min-w-[250px] border border-zinc-400 bg-emerald-700 px-3 py-3 text-left font-bold text-white dark:border-zinc-600 dark:bg-emerald-800">
                                URAIAN PEKERJAAN
                            </th>
                            <th colspan="{{ count($calendarDays) }}" class="border border-zinc-400 bg-emerald-600 px-2 py-2 text-center font-bold text-white dark:border-zinc-600 dark:bg-emerald-700">
                                {{ strtoupper($monthName) }}
                            </th>
                        </tr>
                        {{-- Days Header Row --}}
                        <tr>
                            @foreach($calendarDays as $day)
                                <th class="w-14 min-w-[56px] border border-zinc-400 px-1 py-2 text-center
                                    {{ $day['isSunday'] ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400' : ($day['isWeekend'] ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300') }}">
                                    <div class="text-[10px] font-medium">{{ $day['dayName'] }}</div>
                                    <div class="text-sm font-bold">{{ $day['day'] }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    {{-- Table Body --}}
                    <tbody>
                        @forelse($tasksWithProgress as $index => $task)
                            @php
                                $isParent = $task['hasChildren'];
                                $depth = $task['depth'];
                                $bgClass = $isParent
                                    ? ($depth === 0 ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-teal-50/50 dark:bg-teal-900/10')
                                    : ($index % 2 === 0 ? 'bg-white dark:bg-zinc-800' : 'bg-zinc-50 dark:bg-zinc-800/50');
                            @endphp
                            <tr class="{{ $bgClass }} hover:bg-yellow-50 dark:hover:bg-yellow-900/20 transition-colors">
                                {{-- NO Column - Only show sequential number for leaf tasks --}}
                                <td class="w-12 min-w-[48px] border border-zinc-300 px-2 py-2 text-center font-bold dark:border-zinc-600
                                    {{ $isParent ? 'bg-emerald-50 dark:bg-emerald-900/20' : ($index % 2 === 0 ? 'bg-white dark:bg-zinc-800' : 'bg-zinc-50 dark:bg-zinc-800/50') }}">
                                    @if(!$isParent && $task['numbering'])
                                        <span class="text-zinc-600 dark:text-zinc-400">
                                            {{ $task['numbering'] }}
                                        </span>
                                    @endif
                                </td>
                                {{-- URAIAN PEKERJAAN Column --}}
                                <td class="sticky left-0 z-10 w-[250px] min-w-[250px] border border-zinc-300 px-3 py-2 dark:border-zinc-600
                                    {{ $isParent ? 'bg-emerald-50 dark:bg-emerald-900/20' : ($index % 2 === 0 ? 'bg-white dark:bg-zinc-800' : 'bg-zinc-50 dark:bg-zinc-800/50') }}"
                                    style="padding-left: {{ 12 + ($depth * 16) }}px">
                                    @if($isParent)
                                        {{-- Parent tasks: show name as-is (already contains Roman numerals like "I. PEKERJAAN PERSIAPAN") --}}
                                        <span class="font-bold text-emerald-800 dark:text-emerald-300">
                                            {{ $task['name'] }}
                                        </span>
                                    @else
                                        {{-- Leaf tasks: show with dash prefix --}}
                                        <div class="flex items-start gap-2">
                                            <span class="text-zinc-400">-</span>
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ $task['name'] }}</span>
                                        </div>
                                    @endif
                                </td>
                                {{-- Daily Progress Columns --}}
                                @foreach($calendarDays as $day)
                                    @php
                                        $progress = $task['dailyProgress'][$day['date']] ?? null;
                                        $hasProgress = $progress !== null;
                                        $cellBg = $day['isSunday']
                                            ? 'bg-red-50/50 dark:bg-red-900/10'
                                            : ($day['isWeekend'] ? 'bg-amber-50/30 dark:bg-amber-900/5' : '');
                                    @endphp
                                    <td class="border border-zinc-300 px-1 py-2 text-center dark:border-zinc-600 {{ $cellBg }}">
                                        @if($hasProgress && !$isParent)
                                            <span class="inline-block min-w-[40px] rounded px-1 py-0.5 text-[11px] font-semibold
                                                {{ $progress >= 100 ? 'bg-emerald-500 text-white' : ($progress >= 75 ? 'bg-teal-400 text-white' : ($progress >= 50 ? 'bg-cyan-400 text-white' : ($progress >= 25 ? 'bg-amber-400 text-zinc-800' : 'bg-zinc-300 text-zinc-700 dark:bg-zinc-600 dark:text-zinc-200'))) }}">
                                                {{ number_format($progress, 2) }}
                                            </span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 2 + count($calendarDays) }}" class="border border-zinc-300 px-4 py-12 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                                    <div class="flex flex-col items-center">
                                        <svg class="mb-2 h-8 w-8 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                                        </svg>
                                        <span class="text-sm">Tidak ada data task untuk proyek ini</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Legend --}}
            <div class="mt-4 rounded-lg border border-zinc-300 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="font-mono text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">
                    Keterangan Progress
                </div>
                <div class="mt-3 flex flex-wrap gap-4">
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-5 w-12 rounded bg-emerald-500"></span>
                        <span class="font-mono text-xs text-zinc-600 dark:text-zinc-400">100% (Selesai)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-5 w-12 rounded bg-teal-400"></span>
                        <span class="font-mono text-xs text-zinc-600 dark:text-zinc-400">75-99%</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-5 w-12 rounded bg-cyan-400"></span>
                        <span class="font-mono text-xs text-zinc-600 dark:text-zinc-400">50-74%</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-5 w-12 rounded bg-amber-400"></span>
                        <span class="font-mono text-xs text-zinc-600 dark:text-zinc-400">25-49%</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-5 w-12 rounded bg-zinc-300 dark:bg-zinc-600"></span>
                        <span class="font-mono text-xs text-zinc-600 dark:text-zinc-400">0-24%</span>
                    </div>
                </div>
                <div class="mt-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                    <div class="flex flex-wrap gap-4">
                        <div class="flex items-center gap-2">
                            <span class="inline-block h-5 w-8 rounded bg-red-100 dark:bg-red-900/40"></span>
                            <span class="font-mono text-xs text-zinc-600 dark:text-zinc-400">Minggu</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-block h-5 w-8 rounded bg-amber-50 dark:bg-amber-900/30"></span>
                            <span class="font-mono text-xs text-zinc-600 dark:text-zinc-400">Sabtu</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

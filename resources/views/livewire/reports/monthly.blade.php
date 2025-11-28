<?php

use App\Models\Office;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;



layout('components.layouts.app');

new class extends Component
{
    public ?int $projectId = null;
    public string $startMonth;
    public string $endMonth;

    public function mount(): void
    {
        // Default to last 6 months
        $this->endMonth = now()->format('Y-m');
        $this->startMonth = now()->subMonths(6)->format('Y-m');
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

        if (!$currentUser->office_id) {
            return $currentUser->projects()->with(['location', 'partner', 'office'])->orderBy('name')->get();
        }

        $currentOffice = Office::with('level')->find($currentUser->office_id);

        if (!$currentOffice) {
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
     * Get monthly progress data
     */
    
    private function getReportData()
    {
        if (!$this->projectId) {
            return null;
        }

        $project = Project::with(['location', 'partner', 'office.parent'])->find($this->projectId);

        if (!$project) {
            return null;
        }

        // Get all leaf tasks
        $leafTasks = Task::where('project_id', $this->projectId)
            ->whereDoesntHave('children')
            ->orderBy('name')
            ->get();

        // Group progress by month
        $monthlyData = [];

        $start = Carbon::parse($this->startMonth . '-01');
        $end = Carbon::parse($this->endMonth . '-01')->endOfMonth();

        $current = $start->copy();
        while ($current->lte($end)) {
            $monthStart = $current->copy()->startOfMonth();
            $monthEnd = $current->copy()->endOfMonth();

            $monthKey = $monthStart->format('Y-m');

            $monthlyData[$monthKey] = [
                'month_start' => $monthStart->format('Y-m-d'),
                'month_end' => $monthEnd->format('Y-m-d'),
                'month_label' => $monthStart->format('F Y'),
                'tasks' => [],
            ];

            foreach ($leafTasks as $task) {
                // Get all progress in this month
                $monthProgress = TaskProgress::where('task_id', $task->id)
                    ->where('project_id', $this->projectId)
                    ->whereBetween('progress_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
                    ->orderBy('progress_date')
                    ->get();

                if ($monthProgress->isNotEmpty()) {
                    $firstProgress = $monthProgress->first();
                    $lastProgress = $monthProgress->last();

                    // Get progress before this month
                    $beforeMonth = TaskProgress::where('task_id', $task->id)
                        ->where('project_id', $this->projectId)
                        ->where('progress_date', '<', $monthStart->format('Y-m-d'))
                        ->orderBy('progress_date', 'desc')
                        ->first();

                    $startPercentage = $beforeMonth ? $beforeMonth->percentage : 0;
                    $endPercentage = $lastProgress->percentage;
                    $monthlyChange = $endPercentage - $startPercentage;

                    // Calculate avg daily velocity
                    $uniqueDays = $monthProgress->pluck('progress_date')->unique()->count();
                    $avgDailyVelocity = $uniqueDays > 0 ? $monthlyChange / $uniqueDays : 0;

                    $monthlyData[$monthKey]['tasks'][] = [
                        'task' => $task,
                        'start_percentage' => $startPercentage,
                        'end_percentage' => $endPercentage,
                        'monthly_change' => $monthlyChange,
                        'days_active' => $uniqueDays,
                        'avg_daily_velocity' => $avgDailyVelocity,
                        'updates_count' => $monthProgress->count(),
                    ];
                }
            }

            $current->addMonth();
        }

        // Remove months with no data
        $monthlyData = array_filter($monthlyData, function ($month) {
            return !empty($month['tasks']);
        });

        return [
            'project' => $project,
            'monthly_data' => $monthlyData,
        ];
    }

    /**
     * Export to CSV
     */
    public function exportCsv()
    {
        $data = $this->getReportData();

        if (!$data) {
            return;
        }

        $filename = 'monthly_report_' . $this->startMonth . '_to_' . $this->endMonth . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');

            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($file, ['Project', $data['project']->name]);
            fputcsv($file, ['Period', $this->startMonth . ' to ' . $this->endMonth]);
            fputcsv($file, []);

            fputcsv($file, ['Month', 'Task Name', 'Start %', 'End %', 'Monthly Change', 'Days Active', 'Avg Daily Velocity', 'Updates Count']);

            foreach ($data['monthly_data'] as $monthData) {
                foreach ($monthData['tasks'] as $taskData) {
                    fputcsv($file, [
                        $monthData['month_label'],
                        $taskData['task']->name,
                        number_format($taskData['start_percentage'], 2),
                        number_format($taskData['end_percentage'], 2),
                        number_format($taskData['monthly_change'], 2),
                        $taskData['days_active'],
                        number_format($taskData['avg_daily_velocity'], 2),
                        $taskData['updates_count'],
                    ]);
                }
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function with(): array
    {
        return [
            'projects' => $this->getAccessibleProjects(),
            'reportData' => $this->getReportData(),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Monthly Report') }}</flux:heading>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                {{ __('View monthly progress aggregated data') }}
            </p>
        </div>
        @if($reportData)
            <flux:button wire:click="exportCsv" variant="primary" icon="arrow-down-tray">
                {{ __('Export to CSV') }}
            </flux:button>
        @endif
    </div>

    <!-- Filters -->
    <div class="grid gap-4 md:grid-cols-3">
        <div>
            <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                {{ __('Project') }}
            </label>
            <flux:select wire:model.live="projectId" placeholder="{{ __('Select Project...') }}">
                @foreach($projects as $project)
                    <option value="{{ $project->id }}">{{ $project->name }}</option>
                @endforeach
            </flux:select>
        </div>

        <div>
            <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                {{ __('Start Date') }} ({{ __('Month') }})
            </label>
            <flux:input type="month" wire:model.live="startMonth" />
        </div>

        <div>
            <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                {{ __('End Date') }} ({{ __('Month') }})
            </label>
            <flux:input type="month" wire:model.live="endMonth" />
        </div>
    </div>

    @if(!$projectId)
        <div class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-neutral-300 bg-neutral-50 py-12 dark:border-neutral-700 dark:bg-neutral-800/50">
            <svg class="h-12 w-12 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <p class="mt-4 text-lg font-medium text-neutral-600 dark:text-neutral-400">
                {{ __('No Project Selected') }}
            </p>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-500">
                {{ __('Please select a project to start tracking progress') }}
            </p>
        </div>
    @elseif($reportData)
        @php
            $data = $reportData;
        @endphp

        <!-- Project Info -->
        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
            <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $data['project']->name }}</h3>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                {{ $data['project']->location->village_name ?? '' }}, {{ $data['project']->location->city_name ?? '' }}
            </p>
        </div>

        <!-- Monthly Data -->
        <div class="space-y-4">
            @forelse($data['monthly_data'] as $monthData)
                <div class="rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    <!-- Month Header -->
                    <div class="border-b border-neutral-200 bg-neutral-50 px-6 py-4 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ $monthData['month_label'] }}
                            </h3>
                            <flux:badge size="sm">{{ count($monthData['tasks']) }} {{ __('tasks') }}</flux:badge>
                        </div>
                    </div>

                    <!-- Tasks Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-800">
                                <tr>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Task Name') }}</th>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Start %') }}</th>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('End %') }}</th>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Monthly Change') }}</th>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Days Active') }}</th>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Avg Daily Velocity') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                @foreach($monthData['tasks'] as $taskData)
                                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                        <td class="px-6 py-4 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                            {{ $taskData['task']->name }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-neutral-600 dark:text-neutral-400">
                                            {{ number_format($taskData['start_percentage'], 2) }}%
                                        </td>
                                        <td class="px-6 py-4 text-sm text-neutral-600 dark:text-neutral-400">
                                            {{ number_format($taskData['end_percentage'], 2) }}%
                                        </td>
                                        <td class="px-6 py-4">
                                            @php
                                                $change = $taskData['monthly_change'];
                                                $changeColor = $change > 0 ? 'green' : ($change < 0 ? 'red' : 'zinc');
                                            @endphp
                                            <flux:badge color="{{ $changeColor }}" size="sm">
                                                @if($change > 0) + @endif{{ number_format($change, 2) }}%
                                            </flux:badge>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-neutral-600 dark:text-neutral-400">
                                            {{ $taskData['days_active'] }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-neutral-600 dark:text-neutral-400">
                                            {{ number_format($taskData['avg_daily_velocity'], 2) }}% / day
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-neutral-300 bg-neutral-50 py-12 dark:border-neutral-700 dark:bg-neutral-800/50">
                    <svg class="h-12 w-12 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                    </svg>
                    <p class="mt-4 text-lg font-medium text-neutral-600 dark:text-neutral-400">
                        {{ __('No Data') }}
                    </p>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-500">
                        {{ __('No progress updates found in the selected date range') }}
                    </p>
                </div>
            @endforelse
        </div>
    @endif
</div>

<?php

use App\Models\Office;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public ?int $projectId = null;

    public string $startDate;

    public string $endDate;

    public function mount(): void
    {
        // Default to last 30 days
        $this->endDate = now()->format('Y-m-d');
        $this->startDate = now()->subDays(30)->format('Y-m-d');
    }

    /**
     * Get accessible projects based on user role
     */
    private function getAccessibleProjects()
    {
        $currentUser = Auth::user();
        $query = Project::with(['location', 'partner', 'office']);

        // Admins see all projects
        if ($currentUser->isAdmin() || $currentUser->hasPermission('*')) {
            return $query->orderBy('name')->get();
        }

        // Get current user's office for coverage filtering
        if (! $currentUser->office_id) {
            return $currentUser->projects()->with(['location', 'partner', 'office'])->orderBy('name')->get();
        }

        $currentOffice = Office::with('level')->find($currentUser->office_id);

        if (! $currentOffice) {
            return $currentUser->projects()->with(['location', 'partner', 'office'])->orderBy('name')->get();
        }

        // Kodim Admins: Only projects in Koramils under their Kodim
        if ($currentOffice->level->level === 3) {
            $query->whereHas('office', function ($q) use ($currentUser) {
                $q->where('parent_id', $currentUser->office_id);
            });
        }
        // Koramil Admins: Only projects in their exact Koramil
        elseif ($currentOffice->level->level === 4) {
            $query->where('office_id', $currentUser->office_id);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Get daily progress data with changes
     * OPTIMIZED: Batch load previous progress to avoid N+1 queries
     */
    private function getReportData()
    {
        if (! $this->projectId) {
            return null;
        }

        $project = Project::with(['location', 'partner', 'office.parent'])->find($this->projectId);

        if (! $project) {
            return null;
        }

        // Get all progress records within date range
        $progressRecords = TaskProgress::where('project_id', $this->projectId)
            ->whereBetween('progress_date', [$this->startDate, $this->endDate])
            ->with('task')
            ->orderBy('progress_date', 'desc')
            ->orderBy('task_id')
            ->get();

        if ($progressRecords->isEmpty()) {
            return [
                'project' => $project,
                'daily_data' => [],
                'total_updates' => 0,
            ];
        }

        // OPTIMIZED: Batch load ALL previous progress for ALL tasks in ONE query
        // Get the latest progress BEFORE each date for each task
        $taskIds = $progressRecords->pluck('task_id')->unique()->toArray();

        // Get all progress records before the date range for these tasks
        $allPreviousProgress = TaskProgress::where('project_id', $this->projectId)
            ->whereIn('task_id', $taskIds)
            ->where('progress_date', '<', $this->endDate)
            ->orderBy('progress_date', 'desc')
            ->get()
            ->groupBy('task_id');

        // Build a lookup: task_id => [date => percentage]
        // For each task, get all historical progress keyed by date
        $progressLookup = [];
        foreach ($allPreviousProgress as $taskId => $records) {
            $progressLookup[$taskId] = $records->keyBy(fn ($r) => $r->progress_date->format('Y-m-d'));
        }

        // Group by date
        $dailyData = [];

        foreach ($progressRecords as $progress) {
            $date = is_string($progress->progress_date)
                ? $progress->progress_date
                : $progress->progress_date->format('Y-m-d');

            if (! isset($dailyData[$date])) {
                $dailyData[$date] = [];
            }

            // OPTIMIZED: Find previous progress from preloaded data (no query!)
            $previousPercentage = 0;
            if (isset($progressLookup[$progress->task_id])) {
                // Find the latest progress before this date
                $taskProgress = $progressLookup[$progress->task_id];
                foreach ($taskProgress as $progressDate => $prevProgress) {
                    if ($progressDate < $date) {
                        $previousPercentage = $prevProgress->percentage;
                        break; // Already sorted desc, so first match is the latest
                    }
                }
            }

            $dailyChange = $progress->percentage - $previousPercentage;

            $dailyData[$date][] = [
                'task' => $progress->task,
                'current_percentage' => $progress->percentage,
                'previous_percentage' => $previousPercentage,
                'daily_change' => $dailyChange,
                'notes' => $progress->notes,
            ];
        }

        return [
            'project' => $project,
            'daily_data' => $dailyData,
            'total_updates' => $progressRecords->count(),
        ];
    }

    /**
     * Export to CSV
     */
    public function exportCsv()
    {
        $data = $this->getReportData();

        if (! $data) {
            return;
        }

        $filename = 'daily_report_'.$this->startDate.'_to_'.$this->endDate.'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Headers
            fputcsv($file, ['Project', $data['project']->name]);
            fputcsv($file, ['Date Range', $this->startDate.' to '.$this->endDate]);
            fputcsv($file, ['Total Updates', $data['total_updates']]);
            fputcsv($file, []); // Empty row

            // Data headers
            fputcsv($file, ['Date', 'Task Name', 'Previous %', 'Current %', 'Daily Change', 'Notes']);

            // Data rows
            foreach ($data['daily_data'] as $date => $tasks) {
                foreach ($tasks as $taskData) {
                    fputcsv($file, [
                        $date,
                        $taskData['task']->name,
                        number_format($taskData['previous_percentage'], 2),
                        number_format($taskData['current_percentage'], 2),
                        number_format($taskData['daily_change'], 2),
                        $taskData['notes'] ?? '-',
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
            <flux:heading size="xl">{{ __('Daily Report') }}</flux:heading>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                {{ __('View daily progress changes and updates') }}
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
                {{ __('Start Date') }}
            </label>
            <flux:input type="date" wire:model.live="startDate" />
        </div>

        <div>
            <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                {{ __('End Date') }}
            </label>
            <flux:input type="date" wire:model.live="endDate" />
        </div>
    </div>

    @if(!$projectId)
        <!-- No Project Selected -->
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

        <!-- Summary -->
        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $data['project']->name }}</h3>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        {{ $data['project']->location->village_name ?? '' }}, {{ $data['project']->location->city_name ?? '' }}
                    </p>
                </div>
                <div class="text-right">
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">{{ __('Total Updates') }}</div>
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $data['total_updates'] }}</div>
                </div>
            </div>
        </div>

        <!-- Daily Progress Data -->
        <div class="space-y-4">
            @forelse($data['daily_data'] as $date => $tasks)
                <div class="rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    <!-- Date Header -->
                    <div class="border-b border-neutral-200 bg-neutral-50 px-6 py-4 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ \Carbon\Carbon::parse($date)->format('l, d M Y') }}
                            </h3>
                            <flux:badge size="sm">{{ count($tasks) }} {{ __('Updates Count') }}</flux:badge>
                        </div>
                    </div>

                    <!-- Tasks Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-800">
                                <tr>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Task Name') }}</th>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Previous %') }}</th>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Current %') }}</th>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Daily Change') }}</th>
                                    <th class="px-6 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Notes') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                @foreach($tasks as $taskData)
                                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                        <td class="px-6 py-4 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                            {{ $taskData['task']->name }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-neutral-600 dark:text-neutral-400">
                                            {{ number_format($taskData['previous_percentage'], 2) }}%
                                        </td>
                                        <td class="px-6 py-4 text-sm text-neutral-600 dark:text-neutral-400">
                                            {{ number_format($taskData['current_percentage'], 2) }}%
                                        </td>
                                        <td class="px-6 py-4">
                                            @php
                                                $change = $taskData['daily_change'];
                                                $changeColor = $change > 0 ? 'green' : ($change < 0 ? 'red' : 'zinc');
                                            @endphp
                                            <flux:badge color="{{ $changeColor }}" size="sm">
                                                @if($change > 0) + @endif{{ number_format($change, 2) }}%
                                            </flux:badge>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-neutral-600 dark:text-neutral-400">
                                            {{ $taskData['notes'] ?? '-' }}
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

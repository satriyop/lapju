<?php

use App\Models\Office;
use App\Models\Project;
use App\Models\Location;
use App\Models\TaskProgress;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;



layout('components.layouts.app');

new class extends Component
{
    public ?int $projectId = null;
    public ?int $officeId = null;
    public ?int $locationId = null;
    public string $asOfDate;

    public function mount(): void
    {
        // Default to today
        $this->asOfDate = now()->format('Y-m-d');
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
        if (!$currentUser->office_id) {
            // Reporters without office only see assigned projects
            return $currentUser->projects()->with(['location', 'partner', 'office'])->orderBy('name')->get();
        }

        $currentOffice = Office::with('level')->find($currentUser->office_id);

        if (!$currentOffice) {
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
     * Get offices based on user role
     */
    private function getAccessibleOffices()
    {
        $currentUser = Auth::user();

        // Admins see all offices
        if ($currentUser->isAdmin()) {
            return Office::with('level', 'parent')->orderBy('name')->get();
        }

        // No office filter for reporters
        if (!$currentUser->office_id) {
            return collect();
        }

        $currentOffice = Office::with('level')->find($currentUser->office_id);

        if (!$currentOffice) {
            return collect();
        }

        // Kodim Admin: Their Kodim + child Koramils
        if ($currentOffice->level->level === 3) {
            return Office::with('level', 'parent')
                ->where(function ($q) use ($currentUser) {
                    $q->where('id', $currentUser->office_id)
                        ->orWhere('parent_id', $currentUser->office_id);
                })
                ->orderBy('name')
                ->get();
        }

        // Koramil Admin: Only their Koramil
        if ($currentOffice->level->level === 4) {
            return Office::with('level', 'parent')
                ->where('id', $currentUser->office_id)
                ->get();
        }

        return collect();
    }

    /**
     * Get progress data for the report
     */
    
    private function getReportData()
    {
        if (!$this->projectId) {
            return null;
        }

        $project = Project::with([
            'location',
            'partner',
            'office.parent',
            'tasks' => function ($query) {
                $query->whereNull('parent_id')->orderBy('_lft');
            },
            'tasks.children'
        ])->find($this->projectId);

        if (!$project) {
            return null;
        }

        // Get all tasks with their latest progress up to asOfDate
        $tasks = $project->tasks()->with(['children'])->get();
        $taskProgress = $this->getLatestProgressMap($project->id, $this->asOfDate);

        $reportTasks = [];
        foreach ($tasks as $rootTask) {
            $reportTasks[] = $this->buildTaskHierarchy($rootTask, $taskProgress);
        }

        // Calculate overall project completion
        $allLeafTasks = $this->getAllLeafTasks($tasks);
        $totalTasks = $allLeafTasks->count();
        $completedTasks = $allLeafTasks->filter(function ($task) use ($taskProgress) {
            return isset($taskProgress[$task->id]) && $taskProgress[$task->id]['percentage'] >= 100;
        })->count();

        $totalPercentage = 0;
        $totalWeight = 0;
        foreach ($allLeafTasks as $task) {
            $weight = $task->weight ?? 1;
            $percentage = $taskProgress[$task->id]['percentage'] ?? 0;
            $totalPercentage += $percentage * $weight;
            $totalWeight += $weight;
        }

        $overallCompletion = $totalWeight > 0 ? round($totalPercentage / $totalWeight, 2) : 0;

        return [
            'project' => $project,
            'tasks' => $reportTasks,
            'overall_completion' => $overallCompletion,
            'tasks_completed' => $completedTasks,
            'total_tasks' => $totalTasks,
        ];
    }

    /**
     * Get latest progress for each task up to a specific date
     */
    private function getLatestProgressMap(int $projectId, string $asOfDate): array
    {
        $progressRecords = TaskProgress::where('project_id', $projectId)
            ->where('progress_date', '<=', $asOfDate)
            ->whereIn('id', function ($query) use ($projectId, $asOfDate) {
                $query->selectRaw('MAX(id)')
                    ->from('task_progress')
                    ->where('project_id', $projectId)
                    ->where('progress_date', '<=', $asOfDate)
                    ->groupBy('task_id');
            })
            ->get();

        $map = [];
        foreach ($progressRecords as $record) {
            $map[$record->task_id] = [
                'percentage' => $record->percentage,
                'date' => $record->progress_date,
                'notes' => $record->notes,
            ];
        }

        return $map;
    }

    /**
     * Build task hierarchy with progress data
     */
    private function buildTaskHierarchy($task, $progressMap, $level = 0)
    {
        $children = $task->children()
            ->orderBy('_lft')
            ->get();

        $hasChildren = $children->isNotEmpty();

        // For leaf tasks, use actual progress
        // For parent tasks, calculate weighted average
        if ($hasChildren) {
            $childData = [];
            $totalPercentage = 0;
            $totalWeight = 0;
            $leafCount = 0;

            foreach ($children as $child) {
                $childInfo = $this->buildTaskHierarchy($child, $progressMap, $level + 1);
                $childData[] = $childInfo;

                $weight = $child->weight ?? 1;
                $totalPercentage += $childInfo['percentage'] * $weight;
                $totalWeight += $weight;
                $leafCount += $childInfo['leaf_count'];
            }

            $avgPercentage = $totalWeight > 0 ? round($totalPercentage / $totalWeight, 2) : 0;

            return [
                'task' => $task,
                'percentage' => $avgPercentage,
                'last_updated' => null,
                'notes' => null,
                'has_children' => true,
                'children' => $childData,
                'level' => $level,
                'leaf_count' => $leafCount,
            ];
        } else {
            // Leaf task
            $progress = $progressMap[$task->id] ?? null;

            return [
                'task' => $task,
                'percentage' => $progress ? $progress['percentage'] : 0,
                'last_updated' => $progress ? $progress['date'] : null,
                'notes' => $progress ? $progress['notes'] : null,
                'has_children' => false,
                'children' => [],
                'level' => $level,
                'leaf_count' => 1,
            ];
        }
    }

    /**
     * Get all leaf tasks from a collection
     */
    private function getAllLeafTasks($tasks)
    {
        $leafTasks = collect();

        foreach ($tasks as $task) {
            if ($task->children()->count() === 0) {
                $leafTasks->push($task);
            } else {
                $leafTasks = $leafTasks->merge($this->getAllLeafTasks($task->children));
            }
        }

        return $leafTasks;
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

        $filename = 'cumulative_report_' . $this->asOfDate . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Headers
            fputcsv($file, ['Project', $data['project']->name]);
            fputcsv($file, ['Location', ($data['project']->location->village_name ?? '') . ', ' . ($data['project']->location->city_name ?? '')]);
            fputcsv($file, ['Office', $data['project']->office->name ?? '-']);
            fputcsv($file, ['As of Date', $this->asOfDate]);
            fputcsv($file, ['Overall Completion', $data['overall_completion'] . '%']);
            fputcsv($file, ['Tasks Completed', $data['tasks_completed'] . ' / ' . $data['total_tasks']]);
            fputcsv($file, []); // Empty row

            // Task data headers
            fputcsv($file, ['Task Name', 'Volume', 'Unit', 'Weight', 'Price', 'Current %', 'Last Updated', 'Status']);

            // Task data rows
            foreach ($data['tasks'] as $taskData) {
                $this->writeCsvTaskRow($file, $taskData);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Write task row to CSV (recursive for hierarchy)
     */
    private function writeCsvTaskRow($file, $taskData, $indent = '')
    {
        $task = $taskData['task'];
        $status = $taskData['percentage'] >= 100 ? 'Completed' :
                 ($taskData['percentage'] >= 50 ? 'On Track' :
                 ($taskData['percentage'] > 0 ? 'At Risk' : 'No Data'));

        fputcsv($file, [
            $indent . $task->name,
            $task->volume ?? '-',
            $task->unit ?? '-',
            $task->weight ?? '-',
            $task->price ?? '-',
            number_format($taskData['percentage'], 2) . '%',
            $taskData['last_updated'] ?? '-',
            $status,
        ]);

        // Write children
        if ($taskData['has_children']) {
            foreach ($taskData['children'] as $child) {
                $this->writeCsvTaskRow($file, $child, $indent . '  ');
            }
        }
    }

    public function with(): array
    {
        return [
            'projects' => $this->getAccessibleProjects(),
            'offices' => $this->getAccessibleOffices(),
            'locations' => Location::orderBy('village_name')->get(),
            'reportData' => $this->getReportData(),
        ];
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('To-Date Report') }}</flux:heading>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                {{ __('View current cumulative progress status') }}
            </p>
        </div>
        @if($reportData)
            <flux:button wire:click="exportCsv" variant="primary" icon="arrow-down-tray">
                {{ __('Export to CSV') }}
            </flux:button>
        @endif
    </div>

    <!-- Filters -->
    <div class="grid gap-4 md:grid-cols-4">
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

        @if($offices->isNotEmpty())
            <div>
                <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('Office') }}
                </label>
                <flux:select wire:model.live="officeId">
                    <option value="">{{ __('All') }}</option>
                    @foreach($offices as $office)
                        <option value="{{ $office->id }}">
                            {{ $office->name }}
                            @if($office->parent)
                                ({{ $office->parent->name }})
                            @endif
                        </option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <div>
            <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                {{ __('Location') }}
            </label>
            <flux:select wire:model.live="locationId">
                <option value="">{{ __('All') }}</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}">
                        {{ $location->village_name }}, {{ $location->city_name }}
                    </option>
                @endforeach
            </flux:select>
        </div>

        <div>
            <label class="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                {{ __('As of Date') }}
            </label>
            <flux:input type="date" wire:model.live="asOfDate" />
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

        <!-- Summary Cards -->
        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">{{ __('Overall Completion') }}</div>
                <div class="mt-2 text-3xl font-bold text-blue-600 dark:text-blue-400">
                    {{ number_format($data['overall_completion'], 2) }}%
                </div>
            </div>

            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">{{ __('Tasks Completed') }}</div>
                <div class="mt-2 text-3xl font-bold text-green-600 dark:text-green-400">
                    {{ $data['tasks_completed'] }} / {{ $data['total_tasks'] }}
                </div>
            </div>

            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">{{ __('As of Date') }}</div>
                <div class="mt-2 text-3xl font-bold text-amber-600 dark:text-amber-400">
                    {{ \Carbon\Carbon::parse($asOfDate)->format('d M Y') }}
                </div>
            </div>
        </div>

        <!-- Project Info -->
        <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
            <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ $data['project']->name }}</h3>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">{{ __('Location') }}</div>
                    <div class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">
                        {{ $data['project']->location->village_name ?? '-' }}, {{ $data['project']->location->city_name ?? '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">{{ __('Office') }}</div>
                    <div class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">
                        {{ $data['project']->office->name ?? '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-sm font-medium text-neutral-600 dark:text-neutral-400">{{ __('Partner') }}</div>
                    <div class="mt-1 text-sm text-neutral-900 dark:text-neutral-100">
                        {{ $data['project']->partner->name ?? '-' }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Tasks Table -->
        <div class="overflow-x-auto rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Task Name') }}</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Volume') }}</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Weight') }}</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Current %') }}</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Last Updated') }}</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach($data['tasks'] as $taskData)
                        @include('livewire.reports.partials.task-row', ['taskData' => $taskData])
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

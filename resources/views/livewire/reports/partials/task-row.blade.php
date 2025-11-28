@php
    $task = $taskData['task'];
    $percentage = $taskData['percentage'];
    $lastUpdated = $taskData['last_updated'];
    $level = $taskData['level'];
    $hasChildren = $taskData['has_children'];

    $statusColor = $percentage >= 100 ? 'green' :
                   ($percentage >= 50 ? 'blue' :
                   ($percentage > 0 ? 'amber' : 'zinc'));

    $statusText = $percentage >= 100 ? __('Completed') :
                  ($percentage >= 50 ? __('On Track') :
                  ($percentage > 0 ? __('At Risk') : __('No Data')));
@endphp

<tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
    <td class="px-4 py-3">
        <div class="flex items-center gap-2" style="padding-left: {{ $level * 1.5 }}rem;">
            @if($hasChildren)
                <svg class="h-4 w-4 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                </svg>
            @endif
            <span class="text-sm {{ $hasChildren ? 'font-semibold' : '' }} text-neutral-900 dark:text-neutral-100">
                {{ $task->name }}
            </span>
            @if($hasChildren)
                <span class="text-xs text-neutral-500">
                    ({{ __('avg of') }} {{ $taskData['leaf_count'] }} {{ __('tasks') }})
                </span>
            @endif
        </div>
    </td>
    <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
        @if($task->volume)
            {{ $task->volume }} {{ $task->unit ?? '' }}
        @else
            -
        @endif
    </td>
    <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
        {{ $task->weight ?? '-' }}
    </td>
    <td class="px-4 py-3">
        <div class="flex items-center gap-2">
            <div class="h-2 w-24 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                <div class="h-full bg-{{ $statusColor }}-500 dark:bg-{{ $statusColor }}-400" style="width: {{ min($percentage, 100) }}%"></div>
            </div>
            <span class="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                {{ number_format($percentage, 2) }}%
            </span>
        </div>
    </td>
    <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
        @if($lastUpdated)
            {{ \Carbon\Carbon::parse($lastUpdated)->format('d M Y') }}
        @else
            -
        @endif
    </td>
    <td class="px-4 py-3">
        <flux:badge color="{{ $statusColor }}" size="sm">
            {{ $statusText }}
        </flux:badge>
    </td>
</tr>

@if($hasChildren)
    @foreach($taskData['children'] as $childData)
        @include('livewire.reports.partials.task-row', ['taskData' => $childData])
    @endforeach
@endif

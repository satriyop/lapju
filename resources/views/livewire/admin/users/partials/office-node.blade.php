{{-- Office Header Row --}}
<tr class="bg-neutral-100 dark:bg-neutral-800/70 cursor-pointer hover:bg-neutral-200 dark:hover:bg-neutral-800"
    @click="toggleOffice({{ $node['office']->id }})"
    wire:key="office-header-{{ $node['office']->id }}">
    <td colspan="7" class="px-4 py-3">
        <div class="flex items-center gap-3" style="padding-left: {{ $level * 2 }}rem;">
            {{-- Expand/Collapse Icon --}}
            <svg class="h-5 w-5 text-neutral-600 dark:text-neutral-400 transition-transform"
                 :class="{ 'rotate-90': isExpanded({{ $node['office']->id }}) }"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>

            {{-- Office Level Badge --}}
            @if($node['office']->level)
                @php
                    $levelColors = [
                        1 => 'red',
                        2 => 'orange',
                        3 => 'blue',
                        4 => 'green',
                    ];
                    $levelColor = $levelColors[$node['office']->level->level] ?? 'neutral';
                @endphp
                <flux:badge color="{{ $levelColor }}" size="sm">
                    {{ $node['office']->level->name }}
                </flux:badge>
            @endif

            {{-- Office Name --}}
            <span class="font-semibold text-neutral-900 dark:text-neutral-100">
                {{ $node['office']->name }}
            </span>

            {{-- User Count --}}
            <span class="text-sm text-neutral-600 dark:text-neutral-400">
                {{ $node['user_count'] }} {{ Str::plural('user', $node['user_count']) }}
            </span>

            {{-- Pending Count Badge --}}
            @if($node['pending_count'] > 0)
                <flux:badge color="amber" size="sm">
                    {{ $node['pending_count'] }} pending
                </flux:badge>
            @endif
        </div>
    </td>
</tr>

{{-- Users in this office (Collapsible) --}}
@foreach($node['users'] as $user)
    <tr wire:key="user-{{ $user->id }}"
        class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
        x-show="isExpanded({{ $node['office']->id }})"
        x-transition>
        <td class="px-4 py-3" style="padding-left: {{ ($level + 1) * 2 + 1 }}rem;">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 text-sm font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                    {{ $user->initials() }}
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ $user->name }}</span>
                        @if($user->projects_count > 0)
                            <flux:badge size="sm" class="bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                                {{ $user->projects_count }} {{ Str::plural('project', $user->projects_count) }}
                            </flux:badge>
                        @endif
                    </div>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400">{{ $user->phone }}</div>
                </div>
            </div>
        </td>
        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
            {{ $user->nrp ?? '-' }}
        </td>
        <td class="px-4 py-3 text-sm">
            @if($user->roles->count() > 0)
                <div class="flex flex-wrap gap-1">
                    @foreach($user->roles as $role)
                        <flux:badge size="sm">{{ $role->name }}</flux:badge>
                    @endforeach
                </div>
            @else
                <span class="text-neutral-400">No role</span>
            @endif
        </td>
        <td class="px-4 py-3 text-sm">
            @if($user->office)
                <div class="text-neutral-900 dark:text-neutral-100">{{ $user->office->name }}</div>
                @if($user->office->parent)
                    <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $user->office->parent->name }}</div>
                @endif
            @else
                <span class="text-neutral-400">-</span>
            @endif
        </td>
        <td class="px-4 py-3 text-sm">
            <div class="flex flex-col gap-1">
                @if($user->is_admin)
                    <flux:badge color="purple" size="sm">Admin</flux:badge>
                @endif
                @if($user->is_approved)
                    <flux:badge color="green" size="sm">Approved</flux:badge>
                @else
                    <flux:badge color="amber" size="sm">Pending</flux:badge>
                @endif
            </div>
        </td>
        <td class="px-4 py-3 text-sm">
            <div class="flex flex-col gap-1.5">
                <span class="text-neutral-900 dark:text-neutral-100 font-medium">
                    {{ $user->projects->count() }} {{ Str::plural('project', $user->projects->count()) }}
                </span>
                <flux:button
                    wire:click="openProjectModal({{ $user->id }})"
                    size="sm"
                    variant="ghost"
                    class="w-fit"
                >
                    Assign
                </flux:button>
            </div>
        </td>
        <td class="px-4 py-3 text-right text-sm">
            <div class="flex items-center justify-end gap-2">
                @if(!$user->is_approved)
                    <flux:button
                        wire:click="approveUser({{ $user->id }})"
                        wire:confirm="Approve this user?"
                        size="sm"
                        variant="primary"
                    >
                        Approve
                    </flux:button>
                    <flux:button
                        wire:click="rejectUser({{ $user->id }})"
                        wire:confirm="Reject and delete this user?"
                        size="sm"
                        variant="danger"
                    >
                        Reject
                    </flux:button>
                @else
                    <flux:button wire:click="editUser({{ $user->id }})" size="sm" variant="ghost">
                        Edit
                    </flux:button>
                    @if($user->id !== Auth::id())
                        <flux:button
                            wire:click="deleteUser({{ $user->id }})"
                            wire:confirm="Delete this user?"
                            size="sm"
                            variant="ghost"
                            class="text-red-600 hover:text-red-700"
                        >
                            Delete
                        </flux:button>
                    @endif
                @endif
            </div>
        </td>
    </tr>
@endforeach

{{-- Recursively render child offices --}}
@foreach($node['children'] as $childNode)
    @include('livewire.admin.users.partials.office-node', ['node' => $childNode, 'level' => $level + 1])
@endforeach

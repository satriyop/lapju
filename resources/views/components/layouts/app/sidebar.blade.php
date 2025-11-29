<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <flux:sidebar.header>
                <a href="{{ route('dashboard') }}" class="flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                    <x-app-logo />
                </a>
                <flux:sidebar.collapse class="hidden lg:flex" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group heading="{{ __('Platform Progress') }}" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:sidebar.item>
                    @can('access-map')
                    <flux:sidebar.item icon="map" :href="route('map.index')" :current="request()->routeIs('map.index')" wire:navigate>{{ __('Project Map') }}</flux:sidebar.item>
                    @endcan
                    <flux:sidebar.item icon="calendar" :href="route('calendar-progress.index')" :current="request()->routeIs('calendar-progress.index')" wire:navigate>{{ __('Calendar Progress') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="folder" :href="route('projects.index')" :current="request()->routeIs('projects.index')" wire:navigate>{{ __('Projects') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="chart-bar" :href="route('progress.index')" :current="request()->routeIs('progress.index')" wire:navigate>{{ __('Progress') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="document-chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>{{ __('Reports') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="table-cells" :href="route('lapjusik.index')" :current="request()->routeIs('lapjusik.*')" wire:navigate>{{ __('Lapjusik') }}</flux:sidebar.item>
                </flux:sidebar.group>

                @if(auth()->user()->hasPermission('manage_users'))
                    <flux:sidebar.group heading="{{ __('User Management') }}" class="grid">
                        <flux:sidebar.item icon="user-group" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.index')" wire:navigate>{{ __('Users') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @if(auth()->user()->isAdmin())
                <flux:sidebar.group heading="{{ __('Administration') }}" class="grid">
                    <flux:sidebar.item icon="building-office" :href="route('admin.offices.index')" :current="request()->routeIs('admin.offices.index')" wire:navigate>{{ __('Offices') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="map-pin" :href="route('admin.locations.index')" :current="request()->routeIs('admin.locations.index')" wire:navigate>{{ __('Locations') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="users" :href="route('partners.index')" :current="request()->routeIs('partners.index')" wire:navigate>{{ __('Partners') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="shield-check" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.index')" wire:navigate>{{ __('Roles') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="document-duplicate" :href="route('admin.task-templates.index')" :current="request()->routeIs('admin.task-templates.index')" wire:navigate>{{ __('Task Templates') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="cog" :href="route('admin.settings.index')" :current="request()->routeIs('admin.settings.index')" wire:navigate>{{ __('Settings') }}</flux:sidebar.item>
                </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown class="max-lg:hidden" position="bottom" align="start">
                <flux:sidebar.profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon:trailing="chevrons-up-down"
                    data-test="sidebar-menu-button"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>

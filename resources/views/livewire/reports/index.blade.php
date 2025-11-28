<?php

use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    //
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Reports') }}</flux:heading>
        <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
            {{ __('Choose Report Type') }}
        </p>
    </div>

    <!-- Report Type Cards -->
    <div class="grid gap-6 md:grid-cols-2">
        <!-- Daily Report Card -->
        <a href="{{ route('reports.daily') }}" wire:navigate
           class="group block overflow-hidden rounded-xl border border-neutral-200 bg-white p-6 transition-all hover:border-blue-300 hover:shadow-lg dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-blue-700">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900">
                    <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ __('Daily Report') }}
                    </h3>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        {{ __('View daily progress changes and updates') }}
                    </p>
                    <div class="mt-3 flex items-center gap-2 text-sm font-medium text-blue-600 dark:text-blue-400">
                        {{ __('View Report') }}
                        <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </a>

        <!-- Weekly Report Card -->
        <a href="{{ route('reports.weekly') }}" wire:navigate
           class="group block overflow-hidden rounded-xl border border-neutral-200 bg-white p-6 transition-all hover:border-green-300 hover:shadow-lg dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-green-700">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900">
                    <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ __('Weekly Report') }}
                    </h3>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        {{ __('View weekly progress summary and trends') }}
                    </p>
                    <div class="mt-3 flex items-center gap-2 text-sm font-medium text-green-600 dark:text-green-400">
                        {{ __('View Report') }}
                        <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </a>

        <!-- Monthly Report Card -->
        <a href="{{ route('reports.monthly') }}" wire:navigate
           class="group block overflow-hidden rounded-xl border border-neutral-200 bg-white p-6 transition-all hover:border-purple-300 hover:shadow-lg dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-purple-700">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900">
                    <svg class="h-6 w-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ __('Monthly Report') }}
                    </h3>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        {{ __('View monthly progress aggregated data') }}
                    </p>
                    <div class="mt-3 flex items-center gap-2 text-sm font-medium text-purple-600 dark:text-purple-400">
                        {{ __('View Report') }}
                        <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </a>

        <!-- To-Date Report Card -->
        <a href="{{ route('reports.to-date') }}" wire:navigate
           class="group block overflow-hidden rounded-xl border border-neutral-200 bg-white p-6 transition-all hover:border-amber-300 hover:shadow-lg dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-amber-700">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900">
                    <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ __('To-Date Report') }}
                    </h3>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        {{ __('View current cumulative progress status') }}
                    </p>
                    <div class="mt-3 flex items-center gap-2 text-sm font-medium text-amber-600 dark:text-amber-400">
                        {{ __('View Report') }}
                        <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Info Box -->
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
        <div class="flex gap-3">
            <svg class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div class="flex-1">
                <p class="text-sm font-medium text-blue-900 dark:text-blue-100">
                    {{ __('Note') }}
                </p>
                <p class="mt-1 text-sm text-blue-800 dark:text-blue-200">
                    {{ __('All reports respect your role-based access control. You will only see data for projects and offices you have permission to view.') }}
                </p>
            </div>
        </div>
    </div>
</div>

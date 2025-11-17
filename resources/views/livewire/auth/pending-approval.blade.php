<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.auth.simple');

new class extends Component
{
    public function logout(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect(route('login'), navigate: true);
    }
}; ?>

<div class="flex flex-col items-center gap-6 text-center">
        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900">
            <svg class="h-8 w-8 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>

        <div>
            <h2 class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">
                {{ __('Registration Pending Approval') }}
            </h2>
            <p class="mt-2 text-neutral-600 dark:text-neutral-400">
                {{ __('Thank you for registering! Your account is currently under review.') }}
            </p>
        </div>

        <div class="w-full rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/30">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                {{ __('An administrator will review your registration and grant access to the system. You will be notified once your account has been approved.') }}
            </p>
        </div>

        <div class="space-y-2">
            <p class="text-sm text-neutral-600 dark:text-neutral-400">
                <strong>{{ __('Name:') }}</strong> {{ Auth::user()->name }}
            </p>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">
                <strong>{{ __('Email:') }}</strong> {{ Auth::user()->email }}
            </p>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">
                <strong>{{ __('NRP:') }}</strong> {{ Auth::user()->nrp ?? 'N/A' }}
            </p>
            <p class="text-sm text-neutral-600 dark:text-neutral-400">
                <strong>{{ __('Registered:') }}</strong> {{ Auth::user()->created_at->format('M d, Y H:i') }}
            </p>
        </div>

        <div class="flex gap-4">
            <flux:button wire:click="$refresh" variant="outline">
                {{ __('Check Status') }}
            </flux:button>
            <flux:button wire:click="logout" variant="danger">
                {{ __('Logout') }}
            </flux:button>
        </div>
    </div>
</div>

<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// Route for pending approval - accessible by authenticated but unapproved users
Route::middleware(['auth'])->group(function () {
    Volt::route('pending-approval', 'auth.pending-approval')->name('pending-approval');
});

// Protected routes requiring authentication AND approval
Route::middleware(['auth', 'approved'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');
    Volt::route('tasks', 'tasks.index')->name('tasks.index');
    Volt::route('customers', 'customers.index')->name('customers.index');
    Volt::route('projects', 'projects.index')->name('projects.index');
    Volt::route('progress', 'progress.index')->name('progress.index');
    Volt::route('locations', 'locations.index')->name('locations.index');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('user-password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');
});

// Admin routes - require both approval and admin privileges
Route::middleware(['auth', 'approved', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('users', 'admin.users.index')->name('users.index');
});

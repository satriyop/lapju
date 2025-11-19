<?php

use Illuminate\Support\Facades\Route;
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
    Volt::route('partners', 'partners.index')->name('partners.index');
    Volt::route('projects', 'projects.index')->name('projects.index');
    Volt::route('progress', 'progress.index')->name('progress.index');
    Volt::route('calendar-progress', 'calendar-progress')->name('calendar-progress.index');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('user-password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');
});

// User management route - accessible by admins and users with manage_users permission
Route::middleware(['auth', 'approved'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('users', 'admin.users.index')->name('users.index');
});

// Admin-only routes
Route::middleware(['auth', 'approved', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('offices', 'offices')->name('offices.index');
    Volt::route('locations', 'locations.index')->name('locations.index');
    Volt::route('roles', 'roles.index')->name('roles.index');
    Volt::route('settings', 'admin.settings.index')->name('settings.index');
    Volt::route('task-templates', 'admin.task-templates.index')->name('task-templates.index');
});

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Clean up orphaned Livewire temporary file uploads that were not processed.
| This prevents disk space accumulation from abandoned photo uploads.
|
*/

Schedule::command('livewire:cleanup-temp-uploads')->hourly();

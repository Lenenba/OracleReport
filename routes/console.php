<?php

use Illuminate\Foundation\Inspiring;
use App\Services\ObjectMapping\ObjectMappingSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('object-mapping:sync {--force}', function (ObjectMappingSyncService $syncService) {
    $force = (bool) $this->option('force');
    $results = $syncService->sync($force);
    $this->info('Object mapping sync completed.');
    $this->line(json_encode($results));
})->purpose('Sync object mapping data from config Excel files');

Schedule::command('object-mapping:sync')->hourly();

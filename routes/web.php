<?php

use App\Http\Controllers\BiPublisherController;
use App\Http\Controllers\ConfigCompareController;
use App\Http\Controllers\SqlRunnerController;
use App\Http\Controllers\ObjectMappingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

$bypassLogin = (bool) config('app.bypass_login');

if ($bypassLogin) {
    Route::redirect('/', '/dashboard')->name('home');

    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('bi-publisher', [BiPublisherController::class, 'index'])
        ->name('bi-publisher.index');
    Route::post('bi-publisher/run', [BiPublisherController::class, 'run'])
        ->name('bi-publisher.run');

    Route::get('config-compare', [ConfigCompareController::class, 'index'])
        ->name('config-compare.index');
    Route::get('object-mapping', [ObjectMappingController::class, 'index'])
        ->name('object-mapping.index');
    Route::get('object-mapping/details', [ObjectMappingController::class, 'details'])
        ->name('object-mapping.details');
    Route::post('object-mapping/refresh', [ObjectMappingController::class, 'refresh'])
        ->name('object-mapping.refresh');
    Route::post('config-compare/compare', [ConfigCompareController::class, 'compare'])
        ->name('config-compare.compare');
    Route::post('config-compare/transform', [ConfigCompareController::class, 'transform'])
        ->name('config-compare.transform');
    Route::post('config-compare/save', [ConfigCompareController::class, 'save'])
        ->name('config-compare.save');
    Route::delete('config-compare/entries/{entry}', [ConfigCompareController::class, 'destroyEntry'])
        ->name('config-compare.entries.destroy');
    Route::delete('config-compare/runs/{run}', [ConfigCompareController::class, 'destroyRun'])
        ->name('config-compare.runs.destroy');

    Route::get('sql-runner', [SqlRunnerController::class, 'index'])
        ->name('sql-runner.index');
    Route::post('sql-runner/run', [SqlRunnerController::class, 'run'])
        ->name('sql-runner.run');
} else {
    Route::get('/', function () {
        return Inertia::render('welcome', [
            'canRegister' => Features::enabled(Features::registration()),
        ]);
    })->name('home');

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('dashboard', function () {
            return Inertia::render('dashboard');
        })->name('dashboard');

        Route::get('bi-publisher', [BiPublisherController::class, 'index'])
            ->name('bi-publisher.index');
        Route::post('bi-publisher/run', [BiPublisherController::class, 'run'])
            ->name('bi-publisher.run');

        Route::get('config-compare', [ConfigCompareController::class, 'index'])
            ->name('config-compare.index');
        Route::get('object-mapping', [ObjectMappingController::class, 'index'])
            ->name('object-mapping.index');
        Route::get('object-mapping/details', [ObjectMappingController::class, 'details'])
            ->name('object-mapping.details');
        Route::post('object-mapping/refresh', [ObjectMappingController::class, 'refresh'])
            ->name('object-mapping.refresh');
        Route::post('config-compare/compare', [ConfigCompareController::class, 'compare'])
            ->name('config-compare.compare');
        Route::post('config-compare/transform', [ConfigCompareController::class, 'transform'])
            ->name('config-compare.transform');
        Route::post('config-compare/save', [ConfigCompareController::class, 'save'])
            ->name('config-compare.save');
        Route::delete('config-compare/entries/{entry}', [ConfigCompareController::class, 'destroyEntry'])
            ->name('config-compare.entries.destroy');
        Route::delete('config-compare/runs/{run}', [ConfigCompareController::class, 'destroyRun'])
            ->name('config-compare.runs.destroy');

        Route::get('sql-runner', [SqlRunnerController::class, 'index'])
            ->name('sql-runner.index');
        Route::post('sql-runner/run', [SqlRunnerController::class, 'run'])
            ->name('sql-runner.run');
    });
}

require __DIR__.'/settings.php';

<?php

use App\Http\Controllers\BiPublisherController;
use App\Http\Controllers\ConfigCompareController;
use App\Http\Controllers\SqlRunnerController;
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
    Route::post('config-compare/transform', [ConfigCompareController::class, 'transform'])
        ->name('config-compare.transform');
    Route::post('config-compare/save', [ConfigCompareController::class, 'save'])
        ->name('config-compare.save');

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
        Route::post('config-compare/transform', [ConfigCompareController::class, 'transform'])
            ->name('config-compare.transform');
        Route::post('config-compare/save', [ConfigCompareController::class, 'save'])
            ->name('config-compare.save');

        Route::get('sql-runner', [SqlRunnerController::class, 'index'])
            ->name('sql-runner.index');
        Route::post('sql-runner/run', [SqlRunnerController::class, 'run'])
            ->name('sql-runner.run');
    });
}

require __DIR__.'/settings.php';

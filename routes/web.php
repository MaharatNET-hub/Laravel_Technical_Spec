<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DemoController::class, 'index']);

Route::prefix('api')->group(function () {
    Route::get('state', [DemoController::class, 'state']);
    Route::post('start-sample', [DemoController::class, 'startSample']);
    Route::post('upload', [DemoController::class, 'upload']);
    Route::post('step', [DemoController::class, 'step']);
    Route::post('review', [DemoController::class, 'review']);
    Route::post('settings', [DemoController::class, 'settings']);
    Route::post('rules', [DemoController::class, 'rules']);
    Route::post('generate', [DemoController::class, 'generate']);
});

Route::get('out/{name}', [DemoController::class, 'output'])->where('name', '[A-Za-z0-9._-]+');

require __DIR__ . '/v2.php';

<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OcrController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('ocr')->group(function () {
        Route::post('/process', [OcrController::class, 'process']);
        Route::post('/save', [OcrController::class, 'save']);
        Route::get('/history', [OcrController::class, 'history']);
        Route::get('/process-count', [OcrController::class, 'processCount']);
        Route::get('/{id}', [OcrController::class, 'show']);
    });
});

Route::get('/run-migrations', function () {
    try {
        Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();
        return response()->json([
            'status' => 'success',
            'message' => 'Migrations ran successfully!',
            'output' => $output,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'An error occurred while running migrations.',
            'error' => $e->getMessage(),
        ], 500);
    }
});

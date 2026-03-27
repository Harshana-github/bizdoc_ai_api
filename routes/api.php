<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentTypeController;
use App\Http\Controllers\Api\OcrController;
use App\Http\Controllers\Api\SystemSettingController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\ImageCategorizerController;
use App\Http\Controllers\ImageController;
use App\Models\OcrProcess;
use App\Models\OcrResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/system-settings', [SystemSettingController::class, 'index']);
Route::post('/system-settings', [SystemSettingController::class, 'update']);

Route::prefix('document-types')->group(function () {
    Route::get('/', [DocumentTypeController::class, 'index']);
});

Route::get('/test-gemini', function () {
    try {
        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'GEMINI_API_KEY not found in .env',
            ], 500);
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(
            "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key={$apiKey}",
            [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => "Say hello from Gemini API test."]
                        ]
                    ]
                ]
            ]
        );

        return response()->json([
            'success' => $response->successful(),
            'status' => $response->status(),
            'env_key_exists' => !empty($apiKey),
            'response' => $response->json(),
            'raw_body' => $response->body(),
        ], $response->status());
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Request failed',
            'error' => $e->getMessage(),
        ], 500);
    }
});

Route::prefix('templates')->group(function () {
    Route::post('/', [TemplateController::class, 'store']);
    Route::put('/{id}', [TemplateController::class, 'update']);
});

Route::middleware('auth:api')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('ocr')->group(function () {
        Route::post('/process', [OcrController::class, 'process']);
        Route::post('/save', [OcrController::class, 'save']);
        Route::get('/history', [OcrController::class, 'history']);
        Route::get('/process-count', [OcrController::class, 'processCount']);
        Route::post('/export', [OcrController::class, 'export']);
        Route::get('/{id}', [OcrController::class, 'show']);
    });

    // Route::prefix('image-categorizer')->group(function () {
    //     Route::get('/images/{jobCode}', [ImageCategorizerController::class, 'getImages']);
    //     Route::delete('/images', [ImageCategorizerController::class, 'deleteImages']);
    //     Route::post('/upload', [ImageCategorizerController::class, 'upload']);
    //     Route::post('/report', [ImageCategorizerController::class, 'generateReport']);
    // });

    Route::get('/images', [ImageController::class, 'index']);
    Route::post('/images/upload', [ImageController::class, 'upload']);
    Route::post('/images/delete', [ImageController::class, 'deleteMultiple']);
    Route::post('/images/generate-report', [ImageController::class, 'generateReport']);
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

Route::get('/clear-ocr-history', function () {
    try {

        OcrResult::truncate();
        OcrProcess::truncate();

        Storage::disk('public')->deleteDirectory('ocr-documents');

        return response()->json([
            'status' => 'success',
            'message' => 'OCR history and files cleared successfully.'
        ]);
    } catch (\Exception $e) {

        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});

Route::get('/clear-cache', function () {
    $exitCode = Artisan::call('cache:clear');
    $exitCode = Artisan::call('config:clear');
    $exitCode = Artisan::call('route:clear');
    $exitCode = Artisan::call('view:clear');
    $exitCode = Artisan::call('optimize');
    return '<h1>Cache Cleared</h1>';
});

Route::get('/run-document-type-seed', function () {
    try {
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\DocumentTypeSeeder',
            '--force' => true,
        ]);

        $output = Artisan::output();

        return response()->json([
            'status' => 'success',
            'message' => 'DocumentTypeSeeder executed successfully!',
            'output' => $output,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to run DocumentTypeSeeder.',
            'error' => $e->getMessage(),
        ], 500);
    }
});

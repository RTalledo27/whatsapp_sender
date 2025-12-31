<?php

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Manejar OPTIONS para CORS preflight
Route::options('{any}', function () {
    return response('', 200);
})->where('any', '.*');

Route::middleware('api')->group(function () {
    
    // Contacts
    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/', [ContactController::class, 'store']);
        Route::put('/{contact}', [ContactController::class, 'update']);
        Route::delete('/{contact}', [ContactController::class, 'destroy']);
        Route::post('/import-excel', [ContactController::class, 'importExcel']);
        Route::get('/excel-format', [ContactController::class, 'getExcelFormat']);
    });

    // Campaigns
    Route::prefix('campaigns')->group(function () {
        Route::get('/', [CampaignController::class, 'index']);
        Route::post('/', [CampaignController::class, 'store']);
        Route::get('/{campaign}', [CampaignController::class, 'show']); // Solo estado para polling
        Route::get('/{campaign}/details', [CampaignController::class, 'details']); // Detalles completos
        Route::delete('/{campaign}', [CampaignController::class, 'destroy']);
        Route::get('/{campaign}/statistics', [CampaignController::class, 'statistics']);
        Route::post('/{campaign}/retry-failed', [CampaignController::class, 'retryFailed']);
    });

    // Statistics
    Route::prefix('statistics')->group(function () {
        Route::get('/', [StatisticsController::class, 'index']);
        Route::get('/export', [StatisticsController::class, 'export']);
    });

    // Templates
    Route::get('/templates', [TemplateController::class, 'index']);
    Route::get('/account-info', [TemplateController::class, 'getAccountInfo']);

    // Test endpoints (remover en producción)
    Route::post('/test/template', [TestController::class, 'testTemplate']);
    Route::post('/test/text', [TestController::class, 'testTextMessage']);
    
    // Debug
    Route::get('/debug/latest-campaign', function() {
        $campaign = \App\Models\Campaign::latest()->first();
        
        if (!$campaign) {
            return response()->json(['error' => 'No campaigns found']);
        }
        
        return response()->json([
            'campaign' => $campaign->toArray(),
            'template_name_type' => gettype($campaign->template_name),
            'template_name_value' => $campaign->template_name,
            'template_name_empty' => empty($campaign->template_name),
            'template_parameters' => $campaign->template_parameters,
        ]);
    });

    // Health check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
        ]);
    });
});

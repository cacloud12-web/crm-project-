<?php

use App\Http\Controllers\Bulk\BulkCaMasterExportController;
use App\Http\Controllers\Bulk\BulkCaMasterImportController;
use App\Http\Controllers\Bulk\BulkStatusUpdateController;
use App\Http\Controllers\Bulk\ListingExportController;
use App\Http\Controllers\Bulk\SavedListingFilterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('ca-masters/bulk-import/sample.csv', [BulkCaMasterImportController::class, 'downloadSampleCsv']);
    Route::get('ca-masters/bulk-import/sample.xlsx', [BulkCaMasterImportController::class, 'downloadSampleXlsx']);
    Route::get('ca-masters/bulk-import/history', [BulkCaMasterImportController::class, 'history']);
    Route::get('ca-masters/bulk-import/history/{id}', [BulkCaMasterImportController::class, 'show']);
    Route::delete('ca-masters/bulk-import/history/{id}', [BulkCaMasterImportController::class, 'destroy']);
    Route::get('ca-masters/bulk-import/history/{id}/error-report.csv', [BulkCaMasterImportController::class, 'importErrorReport']);
    Route::get('ca-masters/bulk-import/history/{id}/reimport-template.csv', [BulkCaMasterImportController::class, 'importReimportTemplate']);
    Route::get('ca-masters/bulk-import/session/{sessionId}/error-report.csv', [BulkCaMasterImportController::class, 'sessionErrorReport']);
    Route::get('ca-masters/bulk-import/session/{sessionId}/reimport-template.csv', [BulkCaMasterImportController::class, 'sessionReimportTemplate']);
    Route::post('ca-masters/bulk-import/parse', [BulkCaMasterImportController::class, 'parse'])
        ->middleware('throttle:bulk-import');
    Route::post('ca-masters/bulk-import/validate', [BulkCaMasterImportController::class, 'validateMapping'])
        ->middleware('throttle:bulk-import');
    Route::post('ca-masters/bulk-import/row-actions', [BulkCaMasterImportController::class, 'applyRowActions'])
        ->middleware('throttle:bulk-import');
    Route::post('ca-masters/bulk-import', [BulkCaMasterImportController::class, 'store'])
        ->middleware('throttle:bulk-import');
    Route::get('ca-masters/bulk-import/history/{id}/status', [BulkCaMasterImportController::class, 'status']);
    Route::get('ca-masters/bulk-import/mapping-templates', [BulkCaMasterImportController::class, 'mappingTemplates']);
    Route::post('ca-masters/bulk-import/mapping-templates', [BulkCaMasterImportController::class, 'saveMappingTemplate']);

    Route::middleware('bulk.export')->group(function () {
        Route::get('ca-masters/bulk-export/columns', [BulkCaMasterExportController::class, 'columns']);
        Route::post('ca-masters/bulk-export/preview', [BulkCaMasterExportController::class, 'preview']);
        Route::post('ca-masters/bulk-export', [BulkCaMasterExportController::class, 'store']);
        Route::get('ca-masters/bulk-export/history', [BulkCaMasterExportController::class, 'history']);
        Route::get('ca-masters/bulk-export/history/{id}', [BulkCaMasterExportController::class, 'show']);
        Route::get('ca-masters/bulk-export/history/{id}/status', [BulkCaMasterExportController::class, 'status']);
        Route::get('ca-masters/bulk-export/history/{id}/download', [BulkCaMasterExportController::class, 'download']);
    });

    Route::get('ca-masters/bulk-operations/history', [BulkCaMasterExportController::class, 'operationsHistory']);

    Route::get('listings/{listingKey}/export', [ListingExportController::class, 'export']);
    Route::get('listing-filters/{listingKey}', [SavedListingFilterController::class, 'index']);
    Route::post('listing-filters/{listingKey}', [SavedListingFilterController::class, 'store']);
    Route::delete('listing-filters/{id}', [SavedListingFilterController::class, 'destroy']);

    Route::get('ca-masters/bulk-status-update/statuses', [BulkStatusUpdateController::class, 'statuses']);
    Route::post('ca-masters/bulk-status-update', [BulkStatusUpdateController::class, 'store']);
});

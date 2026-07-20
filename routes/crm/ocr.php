<?php

use App\Http\Controllers\Mapping\MasterImportBatchController;
use App\Http\Controllers\OcrDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('master-import-batches/{batch}', [MasterImportBatchController::class, 'show'])
        ->name('master-import-batches.show');
    Route::post('master-import-batches/{batch}/rollback', [MasterImportBatchController::class, 'rollback'])
        ->name('master-import-batches.rollback');
    Route::get('ocr-documents', [OcrDocumentController::class, 'index'])->name('ocr-documents.index');
    Route::get('ocr-documents/create', [OcrDocumentController::class, 'create'])->name('ocr-documents.create');
    Route::post('ocr-documents', [OcrDocumentController::class, 'store'])
        ->middleware('throttle:ocr-upload')
        ->name('ocr-documents.store');
    Route::get('ocr-documents/{ocrDocument}', [OcrDocumentController::class, 'show'])->name('ocr-documents.show');
    Route::get('ocr-documents/{ocrDocument}/firms', [OcrDocumentController::class, 'firms'])->name('ocr-documents.firms');
    Route::get('ocr-documents/{ocrDocument}/firms/export', [OcrDocumentController::class, 'exportFirmsCsv'])->name('ocr-documents.firms.export');
    Route::put('ocr-documents/{ocrDocument}', [OcrDocumentController::class, 'updateText'])->name('ocr-documents.update');
    Route::patch('ocr-documents/{ocrDocument}/text', [OcrDocumentController::class, 'updateText'])->name('ocr-documents.update-text');
    Route::post('ocr-documents/{ocrDocument}/retry', [OcrDocumentController::class, 'retry'])
        ->middleware('throttle:ocr-upload')
        ->name('ocr-documents.retry');
    Route::get('ocr-documents/{ocrDocument}/original', [OcrDocumentController::class, 'downloadOriginal'])->name('ocr-documents.original');
    Route::get('ocr-documents/{ocrDocument}/preview', [OcrDocumentController::class, 'preview'])->name('ocr-documents.preview');
    Route::get('ocr-documents/{ocrDocument}/download', [OcrDocumentController::class, 'download'])->name('ocr-documents.download');
    Route::post('ocr-documents/{ocrDocument}/reparse', [OcrDocumentController::class, 'reparse'])->name('ocr-documents.reparse');
    Route::post('ocr-documents/{ocrDocument}/approve-safe', [OcrDocumentController::class, 'approveAllSafe'])
        ->name('ocr-documents.approve-safe');
    Route::post('ocr-documents/{ocrDocument}/reject-selected', [OcrDocumentController::class, 'rejectSelectedFirms'])
        ->name('ocr-documents.reject-selected');
    Route::post('ocr-documents/{ocrDocument}/retry-mapping', [OcrDocumentController::class, 'retryMapping'])
        ->name('ocr-documents.retry-mapping');
    Route::patch('ocr-documents/{ocrDocument}/firms/{parsedFirm}/review', [OcrDocumentController::class, 'reviewFirm'])
        ->name('ocr-documents.firms.review');
    Route::patch('ocr-documents/{ocrDocument}/firms/{parsedFirm}/fields', [OcrDocumentController::class, 'correctFirmFields'])
        ->name('ocr-documents.firms.correct-fields');
    Route::delete('ocr-documents/{ocrDocument}', [OcrDocumentController::class, 'destroy'])->name('ocr-documents.destroy');
});

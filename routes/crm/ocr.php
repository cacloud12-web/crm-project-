<?php

use App\Http\Controllers\OcrDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('ocr-documents', [OcrDocumentController::class, 'index'])->name('ocr-documents.index');
    Route::get('ocr-documents/create', [OcrDocumentController::class, 'create'])->name('ocr-documents.create');
    Route::post('ocr-documents', [OcrDocumentController::class, 'store'])
        ->middleware('throttle:ocr-upload')
        ->name('ocr-documents.store');
    Route::get('ocr-documents/{ocrDocument}', [OcrDocumentController::class, 'show'])->name('ocr-documents.show');
    Route::put('ocr-documents/{ocrDocument}', [OcrDocumentController::class, 'updateText'])->name('ocr-documents.update');
    Route::patch('ocr-documents/{ocrDocument}/text', [OcrDocumentController::class, 'updateText'])->name('ocr-documents.update-text');
    Route::post('ocr-documents/{ocrDocument}/retry', [OcrDocumentController::class, 'retry'])
        ->middleware('throttle:ocr-upload')
        ->name('ocr-documents.retry');
    Route::get('ocr-documents/{ocrDocument}/original', [OcrDocumentController::class, 'downloadOriginal'])->name('ocr-documents.original');
    Route::get('ocr-documents/{ocrDocument}/preview', [OcrDocumentController::class, 'preview'])->name('ocr-documents.preview');
    Route::get('ocr-documents/{ocrDocument}/download', [OcrDocumentController::class, 'download'])->name('ocr-documents.download');
    Route::post('ocr-documents/{ocrDocument}/reparse', [OcrDocumentController::class, 'reparse'])->name('ocr-documents.reparse');
    Route::patch('ocr-documents/{ocrDocument}/firms/{parsedFirm}/review', [OcrDocumentController::class, 'reviewFirm'])
        ->name('ocr-documents.firms.review');
    Route::delete('ocr-documents/{ocrDocument}', [OcrDocumentController::class, 'destroy'])->name('ocr-documents.destroy');
});

<?php

use App\Http\Controllers\OcrDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('ocr-documents', [OcrDocumentController::class, 'index'])->name('ocr-documents.index');
    Route::post('ocr-documents', [OcrDocumentController::class, 'store'])->name('ocr-documents.store');
    Route::get('ocr-documents/{ocrDocument}', [OcrDocumentController::class, 'show'])->name('ocr-documents.show');
    Route::patch('ocr-documents/{ocrDocument}/text', [OcrDocumentController::class, 'updateText'])->name('ocr-documents.update-text');
    Route::post('ocr-documents/{ocrDocument}/retry', [OcrDocumentController::class, 'retry'])->name('ocr-documents.retry');
    Route::get('ocr-documents/{ocrDocument}/original', [OcrDocumentController::class, 'downloadOriginal'])->name('ocr-documents.original');
    Route::delete('ocr-documents/{ocrDocument}', [OcrDocumentController::class, 'destroy'])->name('ocr-documents.destroy');
});

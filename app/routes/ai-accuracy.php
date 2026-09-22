<?php

declare(strict_types=1);

use App\Http\Controllers\AiAccuracyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['project.owner', 'throttle:6,1'])->group(function (): void {
    Route::post('visibility/answers/{answer}/assess', [AiAccuracyController::class, 'assess'])->middleware('project.entitled')->name('ai-accuracy.assess');
    Route::post('visibility/findings/{finding}/review', [AiAccuracyController::class, 'review'])->name('ai-accuracy.review');
    Route::post('visibility/findings/{finding}/inspect-page', [AiAccuracyController::class, 'inspectPage'])->middleware('project.entitled')->name('ai-accuracy.inspect-page');
    Route::post('visibility/findings/{finding}/owned-page', [AiAccuracyController::class, 'ownedPage'])->name('ai-accuracy.owned-page');
    Route::post('visibility/findings/{finding}/handoff', [AiAccuracyController::class, 'handoff'])->name('ai-accuracy.handoff');
    Route::post('visibility/corrections/{action}/record-handoff', [AiAccuracyController::class, 'recordHandoff'])->name('ai-accuracy.record-handoff');
    Route::post('visibility/corrections/{action}/recheck', [AiAccuracyController::class, 'recheck'])->middleware('project.entitled')->name('ai-accuracy.recheck');
});

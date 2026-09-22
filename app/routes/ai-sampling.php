<?php

declare(strict_types=1);
use App\Http\Controllers\AiSamplingController;
use Illuminate\Support\Facades\Route;

Route::get('visibility/runs/{run}', [AiSamplingController::class, 'run'])->name('ai-sampling.run');
Route::get('visibility/answers/{answer}', [AiSamplingController::class, 'answer'])->name('ai-sampling.answer');
Route::middleware(['project.owner', 'throttle:3,1'])->group(function (): void {
    Route::post('visibility/cells/{cell}/recheck', [AiSamplingController::class, 'recheckCell'])->middleware('project.entitled')->name('ai-sampling.recheck-cell');
    Route::post('visibility/sets', [AiSamplingController::class, 'set'])->name('ai-sampling.set');
    Route::post('visibility/sample', [AiSamplingController::class, 'sample'])->middleware('project.entitled')->name('ai-sampling.sample');
    Route::post('visibility/answers/{answer}/recheck', [AiSamplingController::class, 'recheck'])->middleware('project.entitled')->name('ai-sampling.recheck');
});

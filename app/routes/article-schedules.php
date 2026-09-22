<?php

declare(strict_types=1);

use App\Http\Controllers\ArticleScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['project.owner', 'throttle:30,1'])->group(function (): void {
    Route::put('content/{item}/schedule', [ArticleScheduleController::class, 'save'])->name('content.schedule.save');
    Route::post('content/{item}/schedule/pause', [ArticleScheduleController::class, 'pause'])->name('content.schedule.pause');
    Route::post('content/{item}/schedule/resume', [ArticleScheduleController::class, 'resume'])->name('content.schedule.resume');
    Route::delete('content/{item}/schedule', [ArticleScheduleController::class, 'cancel'])->name('content.schedule.cancel');
});

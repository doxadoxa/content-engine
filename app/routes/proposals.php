<?php

declare(strict_types=1);

use App\Http\Controllers\PageOutcomeController;
use App\Http\Controllers\PageProposalController;
use Illuminate\Support\Facades\Route;

Route::get('proposals/{proposal}', [PageProposalController::class, 'show'])->name('proposals.show');
Route::get('publications/{publication}/handoff', [PageProposalController::class, 'handoff'])->middleware('project.owner')->name('publications.handoff');
Route::middleware(['project.owner', 'throttle:20,1'])->group(function (): void {
    Route::post('publications/{publication}/outcome-review', [PageOutcomeController::class, 'store'])->name('publications.outcome-review');
    Route::post('opportunities/{opportunity}/proposals', [PageProposalController::class, 'store'])->name('proposals.store');
    Route::post('proposals/{proposal}/revisions', [PageProposalController::class, 'revise'])->name('proposals.revise');
    Route::post('proposals/{proposal}/regenerate', [PageProposalController::class, 'regenerate'])->name('proposals.regenerate');
    Route::post('proposals/{proposal}/accept', [PageProposalController::class, 'accept'])->name('proposals.accept');
    Route::post('proposals/{proposal}/dismiss', [PageProposalController::class, 'dismiss'])->name('proposals.dismiss');
    Route::post('proposals/{proposal}/publish', [PageProposalController::class, 'publish'])->name('proposals.publish');
    Route::post('publications/{publication}/applied', [PageProposalController::class, 'applied'])->name('publications.applied');
    Route::post('publications/{publication}/verify', [PageProposalController::class, 'verify'])->name('publications.verify');
});

<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminOverviewController;
use App\Http\Controllers\Admin\AdminProjectController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Running the service, as opposed to running a project
|--------------------------------------------------------------------------
|
| Everything under here reads across tenants, which is the one thing the rest
| of this application is built to make impossible. {@see \App\Support\Tenancy\ProjectScope}
| fails closed, so a query somebody forgot to widen shows an empty table rather
| than another tenant's rows — the failure mode is a visible bug rather than a
| leak, and that is what makes this safe to write at all.
|
| Its own file rather than a group inside `web.php` for the same reason it has
| its own middleware: this is a different application wearing the same frame,
| and mixing its routes in with the operator's would make "is this behind the
| admin check" a question somebody has to answer by reading indentation.
|
| Deliberately **not** behind `project.entitled` or `EnsureCurrentProject`'s
| tenant. An administrator looking at why a project stopped must not be stopped
| by the same thing.
*/

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', AdminOverviewController::class)->name('overview');

    Route::get('users', [AdminUserController::class, 'index'])->name('users');

    Route::get('projects', [AdminProjectController::class, 'index'])->name('projects');
    Route::get('projects/{project}', [AdminProjectController::class, 'show'])->name('projects.show');

    // The three things somebody actually does to a customer's service. Each
    // writes an `admin_actions` row: six months later, "why is this account on
    // Enterprise" has to have an answer that is not a guess.
    Route::post('projects/{project}/plan', [AdminProjectController::class, 'assign'])->name('projects.plan');
    Route::post('projects/{project}/trial', [AdminProjectController::class, 'extendTrial'])->name('projects.trial');
    Route::post('projects/{project}/status', [AdminProjectController::class, 'status'])->name('projects.status');

    Route::get('subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions');
    Route::post('subscriptions/{subscription}/resync', [AdminSubscriptionController::class, 'resync'])
        ->name('subscriptions.resync');
});

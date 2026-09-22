<?php

declare(strict_types=1);

use App\Http\Controllers\NativePageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['project.owner', 'throttle:20,1'])->group(function (): void {
    Route::get('integrations/wordpress/receiver.zip', [NativePageController::class, 'wordpressPlugin'])->name('wordpress.receiver-download');
    Route::post('pages/{page}/cms-binding', [NativePageController::class, 'bind'])->name('pages.cms-bind');
    Route::post('pages/{page}/editable-snapshots', [NativePageController::class, 'capture'])->name('pages.cms-capture');
    Route::post('proposals/{proposal}/publish-native', [NativePageController::class, 'publish'])->name('proposals.publish-native');
    Route::post('publications/{publication}/recovery', [NativePageController::class, 'recover'])->name('publications.recover');
    Route::post('page-operations/{operation}/reconcile', [NativePageController::class, 'reconcile'])->name('page-operations.reconcile');
    Route::post('page-operations/{operation}/retry', [NativePageController::class, 'retry'])->name('page-operations.retry');
});

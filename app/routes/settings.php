<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

// The forms on these pages submit to Fortify's own routes
// (user/profile-information, user/password), so these are the screens and one
// exception — see the POST below.
Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');

    // The exception: asking for a first password by email, for an account that
    // signed up through Google and has none. Not Fortify's `/forgot-password`,
    // which is behind `guest` and so unreachable by the only people who need
    // this. Throttled because it sends mail on request; the password broker
    // throttles per address underneath as well.
    Route::post('settings/password/link', [PasswordController::class, 'sendLink'])
        ->middleware('throttle:5,1')->name('password.link');
});

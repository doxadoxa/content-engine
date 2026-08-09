<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

// The forms on these pages submit to Fortify's own routes
// (user/profile-information, user/password), so there is nothing here but the
// screens themselves.
Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
});

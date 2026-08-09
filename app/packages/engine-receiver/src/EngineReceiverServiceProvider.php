<?php

declare(strict_types=1);

namespace Persistance\EngineReceiver;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Persistance\EngineReceiver\Http\WebhookController;
use Persistance\EngineReceiver\Middleware\VerifyEngineSignature;

/**
 * Everything a receiving site needs, wired on install (§6.3: ten minutes).
 *
 * The route, the signature check and the migration all arrive together and
 * switched on. Configuration is one value — the secret.
 */
class EngineReceiverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/engine-receiver.php', 'engine-receiver');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'engine-receiver');

        $this->publishes([
            __DIR__.'/../config/engine-receiver.php' => config_path('engine-receiver.php'),
        ], 'engine-receiver-config');

        $this->registerRoute();
    }

    private function registerRoute(): void
    {
        /** @var list<string> $middleware */
        $middleware = config('engine-receiver.middleware', ['api']);

        Route::middleware([...$middleware, VerifyEngineSignature::class])
            ->post((string) config('engine-receiver.path', 'engine/webhook'), WebhookController::class)
            ->name('engine-receiver.webhook');
    }
}

<?php

namespace App\Providers;

use App\Services\Momo\DemoMomoCollections;
use App\Services\Momo\MomoCollections;
use App\Services\Momo\MomoCollectionsClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MomoCollections::class, function (): MomoCollections {
            return config('services.momo.demo_mode')
                ? new DemoMomoCollections
                : new MomoCollectionsClient;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Status columns are deliberately left out of the models' Fillable
         * attributes so a request can never drive a state machine. Without this,
         * a mass-assigned status is dropped in silence and the entry simply
         * fails to change state.
         */
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerMomoHttpClient();
    }

    /**
     * One place for the MoMo transport concerns: base URL, the subscription key
     * every endpoint needs, and a short timeout. A customer is standing at a
     * counter waiting, so a slow MoMo must fail fast rather than hang the till.
     *
     * Retries are deliberately narrow. Request to Pay carries a reference id we
     * persisted, so a retried POST is safe, but only connection-level failures
     * are worth repeating.
     */
    protected function registerMomoHttpClient(): void
    {
        Http::macro('momo', function () {
            return Http::baseUrl((string) config('services.momo.base_url'))
                ->withHeaders([
                    'Ocp-Apim-Subscription-Key' => (string) config('services.momo.subscription_key'),
                    'X-Target-Environment' => (string) config('services.momo.target_environment'),
                ])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->retry(2, 200, fn ($exception) => $exception instanceof ConnectionException, throw: false);
        });
    }
}

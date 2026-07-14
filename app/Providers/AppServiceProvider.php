<?php

namespace App\Providers;

use App\Services\FirebaseTokenVerifier;
use App\Services\Payments\PaymentService;
use App\Services\Payments\SonicPesaGateway;
use App\Services\TokenService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FirebaseTokenVerifier::class, function () {
            return new FirebaseTokenVerifier(config('services.firebase.project_id'));
        });

        $this->app->singleton(TokenService::class, function () {
            return new TokenService(
                accessTtl: (int) config('services.auth_tokens.access_ttl'),
                refreshTtl: (int) config('services.auth_tokens.refresh_ttl'),
            );
        });

        $this->app->singleton(SonicPesaGateway::class, function () {
            return new SonicPesaGateway(
                baseUrl: (string) config('services.sonicpesa.base_url'),
                apiKey: config('services.sonicpesa.api_key'),
                apiSecret: config('services.sonicpesa.api_secret'),
                webhookSecret: config('services.sonicpesa.webhook_secret'),
            );
        });

        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService([
                'sonicpesa' => $app->make(SonicPesaGateway::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The React client consumes bare JSON arrays for collections.
        JsonResource::withoutWrapping();

        // Signed stream URLs must be valid behind the API host.
        if ($url = config('app.url')) {
            URL::forceRootUrl($url);
        }

        RateLimiter::for('auth', function ($request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by($request->ip());
        });

        RateLimiter::for('payments', function ($request) {
            $key = optional($request->user())->id ?: $request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by((string) $key);
        });
    }
}

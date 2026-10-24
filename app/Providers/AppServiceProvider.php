<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiClient;
use App\Media\Operations\OperationRegistry;
use App\Media\Processing\FfmpegMediaProcessor;
use App\Media\Processing\MediaProcessor;
use App\Media\Processing\SimulatedMediaProcessor;
use App\Media\Profiles\ProfileRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationRegistry::class, fn ($app) => new OperationRegistry(
            $app,
            (array) config('mediaforge.operations'),
        ));

        $this->app->singleton(ProfileRegistry::class, fn ($app) => new ProfileRegistry(
            $app->make(OperationRegistry::class),
            (array) config('mediaforge.profiles'),
        ));

        $this->app->bind(MediaProcessor::class, fn ($app) => match (config('mediaforge.processor')) {
            'ffmpeg' => $app->make(FfmpegMediaProcessor::class),
            'simulated' => $app->make(SimulatedMediaProcessor::class),
            default => throw new InvalidArgumentException('Motor de procesamiento no soportado: '.config('mediaforge.processor')),
        });
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Límite por cliente autenticado (se ejecuta después de auth.api)
        RateLimiter::for('api', function (Request $request): Limit {
            $client = AuthenticateApiClient::client($request);

            return Limit::perMinute($client->rateLimitPerMinute())->by('client:'.$client->id);
        });

        RateLimiter::for('health', fn (Request $request): Limit => Limit::perMinute(60)->by('ip:'.$request->ip()));
    }
}

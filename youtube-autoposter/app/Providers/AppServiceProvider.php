<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Generation\ClaudeScriptWriter;
use App\Services\Generation\ScriptWriter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, fn () => new Client(apiKey: config('services.anthropic.api_key') ?: null));
        $this->app->bind(ScriptWriter::class, ClaudeScriptWriter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

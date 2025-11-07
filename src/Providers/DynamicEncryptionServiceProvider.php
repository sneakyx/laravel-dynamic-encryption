<?php

namespace Sneakyx\LaravelDynamicEncryption\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Sneakyx\LaravelDynamicEncryption\Services\DynamicEncrypter;
use Sneakyx\LaravelDynamicEncryption\Services\StorageManager;
use Sneakyx\LaravelDynamicEncryption\Console\RotateEncryptionKey;

class DynamicEncryptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/dynamic-encryption.php', 'dynamic-encryption');

        $this->app->singleton(StorageManager::class, function ($app) {
            return new StorageManager();
        });

        // Bind our encrypter as the core encrypter
        $this->app->singleton('encrypter', function ($app) {
            return new DynamicEncrypter($app->make(StorageManager::class));
        });

        $this->app->alias('encrypter', EncrypterContract::class);
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/dynamic-encryption.php' => config_path('dynamic-encryption.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RotateEncryptionKey::class,
            ]);
        }
    }
}

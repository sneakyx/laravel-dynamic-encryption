<?php

namespace Sneakyx\LaravelDynamicEncryption\Providers;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Console\RotateEncryptionKey;
use Sneakyx\LaravelDynamicEncryption\Services\DynamicEncrypter;
use Sneakyx\LaravelDynamicEncryption\Services\StorageManager;

class DynamicEncryptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/dynamic-encryption.php', 'dynamic-encryption');

        $this->app->singleton(StorageManager::class, function ($app) {
            return new StorageManager;
        });

        // Bind our encrypter as the core encrypter; keep app runnable even if bundle is missing.
        $this->app->singleton('encrypter', function ($app) {
            $flagKey = Config::get('dynamic-encryption.missing_bundle_flag_key', 'dynamic-encryption.missing_bundle');
            try {
                return new DynamicEncrypter($app->make(StorageManager::class));
            } catch (\Throwable $e) {
                // Mark missing bundle so trait/model logic can decide what to do for DB fields.
                Config::set($flagKey, true);

                $policy = strtolower((string) Config::get('dynamic-encryption.on_missing_bundle', 'block'));
                if ($policy === 'fail') {
                    throw $e; // explicit failure requested
                }

                // Provide a working encrypter for framework internals (cookies/session/CSRF) using APP_KEY.
                $appKey = (string) Config::get('app.key');
                $cipher = (string) Config::get('app.cipher', 'aes-256-cbc');
                $keyBytes = str_starts_with($appKey, 'base64:')
                    ? base64_decode(substr($appKey, 7), true)
                    : $appKey;

                return new \Illuminate\Encryption\Encrypter($keyBytes, $cipher);
            }
        });

        $this->app->alias('encrypter', EncrypterContract::class);
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../../config/dynamic-encryption.php' => config_path('dynamic-encryption.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RotateEncryptionKey::class,
            ]);
        }
    }
}

<?php

namespace Sneakyx\LaravelDynamicEncryption\Providers;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Console\AddEncryptionPrefix;
use Sneakyx\LaravelDynamicEncryption\Console\DecryptFields;
use Sneakyx\LaravelDynamicEncryption\Console\EncryptFields;
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

        // Bind our encrypter as the core encrypter; keep app runnable even if password bundle is missing.
        $this->app->singleton('encrypter', function ($app) {
            try {
                return new DynamicEncrypter($app->make(StorageManager::class));
            } catch (\Throwable $e) {

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

                return new Encrypter($keyBytes, $cipher);
            }
        });

        $this->app->alias('encrypter', EncrypterContract::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'dynamic-encryption');

        // Publish config
        $this->publishes([
            __DIR__.'/../../config/dynamic-encryption.php' => config_path('dynamic-encryption.php'),
        ], 'config');

        // In testing, ensure a dynamic encryption password exists early so migrations can run
        if ($this->app->environment('testing')) {
            try {
                $store = (string) Config::get('dynamic-encryption.storage');
                $arrayKey = (string) Config::get('dynamic-encryption.array');
                $fieldKey = (string) Config::get('dynamic-encryption.key');

                if ($arrayKey && $fieldKey) {
                    $bundle = Cache::store($store)->get($arrayKey);
                    if (! is_array($bundle)) {
                        $bundle = [];
                    }
                    if (! array_key_exists($fieldKey, $bundle) || empty($bundle[$fieldKey])) {
                        $bundle[$fieldKey] = 'base64:'.base64_encode(random_bytes(32));
                        Cache::store($store)->forever($arrayKey, $bundle);
                    }
                }
            } catch (\Throwable $e) {
                // Silently ignore to avoid interfering with app boot in tests; TestCase may populate later.
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                RotateEncryptionKey::class,
                AddEncryptionPrefix::class,
                DecryptFields::class,
                EncryptFields::class,
            ]);
        }
    }
}

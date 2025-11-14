<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Unit;

use Orchestra\Testbench\TestCase as Orchestra; // If unavailable, tests may be adapted in app context.
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Services\StorageManager;

class StorageManagerTest extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [DynamicEncryptionServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('dynamic-encryption.storage', 'memcache');
        $app['config']->set('dynamic-encryption.key', 'dynamic_encryption_key');
    }

    public function test_normalize_base64_key_to_bytes(): void
    {
        $sm = $this->app->make(StorageManager::class);
        $raw = random_bytes(32);
        $bytes = $sm->normalizeKeyToBytes('base64:'.base64_encode($raw));
        $this->assertSame($raw, $bytes);
    }

    public function test_get_key_with_old_and_new_parameters(): void
    {
        $sm = $this->app->make(StorageManager::class);

        // Mock keys in cache first so we have something to retrieve
        $this->app['cache']->store('memcached')->put('dynamic_encryption_key_old', 'base64:'.base64_encode(random_bytes(32)));
        $this->app['cache']->store('memcached')->put('dynamic_encryption_key', 'base64:'.base64_encode(random_bytes(32)));

        // Test getting old key
        $oldKey = $sm->getKeyString(true);
        $this->assertStringStartsWith('base64:', $oldKey);

        // Test getting new key
        $newKey = $sm->getKeyString(false);
        $this->assertStringStartsWith('base64:', $newKey);

        // Keys should be different
        $this->assertNotSame($oldKey, $newKey);
    }

    public function test_invalid_storage_throws_exception(): void
    {
        $this->app['config']->set('dynamic-encryption.storage', 'database');
        $sm = $this->app->make(StorageManager::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported dynamic encryption storage: database');
        $sm->getKeyString();
    }

    public function test_missing_key_throws_exception(): void
    {
        $this->app['config']->set('dynamic-encryption.storage', 'redis');
        $this->app['config']->set('dynamic-encryption.key', 'missing_key_name');
        $sm = $this->app->make(StorageManager::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Dynamic encryption key 'missing_key_name' not found in redis. Set it first!");
        $sm->getKeyString();
    }
}

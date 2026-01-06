<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Unit;

use Orchestra\Testbench\TestCase as Orchestra;
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Services\StorageManager;

class EncryptionTest extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [DynamicEncryptionServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('dynamic-encryption.storage', 'array');
        $app['config']->set('dynamic-encryption.array', 'dynamic_encryption_key');
        $app['config']->set('dynamic-encryption.key', 'password');
    }

    public function test_encrypt_decrypt_with_dynamic_key(): void
    {
        $sm = $this->app->make(StorageManager::class);
        $raw = random_bytes(32);
        $keyString = 'base64:'.base64_encode($raw);

        // Mock bundle in cache
        $bundle = ['password' => $keyString];
        $this->app['cache']->store('array')->put('dynamic_encryption_key', $bundle);

        $enc = app('encrypter');
        $plain = 'super-secret';
        $cipher = $enc->encryptString($plain);
        $this->assertNotSame($plain, $cipher);
        $this->assertSame($plain, $enc->decryptString($cipher));
    }
}

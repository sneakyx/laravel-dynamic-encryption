<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as Orchestra;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Values\LockedEncryptedValue;

class CastLockedTest extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [DynamicEncryptionServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        // app key for framework encrypter (used as fallback only for internals)
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Configure dynamic encryption to a memcache store, but do NOT provide a bundle → missing
        $app['config']->set('dynamic-encryption.storage', 'memcache');
        $app['config']->set('dynamic-encryption.array', 'dynamic_encryption_key');
        $app['config']->set('dynamic-encryption.key', 'password');
        $app['config']->set('dynamic-encryption.kdf_salt', 'test-salt');
    }

    public function test_get_returns_locked_value_when_bundle_missing(): void
    {
        $model = new class extends Model
        {
            protected $guarded = [];

            protected $table = 'dummy'; // not used

            protected $casts = [
                'secret' => EncryptedNullableCast::class,
            ];
        };

        // Ensure the encryption key is missing
        $app = $this->app;
        $app['cache']->driver(config('dynamic-encryption.storage'))->forget('dynamic_encryption_key');

        // Put some prefixed ciphertext string into attributes.
        // Decryption must fail because key is missing, and cast must yield LockedEncryptedValue.
        $model->setRawAttributes(['secret' => 'dynenc:v1:eyJjaXBoZXIiOiJhZXMtMjU2LWNibyIsIml2IjoiZm9vIiwidGFnIjoiYmFyIn0=']);

        $value = $model->secret;
        $this->assertInstanceOf(LockedEncryptedValue::class, $value);
        $this->assertTrue($value->isLocked());
    }
}

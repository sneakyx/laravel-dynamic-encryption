<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as Orchestra;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptionTestLoader;
use Sneakyx\LaravelDynamicEncryption\Values\LockedEncryptedValue;

class EncryptedNullableCastTest extends Orchestra
{
    use DynamicEncryptionTestLoader;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeDynamicEncryptionKeyForTests();
    }

    public function test_get_returns_null_when_value_is_empty(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableCast;

        $this->assertNull($cast->get($model, 'secret', null, []));
        $this->assertNull($cast->get($model, 'secret', '', []));
    }

    public function test_get_returns_raw_value_when_no_prefix_present(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableCast;
        $plain = 'some-plaintext';

        $this->assertSame($plain, $cast->get($model, 'secret', $plain, []));
    }

    public function test_get_decrypts_value_with_prefix(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableCast;
        $plain = 'secret-data';
        $encrypted = app('encrypter')->encryptString($plain);
        $prefixed = 'dynenc:v1:'.$encrypted;

        $this->assertSame($plain, $cast->get($model, 'secret', $prefixed, []));
    }

    public function test_set_encrypts_and_adds_prefix(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableCast;
        $plain = 'new-secret';

        $result = $cast->set($model, 'secret', $plain, []);

        $this->assertStringStartsWith('dynenc:v1:', $result);
        $ciphertext = substr($result, strlen('dynenc:v1:'));
        $this->assertSame($plain, app('encrypter')->decryptString($ciphertext));
    }

    public function test_set_returns_null_for_empty_values(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableCast;

        $this->assertNull($cast->set($model, 'secret', null, []));
        $this->assertNull($cast->set($model, 'secret', '', []));
    }

    public function test_set_keeps_original_value_for_locked_encrypted_value(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableCast;
        $original = 'dynenc:v1:already-stored';
        $locked = new LockedEncryptedValue('secret', 1);

        $result = $cast->set($model, 'secret', $locked, ['secret' => $original]);

        $this->assertSame($original, $result);
    }

    public function test_get_handles_decryption_error_policies(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableCast;
        $invalidPrefixed = 'dynenc:v1:invalid-payload';

        // Default: placeholder
        $result = $cast->get($model, 'secret', $invalidPrefixed, []);
        $this->assertInstanceOf(LockedEncryptedValue::class, $result);

        // Policy: raw
        config(['dynamic-encryption.on_decryption_error' => 'raw']);
        $this->assertSame('invalid-payload', $cast->get($model, 'secret', $invalidPrefixed, []));

        // Policy: null
        config(['dynamic-encryption.on_decryption_error' => 'null']);
        $this->assertNull($cast->get($model, 'secret', $invalidPrefixed, []));

        // Policy: fail
        config(['dynamic-encryption.on_decryption_error' => 'fail']);
        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        $cast->get($model, 'secret', $invalidPrefixed, []);
    }
}

class TestCastModel extends Model {}

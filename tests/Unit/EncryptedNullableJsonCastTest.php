<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Unit;

use Orchestra\Testbench\TestCase as Orchestra;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableJsonCast;
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptionTestLoader;
use Sneakyx\LaravelDynamicEncryption\Values\LockedEncryptedValue;

class EncryptedNullableJsonCastTest extends Orchestra
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
        $cast = new EncryptedNullableJsonCast;

        $this->assertNull($cast->get($model, 'data', null, []));
        $this->assertNull($cast->get($model, 'data', '', []));
    }

    public function test_get_returns_array_from_plaintext_json(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableJsonCast;
        $data = ['foo' => 'bar'];
        $json = json_encode($data);

        $this->assertSame($data, $cast->get($model, 'data', $json, []));
    }

    public function test_get_decrypts_and_decodes_json_with_prefix(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableJsonCast;
        $data = ['foo' => 'bar'];
        $encrypted = app('encrypter')->encryptString(json_encode($data));
        $prefixed = 'dynenc:v1:'.$encrypted;

        $this->assertSame($data, $cast->get($model, 'data', $prefixed, []));
    }

    public function test_set_encrypts_json_and_adds_prefix(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableJsonCast;
        $data = ['foo' => 'bar'];

        $result = $cast->set($model, 'data', $data, []);

        $this->assertStringStartsWith('dynenc:v1:', $result);
        $ciphertext = substr($result, strlen('dynenc:v1:'));
        $decrypted = app('encrypter')->decryptString($ciphertext);
        $this->assertSame($data, json_decode($decrypted, true));
    }

    public function test_set_encrypts_empty_array_as_json(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableJsonCast;
        $data = [];

        $result = $cast->set($model, 'data', $data, []);

        $this->assertStringStartsWith('dynenc:v1:', $result);
        $ciphertext = substr($result, strlen('dynenc:v1:'));
        $decrypted = app('encrypter')->decryptString($ciphertext);
        $this->assertSame('[]', $decrypted);
    }

    public function test_set_returns_null_for_null_or_empty_string(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableJsonCast;

        $this->assertNull($cast->set($model, 'data', null, []));
        $this->assertNull($cast->set($model, 'data', '', []));
    }

    public function test_set_keeps_original_value_for_locked_encrypted_value(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableJsonCast;
        $original = 'dynenc:v1:already-stored';
        $locked = new LockedEncryptedValue('data', 1);

        $result = $cast->set($model, 'data', $locked, ['data' => $original]);

        $this->assertSame($original, $result);
    }

    public function test_get_handles_decryption_error_policies_for_json(): void
    {
        $model = new TestCastModel;
        $cast = new EncryptedNullableJsonCast;
        $invalidPrefixed = 'dynenc:v1:invalid-payload';

        // Default: placeholder
        $result = $cast->get($model, 'data', $invalidPrefixed, []);
        $this->assertInstanceOf(LockedEncryptedValue::class, $result);

        // Policy: null
        config(['dynamic-encryption.on_decryption_error' => 'null']);
        $this->assertNull($cast->get($model, 'data', $invalidPrefixed, []));
    }
}

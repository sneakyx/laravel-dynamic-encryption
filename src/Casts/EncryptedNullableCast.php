<?php

namespace Sneakyx\LaravelDynamicEncryption\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Sneakyx\LaravelDynamicEncryption\Traits\CheckCredentialsExist;
use Sneakyx\LaravelDynamicEncryption\Values\LockedEncryptedValue;

/**
 * Cast for encrypted nullable strings.
 *
 * get():
 *  - null/empty -> null
 *  - decrypts ciphertext using current encrypter
 *  - on any decryption error → returns LockedEncryptedValue (no exception)
 *
 * set():
 *  - LockedEncryptedValue -> keep original stored value (no change)
 *  - null -> null
 *  - string/plain -> encrypts using the current encrypter
 */
final class EncryptedNullableCast implements CastsAttributes
{
    use CheckCredentialsExist;

    const STANDARD_PREFIX = 'dynenc:v1:';

    public function get(Model $model, string $key, $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = (string) $value;
        $prefix = config('dynamic-encryption.prefix', $this::STANDARD_PREFIX);

        if (! str_starts_with($raw, $prefix)) {
            return $raw;
        }

        $ciphertext = substr($raw, strlen($prefix));

        try {
            return app('encrypter')->decryptString($ciphertext);
        } catch (\Throwable $e) {
            $whatToDo = config('dynamic-encryption.on_decryption_error', 'placeholder');
            if ($whatToDo === 'placeholder') {
                // Return the locked placeholder instead of throwing.
                return new LockedEncryptedValue(attribute: $key, ownerId: method_exists($model, 'getKey') ? $model->getKey() : null);
            } elseif ($whatToDo === 'raw') {
                return $ciphertext;
            } elseif ($whatToDo === 'fail') {
                throw $e;
            } else {
                return null;
            }
        }
    }

    public function set(Model $model, string $key, $value, array $attributes): mixed
    {
        if ($value instanceof LockedEncryptedValue) {
            // Keep existing raw value from attributes when locked (no change).
            return $attributes[$key] ?? null;
        }

        if (empty($value)) {
            return null;
        }

        if ($this->checkCredentialsExist()) {
            $prefix = config('dynamic-encryption.prefix', $this::STANDARD_PREFIX);
            $encrypted = app('encrypter')->encryptString((string) $value);

            // prefix has to be added
            return $prefix.$encrypted;

        }
        $policy = strtolower((string) Config::get('dynamic-encryption.on_missing_bundle', 'block'));
        if ($policy === 'plaintext') {
            // Store as plaintext explicitly.
            return (string) $value;
        }
        // Default and 'block' → stop saving; 'fail' behaves the same here.
        throw ValidationException::withMessages([
            $key => __('Saving not possible: Dynamic encryption key is not available. Please contact an administrator.'),
        ]);

    }
}

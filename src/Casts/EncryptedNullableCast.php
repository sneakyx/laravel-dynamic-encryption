<?php

namespace Sneakyx\LaravelDynamicEncryption\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
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
 *  - string/plain -> encrypts using current encrypter
 */
final class EncryptedNullableCast implements CastsAttributes
{
    public function get(Model $model, string $key, $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return app('encrypter')->decryptString((string) $value);
        } catch (\Throwable $e) {
            // Return locked placeholder instead of throwing.
            return new LockedEncryptedValue(attribute: $key, ownerId: method_exists($model, 'getKey') ? $model->getKey() : null);
        }
    }

    public function set(Model $model, string $key, $value, array $attributes): mixed
    {
        if ($value instanceof LockedEncryptedValue) {
            // Keep existing raw value from attributes when locked (no change).
            return $attributes[$key] ?? null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        // Respect missing-bundle policy to avoid encrypting with a wrong key (e.g., APP_KEY fallback).
        $flagKey = Config::get('dynamic-encryption.missing_bundle_flag_key', 'dynamic-encryption.missing_bundle');
        $missing = (bool) Config::get($flagKey, false);
        if ($missing) {
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

        // Normal path: encrypt with current dynamic encrypter.
        return app('encrypter')->encryptString((string) $value);
    }
}

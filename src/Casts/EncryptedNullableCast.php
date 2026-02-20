<?php

namespace Sneakyx\LaravelDynamicEncryption\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Sneakyx\LaravelDynamicEncryption\Traits\CheckCredentialsExist;
use Sneakyx\LaravelDynamicEncryption\Values\LockedEncryptedValue;

/**
 * Cast for encrypted nullable strings.
 *
 * get():
 *  - null/empty → null
 *  - decrypts ciphertext using current encrypter
 *  - on decryption error → returns LockedEncryptedValue (no exception)
 *
 * set():
 *  - LockedEncryptedValue → keep original stored value (no change)
 *  - null → null
 *  - plaintext → encrypts using the current encrypter
 *  - legacy encrypted (eyJpdiI6) → adds prefix and stores
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
        $prefix = config('dynamic-encryption.prefix', self::STANDARD_PREFIX);

        if (! str_starts_with($raw, $prefix)) {
            // Check for legacy encrypted value (without prefix)
            if (str_starts_with($raw, 'eyJpdiI6')) {
                try {
                    return app('encrypter')->decryptString($raw);
                } catch (\Throwable $e) {
                    return $this->handleDecryptionError($key, $model, $raw, $e);
                }
            }

            // Otherwise: plaintext
            return $raw;
        }

        // Value has prefix: decrypt the ciphertext
        $ciphertext = substr($raw, strlen($prefix));

        try {
            return app('encrypter')->decryptString($ciphertext);
        } catch (\Throwable $e) {
            return $this->handleDecryptionError($key, $model, $raw, $e);
        }
    }

    public function set(Model $model, string $key, $value, array $attributes): mixed
    {
        if ($value instanceof LockedEncryptedValue) {
            return $attributes[$key] ?? null;
        }

        if (empty($value)) {
            return null;
        }

        // Warn if the model uses the deprecated $encryptable property
        if (property_exists($model, 'encryptable') && in_array($key, $model->encryptable)) {
            Log::warning('Model '.get_class($model)." uses deprecated \$encryptable property for field '{$key}'. Migrate to casts.");
        }

        if (! $this->checkCredentialsExist()) {
            $policy = strtolower((string) Config::get('dynamic-encryption.on_missing_bundle', 'block'));
            if ($policy === 'plaintext') {
                return (string) $value;
            }
            throw ValidationException::withMessages([
                $key => __('dynamic-encryption::messages.saving_failed_key_missing'),
            ]);
        }

        $prefix = config('dynamic-encryption.prefix', self::STANDARD_PREFIX);

        // If the value is already encrypted (legacy format: eyJpdiI6)
        if (str_starts_with($value, 'eyJpdiI6')) {
            // Validate the encrypted string structure
            $decoded = json_decode(base64_decode(substr($value, 0, 50)), true);
            if (! is_array($decoded) || ! isset($decoded['iv'], $decoded['value'], $decoded['mac'])) {
                throw ValidationException::withMessages([
                    $key => __('Invalid encrypted value. Expected valid ciphertext or plaintext.'),
                ]);
            }
            // If no prefix: add it (migration)
            if (! str_starts_with($value, $prefix)) {
                return $prefix.$value;
            }

            return $value; // Already has prefix → leave unchanged
        }

        // Otherwise: encrypt plaintext
        $encrypted = app('encrypter')->encryptString((string) $value);

        return $prefix.$encrypted;
    }

    protected function handleDecryptionError(string $key, Model $model, string $raw, \Throwable $e): mixed
    {
        $whatToDo = config('dynamic-encryption.on_decryption_error', 'placeholder');
        if ($whatToDo === 'placeholder') {
            return new LockedEncryptedValue($key, $model->getKey());
        } elseif ($whatToDo === 'raw') {
            return $raw;
        } elseif ($whatToDo === 'fail') {
            throw $e;
        }

        return null;
    }
}

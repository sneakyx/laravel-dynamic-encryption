<?php

namespace Sneakyx\LaravelDynamicEncryption\Casts\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Sneakyx\LaravelDynamicEncryption\Values\LockedEncryptedValue;

/**
 * Trait providing common logic for dynamic encryption casts.
 */
trait EncryptionCastSupport
{
    /**
     * Get the configured encryption prefix.
     */
    protected function getPrefix(): string
    {
        return config('dynamic-encryption.prefix', 'dynenc:v1:');
    }

    /**
     * Check if the value has the encryption prefix.
     */
    protected function hasPrefix(string $value): bool
    {
        return str_starts_with($value, $this->getPrefix());
    }

    /**
     * Strip the prefix from a value.
     */
    protected function stripPrefix(string $value): string
    {
        $prefix = $this->getPrefix();
        if (str_starts_with($value, $prefix)) {
            return substr($value, strlen($prefix));
        }

        return $value;
    }

    /**
     * Check if a value is a legacy encrypted ciphertext (eyJpdiI6).
     */
    protected function isLegacyCiphertext(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, 'eyJpdiI6');
    }

    /**
     * Validate the structure of a legacy ciphertext.
     *
     * @throws ValidationException
     */
    protected function validateLegacyCiphertextStructure(string $key, string $value): void
    {
        $decodedJson = base64_decode($value, true);
        $decoded = $decodedJson !== false ? json_decode($decodedJson, true) : null;

        if (! is_array($decoded) || ! isset($decoded['iv'], $decoded['value'], $decoded['mac'])) {
            throw ValidationException::withMessages([
                $key => __('Invalid encrypted value. Expected valid ciphertext or plaintext.'),
            ]);
        }
    }

    /**
     * Encrypt a plaintext string and add the prefix.
     */
    protected function encryptPlaintextString(string $value): string
    {
        $encrypted = app('encrypter')->encryptString($value);

        return $this->getPrefix().$encrypted;
    }

    /**
     * Decrypt a ciphertext string (with or without prefix).
     *
     * @throws \Throwable
     */
    protected function decryptCiphertextString(string $key, Model $model, string $raw): ?string
    {
        $prefix = $this->getPrefix();
        $ciphertext = str_starts_with($raw, $prefix) ? substr($raw, strlen($prefix)) : $raw;

        try {
            return app('encrypter')->decryptString($ciphertext);
        } catch (\Throwable $e) {
            return $this->handleDecryptionError($key, $model, $raw, $e);
        }
    }

    /**
     * Handle decryption errors based on the configured policy.
     *
     * @throws \Throwable
     */
    protected function handleDecryptionError(string $key, Model $model, string $raw, \Throwable $e): mixed
    {
        $policy = config('dynamic-encryption.on_decryption_error', 'placeholder');

        if ($policy === 'placeholder') {
            return new LockedEncryptedValue($key, $model->getKey());
        }

        if ($policy === 'raw') {
            return $raw;
        }

        if ($policy === 'fail') {
            throw $e;
        }

        return null;
    }

    /**
     * Check for the "locked value" mechanic.
     */
    protected function handleLockedValue(string $key, mixed $value, array $attributes): ?string
    {
        if ($value instanceof LockedEncryptedValue) {
            return $attributes[$key] ?? null;
        }

        return null;
    }

    /**
     * Handle missing credentials during the set() operation.
     *
     * @throws ValidationException
     */
    protected function handleMissingCredentials(string $key, mixed $value): ?string
    {
        if ($this->checkCredentialsExist()) {
            return null;
        }

        $policy = strtolower((string) Config::get('dynamic-encryption.on_missing_bundle', 'block'));

        if ($policy === 'plaintext') {
            return is_array($value) ? json_encode($value) : (string) $value;
        }

        throw ValidationException::withMessages([
            $key => __('dynamic-encryption::messages.saving_failed_key_missing'),
        ]);
    }

    /**
     * Log a warning if the model uses the deprecated $encryptable property.
     */
    protected function warnIfDeprecatedEncryptable(Model $model, string $key): void
    {
        if (property_exists($model, 'encryptable') && is_array($model->encryptable) && in_array($key, $model->encryptable)) {
            Log::warning('Model '.get_class($model)." uses deprecated \$encryptable property for field '{$key}'. Migrate to casts.");
        }
    }
}

<?php

namespace Sneakyx\LaravelDynamicEncryption\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Sneakyx\LaravelDynamicEncryption\Casts\Support\EncryptionCastSupport;
use Sneakyx\LaravelDynamicEncryption\Traits\CheckCredentialsExist;
use Sneakyx\LaravelDynamicEncryption\Values\LockedEncryptedValue;

/**
 * Cast for encrypted nullable JSON/Arrays.
 *
 * get():
 *  - null/empty → null
 *  - decrypts ciphertext using current encrypter, then json_decode to array
 *  - on decryption error → returns LockedEncryptedValue (no exception)
 *  - if plaintext JSON without prefix → returns array (if valid JSON) or raw string/null
 *
 * set():
 *  - LockedEncryptedValue → keep original stored value (no change)
 *  - null → null
 *  - array/string → json_encode, then encrypt using current encrypter
 *  - legacy encrypted (eyJpdiI6) → adds prefix and stores
 */
final class EncryptedNullableJsonCast implements CastsAttributes
{
    use CheckCredentialsExist;
    use EncryptionCastSupport;

    public function get(Model $model, string $key, $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = (string) $value;

        if (! $this->hasPrefix($raw)) {
            // Check for legacy encrypted value (without prefix)
            if ($this->isLegacyCiphertext($raw)) {
                $decrypted = $this->decryptCiphertextString($key, $model, $raw);

                return $this->decodeJson($decrypted);
            }

            // Otherwise: check if it's plaintext JSON
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            // backward-compatibility: if it's not valid JSON, return null
            return null;
        }

        // Value has prefix: decrypt the ciphertext
        $decrypted = $this->decryptCiphertextString($key, $model, $raw);

        return $this->decodeJson($decrypted);
    }

    public function set(Model $model, string $key, $value, array $attributes): mixed
    {
        if ($locked = $this->handleLockedValue($key, $value, $attributes)) {
            return $locked;
        }

        // null or empty string should be null.
        // empty array [] should be encrypted as "[]".
        if ($value === null || $value === '') {
            return null;
        }

        $this->warnIfDeprecatedEncryptable($model, $key);

        if ($this->handleMissingCredentials($key, $value)) {
            // If missing credentials and policy is plaintext, return the json string
            return is_array($value) ? json_encode($value) : (string) $value;
        }

        // If the value is already encrypted (legacy format: eyJpdiI6)
        if ($this->isLegacyCiphertext($value)) {
            $this->validateLegacyCiphertextStructure($key, $value);

            if (! $this->hasPrefix($value)) {
                return $this->getPrefix().$value;
            }

            return $value;
        }

        // Otherwise: JSON encode and encrypt
        $json = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value;

        return $this->encryptPlaintextString($json);
    }

    /**
     * Decode JSON string to array, handling LockedEncryptedValue.
     */
    protected function decodeJson(mixed $value): mixed
    {
        if ($value instanceof LockedEncryptedValue || $value === null) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
    }
}

<?php

namespace Sneakyx\LaravelDynamicEncryption\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Sneakyx\LaravelDynamicEncryption\Casts\Support\EncryptionCastSupport;
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
                return $this->decryptCiphertextString($key, $model, $raw);
            }

            // Otherwise: plaintext
            return $raw;
        }

        // Value has prefix: decrypt the ciphertext
        return $this->decryptCiphertextString($key, $model, $raw);
    }

    public function set(Model $model, string $key, $value, array $attributes): mixed
    {
        if ($locked = $this->handleLockedValue($key, $value, $attributes)) {
            return $locked;
        }

        if (empty($value)) {
            return null;
        }

        $this->warnIfDeprecatedEncryptable($model, $key);

        if ($plaintext = $this->handleMissingCredentials($key, $value)) {
            return $plaintext;
        }

        // If the value is already encrypted (legacy format: eyJpdiI6)
        if ($this->isLegacyCiphertext($value)) {
            // Validate the encrypted string structure
            $this->validateLegacyCiphertextStructure($key, $value);

            // If no prefix: add it (migration)
            if (! $this->hasPrefix($value)) {
                return $this->getPrefix().$value;
            }

            return $value; // Already has prefix → leave unchanged
        }

        // Otherwise: encrypt plaintext
        return $this->encryptPlaintextString((string) $value);
    }
}

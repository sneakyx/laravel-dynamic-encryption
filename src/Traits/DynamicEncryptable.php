<?php

namespace Sneakyx\LaravelDynamicEncryption\Traits;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

trait DynamicEncryptable
{
    use CheckCredentialsExist;

    public static function bootDynamicEncryptable(): void
    {
        static::retrieved(function ($model) {
            $encryptable = $model->encryptable ?? [];
            foreach ($encryptable as $field) {
                $value = $model->getAttribute($field);
                if (! is_null($value)) {
                    $model->setAttribute($field, static::decryptSilently($value));
                }
            }
        });

        static::saving(function ($model) {
            $encryptable = $model->encryptable ?? [];
            $policy = strtolower((string) Config::get('dynamic-encryption.on_missing_bundle', 'block'));

            foreach ($encryptable as $field) {
                // Only act on changed fields; unchanged fields pass through untouched
                if (method_exists($model, 'isDirty') && ! $model->isDirty($field)) {
                    continue;
                }

                $value = $model->getAttribute($field);
                if (is_null($value)) {
                    continue;
                }

                if (! static::checkCredentialsExistStatic()) {
                    if ($policy === 'block') {
                        throw ValidationException::withMessages([
                            $field => __('Saving not possible now: Dynamic encryption key is not available. Please contact an administrator.'),
                        ]);
                    }
                    if ($policy === 'plaintext') {
                        // Explicitly skip encryption; store as-is
                        continue;
                    }
                    // 'fail' is handled at provider level; if we're here, treat like 'block' for safety
                    throw ValidationException::withMessages([
                        $field => __('Saving not possible: Dynamic encryption misconfigured.'),
                    ]);
                }

                // Avoid double-encrypt: try decrypt; on failure, assume plain and encrypt
                $alreadyDecrypted = static::attemptDecrypt($value);
                $toStore = $alreadyDecrypted === null ? $value : $alreadyDecrypted; // if null, decrypt failed

                $prefix = config('dynamic-encryption.prefix', 'dynenc:v1:');
                $encrypted = app('encrypter')->encryptString((string) $toStore);
                $model->setAttribute($field, $prefix.$encrypted);
            }
        });

        static::saved(function ($model) {
            // After save, set decrypted values back for in-memory usage
            $encryptable = $model->encryptable ?? [];
            foreach ($encryptable as $field) {
                $value = $model->getAttribute($field);
                if (! is_null($value)) {
                    $model->setAttribute($field, static::decryptSilently($value));
                }
            }
        });
    }

    protected static function decryptSilently(string $value): ?string
    {
        $prefix = config('dynamic-encryption.prefix', 'dynenc:v1:');
        if (str_starts_with($value, $prefix)) {
            $value = substr($value, strlen($prefix));
        }

        try {
            return app('encrypter')->decryptString($value);
        } catch (\Throwable $e) {
            // Do not log key material or ciphertext; simply return original if not decryptable.
            return $value;
        }
    }

    protected static function attemptDecrypt(string $value): ?string
    {
        $prefix = config('dynamic-encryption.prefix', 'dynenc:v1:');
        if (str_starts_with($value, $prefix)) {
            $value = substr($value, strlen($prefix));
        }

        try {
            return app('encrypter')->decryptString($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getEncryptableAttributes(): array
    {
        return $this->encryptable ?? [];
    }
}

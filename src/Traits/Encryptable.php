<?php

namespace Sneakyx\LaravelDynamicEncryption\Traits;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\App;

trait Encryptable
{
    public static function bootEncryptable(): void
    {
        static::retrieved(function ($model) {
            $encryptable = $model->encryptable ?? [];
            foreach ($encryptable as $field) {
                $value = $model->getAttribute($field);
                if (!is_null($value)) {
                    $model->setAttribute($field, static::decryptSilently($value));
                }
            }
        });

        static::saving(function ($model) {
            $encryptable = $model->encryptable ?? [];
            foreach ($encryptable as $field) {
                $value = $model->getAttribute($field);
                if (!is_null($value)) {
                    // Avoid double-encrypt: try decrypt; on failure, assume plain and encrypt
                    $alreadyDecrypted = static::attemptDecrypt($value);
                    $toStore = $alreadyDecrypted === null ? $value : $alreadyDecrypted; // if null, decrypt failed
                    $encrypted = app('encrypter')->encryptString($toStore);
                    $model->setAttribute($field, $encrypted);
                }
            }
        });

        static::saved(function ($model) {
            // After save, set decrypted values back for in-memory usage
            $encryptable = $model->encryptable ?? [];
            foreach ($encryptable as $field) {
                $value = $model->getAttribute($field);
                if (!is_null($value)) {
                    $model->setAttribute($field, static::decryptSilently($value));
                }
            }
        });
    }

    protected static function decryptSilently(string $value): ?string
    {
        try {
            return app('encrypter')->decryptString($value);
        } catch (\Throwable $e) {
            // Do not log key material or ciphertext; simply return original if not decryptable.
            return $value;
        }
    }

    protected static function attemptDecrypt(string $value): ?string
    {
        try {
            return app('encrypter')->decryptString($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

<?php

namespace Sneakyx\LaravelDynamicEncryption\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Encryption\Encrypter as BaseEncrypter;
use Illuminate\Support\Facades\Config;

class DynamicEncrypter extends BaseEncrypter
{
    protected StorageManager $storageManager;

    public function __construct(StorageManager $storageManager)
    {
        $this->storageManager = $storageManager;
        $cipher = Config::get('app.cipher', 'aes-256-cbc');

        try {
            $key = $storageManager->getKeyBytes();
        } catch (\Throwable $e) {
            // Use a dummy key if loading fails during construction,
            // but we'll try to reload during encrypt/decrypt if possible,
            // or let it fail then.
            $key = str_repeat('0', $cipher === 'aes-128-cbc' ? 16 : 32);
        }

        parent::__construct($key, $cipher);
    }

    public function encrypt($value, $serialize = true)
    {
        try {
            $this->key = $this->storageManager->getKeyBytes();
        } catch (\Throwable $e) {
            throw new EncryptException('Dynamic encryption key not available.', 0, $e);
        }

        return parent::encrypt($value, $serialize);
    }

    public function decrypt($payload, $unserialize = true)
    {
        try {
            $this->key = $this->storageManager->getKeyBytes();
        } catch (\Throwable $e) {
            throw new DecryptException('Dynamic encryption key not available.', 0, $e);
        }

        return parent::decrypt($payload, $unserialize);
    }
}

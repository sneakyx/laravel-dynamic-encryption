<?php

namespace Sneakyx\LaravelDynamicEncryption\Services;

use Illuminate\Encryption\Encrypter as BaseEncrypter;
use Illuminate\Support\Facades\Config;

class DynamicEncrypter extends BaseEncrypter
{
    protected StorageManager $storageManager;

    public function __construct(StorageManager $storageManager)
    {
        $this->storageManager = $storageManager;
        $cipher = Config::get('app.cipher', 'aes-256-cbc');
        $key = $storageManager->getKeyBytes();

        parent::__construct($key, $cipher);
    }
}

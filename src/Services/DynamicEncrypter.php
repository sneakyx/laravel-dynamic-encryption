<?php

namespace Sneakyx\LaravelDynamicEncryption\Services;

use Illuminate\Encryption\Encrypter as BaseEncrypter;
use Illuminate\Support\Facades\Config;

class DynamicEncrypter extends BaseEncrypter
{
    public function __construct(StorageManager $storageManager)
    {
        $key = $storageManager->getKeyBytes();
        $cipher = Config::get('app.cipher', 'AES-256-CBC');
        parent::__construct($key, $cipher);
    }
}

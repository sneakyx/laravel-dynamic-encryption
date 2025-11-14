<?php

namespace Sneakyx\LaravelDynamicEncryption\Services;

use Illuminate\Encryption\Encrypter as BaseEncrypter;
use Illuminate\Support\Facades\Config;

class DynamicEncrypter extends BaseEncrypter
{
    public function __construct(StorageManager $storageManager)
    {
        $key = $storageManager->getKeyBytes();
        $cipher = Config::get('app.cipher', 'aes-256-cbc');
        parent::__construct($key, $cipher);
    }
}

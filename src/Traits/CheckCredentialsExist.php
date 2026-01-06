<?php

namespace Sneakyx\LaravelDynamicEncryption\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

trait CheckCredentialsExist
{
    public function checkCredentialsExist(): bool
    {
        return static::checkCredentialsExistStatic();
    }

    public static function checkCredentialsExistStatic(): bool
    {
        $storage = Config::get('dynamic-encryption.storage');
        $arrayKey = Config::get('dynamic-encryption.array');
        $fieldKey = Config::get('dynamic-encryption.key');

        if (! Cache::store($storage)->has($arrayKey)) {
            return false;
        }
        $dynamicEncryptionArray = Cache::store($storage)->get($arrayKey);
        if (! is_array($dynamicEncryptionArray) || ! array_key_exists($fieldKey, $dynamicEncryptionArray)) {
            return false;
        }

        return true;
    }
}

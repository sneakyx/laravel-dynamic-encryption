<?php

namespace Sneakyx\LaravelDynamicEncryption\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

trait CheckCredentialsExist
{
    public function checkCredentialsExist(): bool
    {

        if (! Cache::store(Config::get('dynamic-encryption.storage'))->has(Config::get('dynamic-encryption.array'))) {
            return false;
        }
        $dynamicEncryptionArray = Cache::store(Config::get('dynamic-encryption.storage'))->get(Config::get('dynamic-encryption.array'));
        if (! array_key_exists(Config::get('dynamic-encryption.key'), $dynamicEncryptionArray)) {
            return false;
        }

        return true;

    }
}

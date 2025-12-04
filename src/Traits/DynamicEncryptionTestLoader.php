<?php

namespace Sneakyx\LaravelDynamicEncryption\Traits;

use Illuminate\Support\Facades\Cache;

trait DynamicEncryptionTestLoader
{
    protected static ?string $dynamicEncryptionTestKey = null;

    /**
     * Initialisiert einmalig einen zufälligen Key und schreibt ihn
     * bei jedem Testlauf in den konfigurierten Cache-Store/Key.
     */
    protected function initializeDynamicEncryptionKeyForTests(): void
    {
        // only once per test run
        if (self::$dynamicEncryptionTestKey === null) {
            self::$dynamicEncryptionTestKey = 'base64:'.base64_encode(random_bytes(32));
        }

        $store = config('dynamic-encryption.storage');
        $arrayKey = config('dynamic-encryption.array');
        $fieldKey = config('dynamic-encryption.key');

        if (! $arrayKey || ! $fieldKey) {
            return;
        }

        $bundle = Cache::get($arrayKey);
        if (! is_array($bundle)) {
            $bundle = [];
        }

        $bundle[$fieldKey] = self::$dynamicEncryptionTestKey;

        // Write bundle permanently to the configured cache store
        Cache::store($store)->forever($arrayKey, $bundle);
    }
}

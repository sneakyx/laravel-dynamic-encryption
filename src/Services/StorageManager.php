<?php

namespace Sneakyx\LaravelDynamicEncryption\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Manages loading and storing the dynamic encryption key from configured storage.
 */
class StorageManager
{
    public function __construct()
    {
        // Intentionally empty; resolves facades at runtime.
    }

    /**
     * Returns the key string as stored (e.g. "base64:...")
     */
    public function getKeyString(): string
    {
        $storage = Config::get('dynamic-encryption.storage');
        $keyName = Config::get('dynamic-encryption.key');

        if (!in_array($storage, ['memcache', 'memcached', 'redis'], true)) {
            throw new RuntimeException('Unsupported dynamic encryption storage: ' . (string) $storage);
        }

        $value = Cache::get($keyName);

        if (!is_string($value) || $value === '') {
            throw new RuntimeException("Dynamic encryption key '{$keyName}' not found in {$storage}. Set it first!");
        }

        return $value;
    }

    /**
     * Returns a 32-byte key suitable for AES-256.
     */
    public function getKeyBytes(): string
    {
        $keyString = $this->getKeyString();
        return $this->normalizeKeyToBytes($keyString);
    }

    /**
     * Store the key string into the configured storage. Never logs key.
     */
    public function storeKey(string $keyString): void
    {
        $storage = Config::get('dynamic-encryption.storage');
        $keyName = Config::get('dynamic-encryption.key');

        if (!in_array($storage, ['memcache', 'memcached', 'redis'], true)) {
            throw new RuntimeException('Unsupported dynamic encryption storage: ' . (string) $storage);
        }

        Cache::forever($keyName, $keyString);
    }

    /**
     * Convert a stored key string into 32 raw bytes for Encrypter.
     */
    public function normalizeKeyToBytes(string $keyString): string
    {
        if (Str::startsWith($keyString, 'base64:')) {
            $decoded = base64_decode(substr($keyString, 7), true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new RuntimeException('Invalid base64 dynamic key: wrong length.');
            }
            return $decoded;
        }

        // If plain string of exactly 32 bytes, use as-is.
        if (strlen($keyString) === 32) {
            return $keyString;
        }

        throw new RuntimeException('Dynamic key must be 32 bytes or base64: encoded 32 bytes.');
    }

    /**
     * Build an Encrypter instance from a stored dynamic key string (base64: or raw 32 bytes)
     */
    public function makeEncrypterFromKeyString(string $keyString, ?string $cipher = null): \Illuminate\Contracts\Encryption\Encrypter
    {
        $key = $this->normalizeKeyToBytes($keyString);
        $cipher = $cipher ?: config('app.cipher', 'AES-256-CBC');
        return new \Illuminate\Encryption\Encrypter($key, $cipher);
    }
}

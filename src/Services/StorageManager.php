<?php

namespace Sneakyx\LaravelDynamicEncryption\Services;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\App;
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
    public function getKeyString($getOldPassword = false): string
    {
        $storage = Config::get('dynamic-encryption.storage');
        $keyArray = Config::get('dynamic-encryption.array');
        if ($getOldPassword) {
            $keyName = Config::get('dynamic-encryption.old_key');
        } else {
            $keyName = Config::get('dynamic-encryption.key');
        }
        if (! in_array($storage, ['memcache', 'memcached', 'redis', 'array'], true) && ! App::environment('local')) {
            throw new RuntimeException('Unsupported dynamic encryption storage: '.$storage);
        }

        try {
            $contentOfKeyArray = Cache::store($storage)->get($keyArray);
        } catch (\Throwable $e) {
            if (App::environment('testing')) {
                $contentOfKeyArray = Cache::get($keyArray);
            } else {
                throw $e;
            }
        }

        if (empty($contentOfKeyArray) || ! is_array($contentOfKeyArray)) {
            $contentOfKeyArray = Cache::get($keyArray);
        }

        if (empty($contentOfKeyArray) || ! is_array($contentOfKeyArray)) {
            throw new RuntimeException("Dynamic encryption key '{$keyName}' not found in {$storage}. Set it first!");
        }

        $key = $contentOfKeyArray[$keyName] ?? null;
        if (empty($key)) {
            throw new RuntimeException("Dynamic encryption key '{$keyName}' not found in {$storage}. Set it first!");
        }

        if (! is_string($key)) {
            throw new RuntimeException("Dynamic encryption key '{$keyName}' not found in {$storage}. Set it first!");
        }

        return $key;
    }

    /**
     * Returns a key with the correct length for the configured cipher.
     */
    public function getKeyBytes(): string
    {
        $keyString = $this->getKeyString();

        return $this->normalizeKeyToBytes($keyString);
    }

    /**
     * Store the key string into the configured storage. Never logs key.
     * NOTE: This stores only a single field; callers should maintain the bundle structure.
     */
    public function storeKey(string $keyString): void
    {
        $storage = Config::get('dynamic-encryption.storage');
        $keyArray = Config::get('dynamic-encryption.array');
        $keyField = Config::get('dynamic-encryption.key');

        $allowedStores = ['memcache', 'memcached', 'redis', 'array'];

        if (! in_array($storage, $allowedStores, true)) {
            throw new RuntimeException('Unsupported dynamic encryption storage: '.(string) $storage);
        }

        $bundle = Cache::store($storage)->get($keyArray);
        if (! is_array($bundle)) {
            $bundle = [];
        }
        $bundle[$keyField] = $keyString;

        Cache::store($storage)->forever($keyArray, $bundle);
    }

    /**
     * Convert a stored key string into raw bytes expected by Illuminate Encrypter.
     * Supports:
     *  - base64:<...> where decoded length must equal required cipher key size
     *  - password (plain text) -> derived with configured KDF and salt to the required size
     */
    public function normalizeKeyToBytes(string $keyString): string
    {
        if (empty($keyString)) {
            throw new RuntimeException('Dynamic encryption key is empty');
        }

        $required = $this->requiredKeySize();

        if (Str::startsWith($keyString, 'base64:')) {
            $decoded = base64_decode(substr($keyString, 7), true);
            if ($decoded === false || strlen($decoded) !== $required) {
                throw new RuntimeException('Invalid base64 dynamic key: wrong length.');
            }

            return $decoded;
        }

        // Treat as password – derive key deterministically using configured KDF
        $salt = $this->parseSalt((string) Config::get('dynamic-encryption.kdf_salt', ''));
        if ($salt === null || $salt === '') {
            throw new RuntimeException('KDF salt not configured (dynamic-encryption.kdf_salt).');
        }

        $kdf = strtolower((string) Config::get('dynamic-encryption.kdf', 'pbkdf2'));
        switch ($kdf) {
            case 'argon2id':
                return $this->deriveArgon2id($keyString, $salt, $required);
            case 'pbkdf2':
            default:
                $algo = (string) Config::get('dynamic-encryption.kdf_algo', 'sha256');
                $iters = (int) Config::get('dynamic-encryption.kdf_iters', 210000);
                if ($iters <= 0) {
                    throw new RuntimeException('PBKDF2 iterations must be > 0.');
                }

                return hash_pbkdf2($algo, $keyString, $salt, $iters, $required, true);
        }
    }

    /**
     * Build an Encrypter instance from a stored dynamic key string.
     */
    public function makeEncrypterFromKeyString(string $keyString, ?string $cipher = null): Encrypter
    {
        $key = $this->normalizeKeyToBytes($keyString);
        $cipher = $cipher ?: (string) config('app.cipher', 'aes-256-cbc');

        return new \Illuminate\Encryption\Encrypter($key, $cipher);
    }

    /**
     * Determine the required key size (in bytes) from config('app.cipher').
     */
    protected function requiredKeySize(): int
    {
        $cipher = strtolower((string) Config::get('app.cipher', 'aes-256-cbc'));

        return match ($cipher) {
            'aes-128-cbc', 'aes-128-gcm' => 16,
            'aes-256-cbc', 'aes-256-gcm' => 32,
            default => 32,
        };
    }

    /**
     * Parse salt from config: supports raw string or base64:...
     * Returns raw bytes string.
     */
    protected function parseSalt(string $salt): ?string
    {
        $salt = trim($salt);
        if ($salt === '') {
            return null;
        }
        if (Str::startsWith($salt, 'base64:')) {
            $decoded = base64_decode(substr($salt, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('Invalid base64 salt in dynamic-encryption.kdf_salt');
            }

            return $decoded;
        }

        return $salt; // raw bytes
    }

    /**
     * Derive key using Argon2id via libsodium.
     */
    protected function deriveArgon2id(string $password, string $salt, int $length): string
    {
        if (! function_exists('sodium_crypto_pwhash')) {
            throw new RuntimeException('Argon2id requested but libsodium is not available.');
        }

        $ops = Config::get('dynamic-encryption.argon_ops');
        $mem = Config::get('dynamic-encryption.argon_mem');

        if (! is_int($ops)) {
            $ops = defined('SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE')
                ? constant('SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE')
                : 3;
        }
        if (! is_int($mem)) {
            $mem = defined('SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE')
                ? constant('SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE')
                : 1 << 25; // ~32 MB
        }

        return sodium_crypto_pwhash(
            $length,
            $password,
            $salt,
            $ops,
            $mem,
            defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13') ? constant('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13') : 2
        );
    }
}

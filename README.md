# sneakyx/laravel-dynamic-encryption

A **minimalist Laravel package** that replaces the default `Encrypter` with a **dynamic key system**.
The encryption key is **not loaded from `APP_KEY`**, but from a **volatile storage** (Memcached/Redis).
This ensures **no persistent key storage** (e.g., no database), making it **DSGVO/GDPR-compliant** for ephemeral key management.
Read more in: [Why does this exist?](docs/why-does-this-exist.md)
---

## Installation

### Local Development (Monorepo)
1. The package is already located at:
   `packages/only-local/laravel-dynamic-encryption`
2. Update autoloading:
   ```bash
   composer dump-autoload
Standalone Project (GitHub)
composer require sneakyx/laravel-dynamic-encryption
The package uses Laravel’s auto-discovery and binds the service provider automatically.

### Configuration
.env
```
DYNAMIC_ENCRYPTION_STORAGE=memcache  # Supported: memcache|redis
DYNAMIC_ENCRYPTION_KEY=dynamic_encryption_key  # Key name in cache storage
config/dynamic-encryption.php
```

## Key Notes:

No fallback to APP_KEY: If the dynamic key is missing, an exception is thrown.
No database storage: Keys are only stored in volatile caches (Memcached/Redis).


## Usage
#### Automatic Encryption
After installation, the package replaces Laravel’s default Encrypter.
Use the Crypt facade as usual:
```
Crypt::encryptString('secret');
Crypt::decryptString($payload);
```
#### Encryptable Trait
Add the trait to your models and define an $encryptable array with fields to encrypt:
```
use Sneakyx\LaravelDynamicEncryption\Traits\Encryptable;

class Secret extends Model
{
use Encryptable;

    protected array \$encryptable = ['token', 'note'];
}
```
On save: Fields are automatically encrypted.
On load/post-save: Values are decrypted in-memory (plaintext in model).

### Where can I find the key?
The key is stored in volatile storage (Memcached/Redis).
Read me here: [Where is the key?](docs/where-is-the-key.md)

### Key Rotation
Rotate encryption keys without persistent storage:
```
php artisan encrypt:rotate --model=App\\Models\\Secret
```
#### Options:
`--model=ModelClassRotate` only the specified model (can be used multiple times).
`--all` Rotate all models using the Encryptable trait (use with caution!).
`--dry-run` Dry run (no changes to data).

#### Key Notes:

Fails without `--model` or `--all`: The command explicitly requires model specification.
Warning: `--all` can be slow for large datasets (use only for small projects).

Process:

A new 32-byte key is generated and stored as a base64: string in the configured cache.
Specified models are re-encrypted in chunks (batch size: chunk config).
Secure logging: No key values are logged.


## Security

No key logging: Keys are never written to logs or dumps.
AES-256-CBC: Uses Laravel’s built-in encryption for secure ciphertext.
Volatile storage: Keys are only stored in Memcached/Redis (no persistence).


## Compatibility
Tested with Laravel 10, 11, and 12.

## License
MIT

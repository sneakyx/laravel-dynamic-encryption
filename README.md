# sneakyx/laravel-dynamic-encryption

A minimalist Laravel package that replaces the default `Encrypter` with a dynamic key system.
The application encryption key is not taken from `APP_KEY`, but resolved at runtime from a volatile store (cache) and/or derived from a password using a KDF. This enables ephemeral key management and straightforward rotation.
Read more in: [Why does this exist?](docs/why-does-this-exist.md)
---

## Installation

### Local Development (Monorepo)
1. The package is already located at:
   `packages/only-local/laravel-dynamic-encryption`
2. Update autoloading:
   ```bash
   composer dump-autoload
   ```

### Standalone Project (Packagist/GitHub)
```bash
composer require sneakyx/laravel-dynamic-encryption
```
The package uses Laravel’s auto-discovery and binds the service provider automatically.

## Configuration

Add environment variables and publish/adjust the config if needed.

Relevant `.env` variables (examples):
```
# Which cache store (by name) is expected to carry the key bundle (validated);
# the bundle is read via your DEFAULT cache store. Ensure they match in practice.
DYNAMIC_ENCRYPTION_CACHE_STORE=memcached

# Cache key that holds the bundle (an array) with fields like "password" and "old_password"
DYNAMIC_ENCRYPTION_CACHE_KEY=dynamic_encryption_key
DYNAMIC_ENCRYPTION_ARRAY_KEY=password
DYNAMIC_ENCRYPTION_ARRAY_OLD_KEY=old_password

# KDF settings (password -> key). Salt/params come from .env
DYNAMIC_ENCRYPTION_KDF=pbkdf2    # or: argon2id
DYNAMIC_ENCRYPTION_KDF_ALGO=sha256
DYNAMIC_ENCRYPTION_KDF_ITERS=210000

# Salt can be raw text or base64:...
DYNAMIC_ENCRYPTION_SALT=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=

# Behavior when the dynamic key bundle is missing
#   block     -> prevent saving changed encryptable fields (default)
#   plaintext -> skip encryption for changed fields (store as plaintext)
#   fail      -> throw and fail boot
DYNAMIC_ENCRYPTION_ON_MISSING_BUNDLE=block
```

See `config/dynamic-encryption.php` for all options.

Key size is taken from `config('app.cipher')`:
- aes-256-cbc / aes-256-gcm → 32 bytes
- aes-128-cbc / aes-128-gcm → 16 bytes

## Key material: two supported formats
- Base64-derived key: `base64:<...>` that decodes to the required key length.
- Plain password: any string; the package derives the correct-length key using the configured KDF and salt from `.env`.

## Behavior when the key bundle is missing
- The package keeps the framework operational (sessions/cookies) by providing a core encrypter from `APP_KEY`.
- A runtime flag is set (`dynamic-encryption.missing_bundle` by default). Application code should NOT try decrypting data with a fallback key. Instead, use the cast below to surface a "locked" value in the UI until the user unlocks their data (Variante A).
- Policies when saving while the bundle is missing:
  - `block` (default): Saving models with changed encryptable fields throws a validation error. Unchanged fields are untouched. Reading remains tolerant.
  - `plaintext`: Encryption is skipped for changed encryptable fields (they are stored as plaintext). Use only in exceptional cases.
  - `fail`: Provider throws during boot; the application will error.

## Usage

The package replaces Laravel’s default Encrypter. Use the Crypt facade as usual:
```php
use Illuminate\Support\Facades\Crypt;

$payload = Crypt::encryptString('secret');
$plain   = Crypt::decryptString($payload);
```

### Usage

Use the `EncryptedNullableCast` in your model's `$casts` array:

```php
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;

class User extends Model
{
    protected function casts(): array
    {
        return [
            'secret_field' => EncryptedNullableCast::class,
        ];
    }
}
```

## Migration from v0.3.x

Replace the `DynamicEncryptable` trait and `$encryptable` property with casts:

```php
// OLD (deprecated)
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptable;
class User extends Model {
    use DynamicEncryptable;
    protected array $encryptable = ['secret_field'];
}

// NEW
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;
class User extends Model {
    protected function casts(): array {
        return ['secret_field' => EncryptedNullableCast::class];
    }
}
```

Please migrate to the Cast-based approach above. The trait will log deprecation warnings.

### Cast-based "Locked" Flow
Instead of using a cryptographic fallback (e.g., `APP_KEY`), 
the following cast returns a `LockedEncryptedValue` placeholder object when the key is missing—no exception is thrown. 
This allows the UI to display an "unlock" dialog without breaking the rendering flow.
If you have also (legacy) unencrypted fields for some entities, you also can use the `.env` value:

```dotenv
DYNAMIC_ENCRYPTION_ON_DECRYPTION_ERROR_RETURN="raw"
```
(There are also the values `null` and `fail` for the policy.)

1) Use Cast:
```php
use Illuminate\Database\Eloquent\Model;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;

class UserSecret extends Model
{
    protected $casts = [
        'iban' => EncryptedNullableCast::class,
    ];
}
```

2) Check in the UI:
```php
use Sneakyx\LaravelDynamicEncryption\Values\LockedEncryptedValue;

if ($userSecret->iban instanceof LockedEncryptedValue) {
    // Show banner/modal: "Please enter password to view"
} else {
    echo $userSecret->iban; // Decrypted plaintext
}
```

3) When saving:

- If the bundle key is available, encryption proceeds normally.
- If the key is missing, the policy dynamic-encryption.on_missing_bundle applies:
    - block (default): Throws a ValidationException.
    - plaintext: Stores the value in plaintext (use only temporarily or in exceptional cases!).


Note: `LockedEncryptedValue` is string-/JSON-serializable (returns an empty string or null) and contains no plaintext data.

### Where is the key/password stored?
The package expects a key bundle (array) in your cache under `DYNAMIC_ENCRYPTION_CACHE_KEY`.
Read more: [Where is the key?](docs/where-is-the-key.md)

## Data Prefixing (Versioned Encryption)

Starting with version 0.2.0, encrypted values are stored with a versioned prefix (default: `dynenc:v1:`). This allows the system to reliably distinguish between:
1.  **Encrypted ciphertext:** Values starting with the prefix.
2.  **Legacy plaintext:** Values without the prefix (e.g., from before the encryption was enabled).

### Why use a prefix?
Without a prefix, it's difficult to know if a string in the database is already encrypted or if it's still plaintext. Attempting to decrypt plaintext usually results in a decryption error. With the prefix, the `EncryptedNullableCast` can safely return the raw value if no prefix is found, preventing errors during migration or partial rollouts.

### Migration Command
If you have data that was encrypted with an older version of this package (without a prefix), you can use the following command to add the prefix to existing ciphertexts:

```bash
php artisan dynamic-encrypter:add-prefix --all
```

Options:
- `--model=FQCN`: Process specific models (can be repeated).
- `--all`: Process all models using encryption.
- `--from="2025-01-01 00:00:00"`: Only process records updated after this date.
- `--to="2025-12-31 23:59:59"`: Only process records updated before this date.
- `--dry-run`: Show what would be updated without changing the database.

The command only adds the prefix if the value:
1.  Does not already have the prefix.
2.  Starts with `eyJpdiI` (typical Laravel Base64-encoded encryption payload).
3.  Is longer than 20 characters.
4.  Contains a valid JSON structure with `iv`, `value`, and `mac` fields.

But be careful, there is a theoretical possibility that unencrypted Data is misinterpreted.

### Decrypt encrypted fields and store plaintext
Use this if you need to permanently decrypt encrypted values in your database for specific models/fields:

```bash
php artisan dynamic-encrypter:decrypt --model=App\\Models\\Secret --field=iban --field=note
# or everything
php artisan dynamic-encrypter:decrypt --all
```

- Decrypts values that are recognized as encrypted (with the configured prefix) and writes back the plaintext.
- Supports `--model` (repeatable), `--field` (repeatable), `--all`, and `--dry-run`.

### Encrypt any still-plaintext values in bulk
Use this when you have legacy plaintext in encryptable fields and want to encrypt them in one go:

```bash
php artisan dynamic-encrypter:encrypt --model=App\\Models\\Secret --field=iban
# or everything
php artisan dynamic-encrypter:encrypt --all
```

- Encrypts values that do not start with the configured prefix and are not already legacy-structured ciphertexts.
- Writes back with the current prefix and current dynamic encrypter.
- Supports `--model` (repeatable), `--field` (repeatable), `--all`, and `--dry-run`.

## Key Rotation
Re-encrypt existing data from an old key/password to a new one:
```bash
php artisan dynamic-encrypter:rotate --model=App\\Models\\Secret
```
Options:
- `--model=FQCN` Can be repeated.
- `--all` Rotate for all models using encryption (be careful on large datasets).
- `--dry-run` Do not write changes.

How it works:
- The command expects your cache bundle to contain both entries: `old_password` and `password` (names configurable via env/config).
- It decrypts each field with the old encrypter and re-encrypts with the new one, in chunks (`dynamic-encryption.chunk`).

## Testing

If you are writing Feature tests for your application, you need to ensure that a valid encryption key is present in the cache. Otherwise, models using `DynamicEncryptable` might throw exceptions or fail to save.

This package ships with a helper trait `DynamicEncryptionTestLoader`. Use it in your base `TestCase` to automatically inject a temporary key into the cache for the duration of the tests.

```php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Sneakyx\LaravelDynamicEncryption\Testing\DynamicEncryptionTestLoader;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use DynamicEncryptionTestLoader;

    protected function setUp(): void
    {
        parent::setUp();

        // Initializes a random key and writes it to the configured cache store
        $this->initializeDynamicEncryptionKeyForTests();
    }
}
```


## Security notes
- No key/password is written to logs.
- KDF parameters and salt live in `.env`/secrets; the password/key material lives in cache (bundle).
- Use a non-persistent cache store shared by your PHP workers (memcached/redis). Avoid the `array` driver.

## Compatibility
Tested with Laravel 10.

## License
MIT

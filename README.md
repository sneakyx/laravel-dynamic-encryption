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

### DynamicEncryptable Trait
Add the trait to your models and define an `$encryptable` array with fields to encrypt:
```php
use Illuminate\Database\Eloquent\Model;
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptable;

class Secret extends Model
{
    use DynamicEncryptable;

    protected array \$encryptable = ['token', 'note'];
}
```
- On save: Fields are automatically encrypted using the current dynamic encrypter (unless the missing-bundle policy says otherwise).
- On retrieved/post-save: Values are set back to plaintext in the model instance for convenient usage.

### Variante A: Cast-basierter "Locked"-Flow (empfohlen)
Statt einen kryptographischen Fallback (z. B. `APP_KEY`) zu nutzen, liefert der folgende Cast bei fehlendem Schlüssel KEINE Exception, sondern ein Placeholder-Objekt `LockedEncryptedValue`. Damit kann die UI den "Entsperren"-Dialog anzeigen, ohne den Renderpfad zu unterbrechen.

1) Cast verwenden:
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

2) In der UI prüfen:
```php
use Sneakyx\LaravelDynamicEncryption\Values\LockedEncryptedValue;

if ($userSecret->iban instanceof LockedEncryptedValue) {
    // zeige Banner/Modal: "Zum Anzeigen bitte Passwort eingeben"
} else {
    echo $userSecret->iban; // entschlüsselter Klartext
}
```

3) Beim Speichern:
- Ist der Bundle-Schlüssel verfügbar, wird normal verschlüsselt.
- Ist der Schlüssel nicht vorhanden, greift die Policy `dynamic-encryption.on_missing_bundle`:
  - `block` (Default): Es wird eine ValidationException geworfen.
  - `plaintext`: Wert wird absichtlich im Klartext gespeichert (nur temporär/ausnahmsweise nutzen!).

Hinweis: `LockedEncryptedValue` ist string-/json-serialisierbar (liefert leere Zeichenkette bzw. `null`) und enthält keine Klartextdaten.

### Where is the key/password stored?
The package expects a key bundle (array) in your cache under `DYNAMIC_ENCRYPTION_CACHE_KEY`.
Read more: [Where is the key?](docs/where-is-the-key.md)

## Key Rotation
Re-encrypt existing data from an old key/password to a new one:
```bash
php artisan encrypt:rotate --model=App\\Models\\Secret
```
Options:
- `--model=FQCN` Can be repeated.
- `--all` Rotate for all models using the DynamicEncryptable trait (be careful on large datasets).
- `--dry-run` Do not write changes.

How it works:
- The command expects your cache bundle to contain both entries: `old_password` and `password` (names configurable via env/config).
- It decrypts each field with the old encrypter and re-encrypts with the new one, in chunks (`dynamic-encryption.chunk`).

## Security notes
- No key/password is written to logs.
- KDF parameters and salt live in `.env`/secrets; the password/key material lives in cache (bundle).
- Use a non-persistent cache store shared by your PHP workers (memcached/redis). Avoid the `array` driver.

## Compatibility
Tested with Laravel 10.

## License
MIT

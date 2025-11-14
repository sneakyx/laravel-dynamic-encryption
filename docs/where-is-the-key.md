## Where is the key (or password) stored?

This package expects a single "bundle" (an array) in your cache under the cache key configured by `dynamic-encryption.array` (env: `DYNAMIC_ENCRYPTION_CACHE_KEY`).

The bundle can contain:
- `password` (env: `DYNAMIC_ENCRYPTION_ARRAY_KEY`) → either a plain password OR a `base64:`-encoded, already-derived key.
- `old_password` (env: `DYNAMIC_ENCRYPTION_ARRAY_OLD_KEY`) → optional, used during rotation.

The actual raw key bytes are never stored. If a plain password is provided, the package derives the correct-length key using the configured KDF and the salt from `.env`.

Important:
- The bundle is retrieved via your DEFAULT cache store (`Cache::get(...)`). Ensure your default cache driver points to the same backing store you populate (memcached/redis).
- Do NOT store the salt in cache. Provide it via `.env` (`DYNAMIC_ENCRYPTION_SALT`).

---

## 1) Store a bundle with a PLAIN password (preferred operational model)
```bash
php artisan tinker
```
```php
$bundle = [
    config('dynamic-encryption.key') => 'your-password-here',  // typically 'password' => '...'
    // Optionally keep an old password during rotation:
    // config('dynamic-encryption.old_key') => 'old-password-here',
];

// Persist the whole bundle under the array cache key
Cache::forever(config('dynamic-encryption.array'), $bundle);

// Verify
Cache::get(config('dynamic-encryption.array'));
```
With this setup, the package will use:
- KDF = `config('dynamic-encryption.kdf')` (default pbkdf2)
- Salt = `config('dynamic-encryption.kdf_salt')` (from `.env`)
- Parameters from `.env` (e.g., `DYNAMIC_ENCRYPTION_KDF_ITERS`)
- Required key size from `config('app.cipher')`

## 2) Store a bundle with a BASE64-derived key (no KDF at runtime)
```bash
php artisan tinker
```
```php
$required = in_array(strtolower(config('app.cipher')), ['aes-128-cbc','aes-128-gcm']) ? 16 : 32;
$key = 'base64:' . base64_encode(random_bytes($required));

$bundle = [
    config('dynamic-encryption.key') => $key, // e.g., 'password' => 'base64:...'
];

Cache::forever(config('dynamic-encryption.array'), $bundle);
Cache::get(config('dynamic-encryption.array'));
```
If the value starts with `base64:`, the package will decode and validate it against the required length, then use it directly.

## 3) Rotation bundle
During rotation, set both entries so the CLI can decrypt with old and re-encrypt with new:
```php
$bundle = [
    config('dynamic-encryption.old_key') => 'old-password-or-base64:...',
    config('dynamic-encryption.key')     => 'new-password-or-base64:...',
];
Cache::forever(config('dynamic-encryption.array'), $bundle);
```
Then run:
```bash
php artisan encrypt:rotate --model=App\Models\YourModel
```

---

## Configuration recap (.env)
```
# Bundle location and field names
DYNAMIC_ENCRYPTION_CACHE_STORE=memcached
DYNAMIC_ENCRYPTION_CACHE_KEY=dynamic_encryption_key
DYNAMIC_ENCRYPTION_ARRAY_KEY=password
DYNAMIC_ENCRYPTION_ARRAY_OLD_KEY=old_password

# KDF + salt (for password mode)
DYNAMIC_ENCRYPTION_KDF=pbkdf2    # or argon2id
DYNAMIC_ENCRYPTION_KDF_ALGO=sha256
DYNAMIC_ENCRYPTION_KDF_ITERS=210000
DYNAMIC_ENCRYPTION_SALT=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
```
Key size is inferred from `APP_CIPHER`/`config('app.cipher')`:
- aes-256-* → 32 bytes
- aes-128-* → 16 bytes

---

## Troubleshooting
| Issue | Solution |
|------|----------|
| `RuntimeException: Dynamic encryption key array is empty` | Ensure the bundle array is written to `Cache::forever(config('dynamic-encryption.array'), [...])` in your DEFAULT cache store. |
| `Dynamic encryption key is empty` | The bundle exists but does not contain the expected field (e.g., `password`). Check `DYNAMIC_ENCRYPTION_ARRAY_KEY`. |
| `Invalid base64 dynamic key: wrong length.` | Your `base64:` key decodes to the wrong size. Ensure it matches the cipher (16 or 32 bytes). |
| `KDF salt not configured` | Set `DYNAMIC_ENCRYPTION_SALT` in `.env` (raw or `base64:`). |
| Encryption still fails | Verify that your default `CACHE_DRIVER` matches where you populated the bundle; clear caches: `php artisan optimize:clear`. |


## Why This Package Exists

This package was created to solve practical problems in Laravel encryption while keeping operations simple:

1) Dynamic, ephemeral keys with deterministic recovery
- Laravel’s default encryption uses a static `APP_KEY`, making rotation awkward.
- Here, the effective runtime key is either:
  - provided as a `base64:`-encoded raw key in cache, or
  - deterministically derived from a plain password (stored in cache) using a KDF and a salt from `.env`.
- Result: You can clear caches and still recover the same key as long as the password, salt, and KDF params stay the same.

2) Reduce persistent key exposure (GDPR/DSGVO mindset)
- No encryption key is stored in the database.
- The password/key bundle lives in a volatile cache; the salt and KDF params live in environment config.
- There is no silent fallback to `APP_KEY` to avoid accidental use of the wrong key.

3) Minimal code changes for applications
- Works with Laravel’s native `Crypt`/`Encrypter`.
- Provides a cast to transparently encrypt/decrypt chosen model attributes without writing accessors/mutators.

### Use Cases
- Environments wanting key rotation without downtime.
- Systems that prefer not to persist raw encryption keys in databases.
- Teams that want a password-driven model (with KDF) and reproducible keys across restarts.

### Alternatives Considered
| Solution                     | Observation                                                    |
|------------------------------|----------------------------------------------------------------|
| Laravel’s default `Crypt`    | Static key; rotation requires app key changes.                 |
| `spatie/laravel-ciphersweet` | Great features but persistent keys and different crypto model. |
| Manual accessors/mutators    | Boilerplate and error-prone for many fields.                   |

### Design Decisions (updated)
1) Cache bundle + KDF-from-.env
- A bundle array in cache (under `dynamic-encryption.array`) contains `password`/`old_password`.
- If the value starts with `base64:`, it’s a ready-to-use raw key.
- Otherwise, the value is treated as a password and turned into a key with the configured KDF (PBKDF2 or Argon2id) and salt from `.env`.

2) Cipher-driven key length
- Required key size is derived from `config('app.cipher')` (16 or 32 bytes).

3) Fail fast, no key logging
- Missing/invalid bundle or misconfigured salt/KDF leads to explicit exceptions.
- No key or password is ever logged.

4) Rotation without downtime
- Provide both `old_password` and `password` in the bundle; run `php artisan dynamic-encrypter:rotate ...` to re-encrypt fields in batches.

This approach keeps secrets where they belong (password in cache/secret store, salt/KDF in env), avoids persisting raw keys, and remains compatible with Laravel’s native encryption APIs.

## Setting the Encryption Key in Cache

To use this package, you must **manually set the encryption key** in your configured cache storage (Memcached/Redis) **before using encryption**.
Here’s how to do it:

#### 1. Generate and Store the Key
```bash
php artisan tinker
```
```php
// Generate a new 32-byte key (AES-256 compatible)
$key = base64_encode(random_bytes(32));

// Store it in the cache (Memcached/Redis)
Cache::put(
    config('dynamic-encryption.key'),  // e.g., 'dynamic_encryption_key'
    'base64:' . $key,                  // Prefix with 'base64:' for compatibility
    now()->addDays(30)                 // TTL: 30 days (adjust as needed)
);

// Verify the key was set
Cache::get(config('dynamic-encryption.key'));
// Output: "base64:..." (your encrypted key)
```

#### 2. Automate Key Setup (Optional)
For production, **set the key during deployment** (e.g., in a `Deployer` script or CI/CD pipeline):

```bash
# Example for a deployment script (e.g., deploy.php)
php artisan tinker --execute="
    Cache::put(
        config('dynamic-encryption.key'),
        'base64:' . base64_encode(random_bytes(32)),
        now()->addDays(30)
    );
    echo 'Encryption key set in cache.' . PHP_EOL;
"
```

#### 3. Verify the Key Exists
Check if the key is set before using encryption:

```php
if (!Cache::has(config('dynamic-encryption.key'))) {
    throw new \RuntimeException('Encryption key not found in cache. Set it first!');
}
```

#### 4. Key Rotation
To **rotate the key manually** (without re-encrypting data):

```php
// Generate a new key
$newKey = base64_encode(random_bytes(32));

// Update the cache
Cache::put(
    config('dynamic-encryption.key'),
    'base64:' . $newKey,
    now()->addDays(30)
);

// Run the rotation command to re-encrypt data
php artisan encrypt:rotate --all
```

#### 5. Important Notes
- **Key Format**: The key **must be prefixed with `base64:`** (e.g., `base64:abc123...`).
  This tells the package to decode it as a **32-byte AES-256 key**.
- **TTL (Time-to-Live)**: Set a **sensible expiration** (e.g., 30 days).
    - Too short → Risk of key loss.
    - Too long → Reduced security (less frequent rotation).
- **No Persistent Storage**: The key **vanishes when the cache restarts**.
  Ensure your deployment process **always sets the key** (e.g., in `post-deploy` hooks).

#### 6. Example for CI/CD (GitHub Actions)
```yaml
# .github/workflows/deploy.yml
- name: Set encryption key
  run: |
    php artisan tinker --execute="
      Cache::put(
        config('dynamic-encryption.key'),
        'base64:' . base64_encode(random_bytes(32)),
        now()->addDays(30)
      );
    "
```

#### Troubleshooting
| Issue                          | Solution                                                                                     |
|--------------------------------|---------------------------------------------------------------------------------------------|
| `RuntimeException: Key missing` | Run the `tinker` command above to set the key.                                               |
| Key expires too soon           | Increase TTL in `Cache::put()` (e.g., `now()->addDays(90)`).                                 |
| Wrong key format               | Ensure the key starts with `base64:` (e.g., `base64:abc123...`).                              |
| Cache driver not configured    | Set `DYNAMIC_ENCRYPTION_STORAGE=memcache` in `.env` and ensure `CACHE_DRIVER` is configured. |


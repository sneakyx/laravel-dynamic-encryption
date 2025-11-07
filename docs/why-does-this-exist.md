## Why This Package Exists

This package was created to solve **three critical problems** in Laravel encryption:

1. **Dynamic Key Management**
   Laravel’s default encryption relies on a static `APP_KEY`, which is **inflexible** and **hard to rotate** without downtime.
   This package replaces it with a **volatile key system** (Memcached/Redis), allowing:
    - **Key rotation without downtime** (e.g., for security compliance).
    - **No persistent storage** (keys vanish on cache restart, reducing attack surface).

2. **DSGVO/GDPR Compliance**
   Storing encryption keys in `.env` or databases violates **data protection principles** (e.g., "storage limitation").
   This package enforces:
    - **Ephemeral keys** (only in memory/cache).
    - **No fallback to `APP_KEY`** (fails fast if key is missing, preventing silent security risks).

3. **Zero-Configuration Encryption**
   Unlike other packages (e.g., `spatie/laravel-ciphersweet`), this solution:
    - **Automatically encrypts/decrypts** model attributes via a trait.
    - **Requires no manual accessors/mutators**.
    - **Works with existing Laravel facades** (`Crypt::encryptString()`).

### Use Cases
- **High-security applications** (e.g., healthcare, finance) where key rotation is mandatory.
- **Multi-tenant systems** where each tenant needs isolated encryption keys.
- **Projects with strict GDPR requirements** (no persistent key storage).

### Alternatives Considered
| Solution                     | Problem                          |
 |------------------------------|----------------------------------|
| Laravel’s default `Crypt`    | Static key, no rotation support. |
| `spatie/laravel-ciphersweet` | Persistent keys, complex setup.  |
| Manual accessors/mutators    | Boilerplate code, error-prone.   |

This package provides a **minimalist, secure, and compliant** alternative.

### Key Design Decisions
1. **No Database Storage**
   Keys are **only stored in Memcached/Redis** to ensure volatility.
   *(Rationale: Databases can be compromised; caches are ephemeral.)*

2. **Explicit Model Selection**
   The `encrypt:rotate` command **requires `--model` or `--all`** to prevent accidental mass re-encryption.
   *(Rationale: Safety over convenience.)*

3. **Fail-Fast on Missing Keys**
   Throws exceptions if the dynamic key is missing (no silent fallback to `APP_KEY`).
   *(Rationale: Security > availability.)*

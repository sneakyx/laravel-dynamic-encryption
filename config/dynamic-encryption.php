<?php

return [
    'storage' => env('DYNAMIC_ENCRYPTION_CACHE_STORE', 'memcached'),
    'array' => env('DYNAMIC_ENCRYPTION_CACHE_KEY', 'dynamic_encryption_key'),
    'key' => env('DYNAMIC_ENCRYPTION_ARRAY_KEY', 'password'),
    'old_key' => env('DYNAMIC_ENCRYPTION_ARRAY_OLD_KEY', 'old_password'),

    // KDF settings (source from .env)
    // Supported: 'pbkdf2' (default), 'argon2id'
    'kdf' => env('DYNAMIC_ENCRYPTION_KDF', 'pbkdf2'),

    // Salt for deriving the key from the password. Not secret but must be stable.
    // Accepts either raw string or base64:... format.
    'kdf_salt' => env('DYNAMIC_ENCRYPTION_SALT', ''),

    // PBKDF2 parameters
    'kdf_algo' => env('DYNAMIC_ENCRYPTION_KDF_ALGO', 'sha256'),
    'kdf_iters' => (int) env('DYNAMIC_ENCRYPTION_KDF_ITERS', 210000),

    // Argon2id parameters (only used when kdf = argon2id)
    // Provide explicit values via env if sodium constants are unavailable in your PHP build.
    'argon_ops' => env('DYNAMIC_ENCRYPTION_ARGON_OPSLIMIT'),
    'argon_mem' => env('DYNAMIC_ENCRYPTION_ARGON_MEMLIMIT'),

    // Behavior when the dynamic key bundle is missing/invalid
    //  - 'block'     => prevent saving changed encryptable fields (default)
    //  - 'plaintext' => skip encryption for changed fields (store as plaintext)
    //  - 'fail'      => throw and fail boot
    'on_missing_bundle' => env('DYNAMIC_ENCRYPTION_ON_MISSING_BUNDLE', 'block'),

    // What if the value cannot be decrypted?
    //  - 'placeholder'     => return a placeholder value (default)
    //  - 'fail'            => throw and fail validation
    //  - 'encrypted'       => return the encrypted value
    //  - 'null'            => return null (fallback)
    'on_decryption_error' => env('DYNAMIC_ENCRYPTION_ON_DECRYPTION_ERROR_RETURN', 'placeholder'),

    // standard-prefix setting for saving encrypted values to the database
    'prefix' => env('DYNAMIC_ENCRYPTION_PREFIX', 'dynenc:v1:'),

    'chunk' => 200,
];

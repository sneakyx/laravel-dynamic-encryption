<?php

return [
    'storage' => env('DYNAMIC_ENCRYPTION_STORAGE', 'memcache'),
    'key' => env('DYNAMIC_ENCRYPTION_KEY', 'dynamic_encryption_key'),
    'chunk' => 200,
];

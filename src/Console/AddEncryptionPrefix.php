<?php

namespace Sneakyx\LaravelDynamicEncryption\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;
use Sneakyx\LaravelDynamicEncryption\Services\StorageManager;

class AddEncryptionPrefix extends Command
{
    protected $signature = 'dynamic-encrypter:migrate-legacy
                            {--model=* : Specific FQCN models to process}
                            {--all : Process all models using encryption}
                            {--from= : Filter by updated_at from date (Y-m-d H:i:s)}
                            {--to= : Filter by updated_at to date (Y-m-d H:i:s)}
                            {--old-password= : Use a specific old password for legacy decryption}
                            {--old-salt= : Use a specific old KDF salt for legacy decryption}
                            {--old-kdf-iters= : Use specific old KDF iterations for legacy decryption}
                            {--skip-app-key : Do NOT try decryption with APP_KEY}
                            {--skip-no-salt : Do NOT try KDF without salt}
                            {--dry-run : Dry run without changing data}
                            {--debug-first : Show debug info for the first failed record and stop}';

    protected $description = 'Re-encrypt legacy values with the current encryption method and add prefix.';

    public function handle(): int
    {
        $models = $this->option('model');
        if (empty($models) && ! $this->option('all')) {
            $this->error('No models specified. Use --model=ModelClass or --all.');

            return Command::FAILURE;
        }

        if ($this->option('all')) {
            $models = $this->findAllModelsWithEncryption();
        }

        $from = $this->option('from');
        $to = $this->option('to');
        $dryRun = (bool) $this->option('dry-run');
        $debugFirst = (bool) $this->option('debug-first');
        $prefix = config('dynamic-encryption.prefix', EncryptedNullableCast::STANDARD_PREFIX);
        $chunk = (int) Config::get('dynamic-encryption.chunk', 200);

        // Build all candidate legacy encrypters
        $legacyEncrypters = $this->buildLegacyEncrypters();

        if (empty($legacyEncrypters)) {
            $this->error('Could not build any legacy encrypter. Check configuration and cache.');

            return Command::FAILURE;
        }

        $this->info('Built '.count($legacyEncrypters).' legacy encrypter candidate(s):');
        foreach ($legacyEncrypters as $label => $enc) {
            $this->line("  - {$label}");
        }

        // The current encrypter (with KDF + prefix)
        $currentEncrypter = app('encrypter');

        foreach ($models as $fqcn) {
            if (! class_exists($fqcn)) {
                $this->warn("Model not found: {$fqcn}");

                continue;
            }

            $this->info("Processing model: {$fqcn}");
            /** @var Model $modelInstance */
            $modelInstance = new $fqcn;
            $tableName = $modelInstance->getTable();

            $fields = $this->getEncryptedFields($modelInstance);

            if (empty($fields)) {
                $this->warn("Model {$fqcn} has no encrypted fields.");

                continue;
            }

            $query = $fqcn::query();
            if ($from) {
                $query->where('updated_at', '>=', $from);
            }
            if ($to) {
                $query->where('updated_at', '<=', $to);
            }

            $totalMigrated = 0;
            $totalSkipped = 0;
            $totalFailed = 0;
            $stopProcessing = false;

            $query->orderBy($modelInstance->getKeyName())->chunk($chunk, function ($items) use (
                $fields, $prefix, $dryRun, $debugFirst, $tableName, $legacyEncrypters, $currentEncrypter,
                &$totalMigrated, &$totalSkipped, &$totalFailed, &$stopProcessing
            ) {
                if ($stopProcessing) {
                    return false;
                }

                foreach ($items as $item) {
                    if ($stopProcessing) {
                        return false;
                    }

                    $updates = [];
                    foreach ($fields as $field) {
                        $value = $item->getRawOriginal($field);

                        if (empty($value)) {
                            continue;
                        }

                        // Already has the new prefix → skip
                        if (str_starts_with($value, $prefix)) {
                            continue;
                        }

                        // Check if it looks like a Laravel encrypted payload
                        if (strlen($value) > 20 && str_starts_with($value, 'eyJpdiI')) {
                            $decoded = json_decode(base64_decode($value), true);

                            if (! is_array($decoded) || ! isset($decoded['iv'], $decoded['value'], $decoded['mac'])) {
                                continue;
                            }

                            // Try all legacy encrypters until one works
                            $plaintext = $this->tryDecryptWithCandidates($legacyEncrypters, $value);

                            if ($plaintext === null) {
                                $totalFailed++;

                                if ($debugFirst) {
                                    $this->error("DEBUG: Could not decrypt field '{$field}' for record {$item->getKey()}");
                                    $this->line('  Raw value (first 80 chars): '.substr($value, 0, 80).'...');
                                    $this->line('  Decoded payload:');
                                    $this->line('    iv:    '.($decoded['iv'] ?? 'n/a'));
                                    $this->line('    value: '.substr($decoded['value'] ?? '', 0, 40).'...');
                                    $this->line('    mac:   '.($decoded['mac'] ?? 'n/a'));
                                    $this->line('    tag:   '.($decoded['tag'] ?? 'n/a'));
                                    $this->line('  Tried encrypters:');
                                    foreach ($legacyEncrypters as $label => $enc) {
                                        try {
                                            $enc->decryptString($value);
                                            $this->line("    {$label}: SUCCESS");
                                        } catch (\Throwable $e) {
                                            $this->line("    {$label}: FAILED - ".$e->getMessage());
                                        }
                                    }
                                    $stopProcessing = true;

                                    return false;
                                }

                                $this->warn("  Could not decrypt field '{$field}' for record {$item->getKey()} with any legacy key.");

                                continue;
                            }

                            // Re-encrypt with current encrypter (KDF-based) + prefix
                            $newEncrypted = $prefix.$currentEncrypter->encryptString($plaintext);
                            $updates[$field] = $newEncrypted;
                        }
                    }

                    if (! empty($updates)) {
                        if (! $dryRun) {
                            DB::table($tableName)->where($item->getKeyName(), $item->getKey())->update($updates);
                        }
                        $totalMigrated++;
                    } else {
                        $totalSkipped++;
                    }
                }
            });

            $this->info("Migrated {$totalMigrated} records, skipped {$totalSkipped}, failed {$totalFailed} for {$fqcn}".($dryRun ? ' (dry run)' : ''));

            if ($stopProcessing) {
                return Command::FAILURE;
            }
        }

        $this->info('Migration completed.');

        return Command::SUCCESS;
    }

    /**
     * Build all candidate legacy encrypters to try during migration.
     *
     * @return array<string, Encrypter>
     */
    protected function buildLegacyEncrypters(): array
    {
        $encrypters = [];
        $cipher = (string) Config::get('app.cipher', 'aes-256-cbc');

        /** @var StorageManager $storageManager */
        $storageManager = app(StorageManager::class);

        // Determine passwords to try
        $passwords = [];
        try {
            $passwords['current'] = $storageManager->getKeyString();
        } catch (\Throwable $e) {
            $this->warn('Could not load current password from cache: '.$e->getMessage());
        }

        if ($this->option('old-password')) {
            $passwords['cli-old'] = $this->option('old-password');
        }

        try {
            $oldPassword = $storageManager->getKeyString(true);
            if (! in_array($oldPassword, $passwords, true)) {
                $passwords['config-old'] = $oldPassword;
            }
        } catch (\Throwable $e) {
            // Old password not available
        }

        if (empty($passwords)) {
            $this->error('No passwords available to build legacy encrypters.');

            return [];
        }

        // Strategy 1: Current KDF settings (password + current salt/iters)
        foreach ($passwords as $pwLabel => $pw) {
            try {
                $enc = $storageManager->makeEncrypterFromKeyString($pw, $cipher);
                $encrypters["kdf-current-salt({$pwLabel})"] = $enc;
            } catch (\Throwable $e) {
                $this->warn("Could not build encrypter with current KDF for '{$pwLabel}': ".$e->getMessage());
            }
        }

        // Strategy 2: If --old-salt or --old-kdf-iters given, derive with those legacy params
        $oldSalt = $this->option('old-salt');
        $oldIters = $this->option('old-kdf-iters');

        if ($oldSalt || $oldIters) {
            $origSalt = Config::get('dynamic-encryption.kdf_salt');
            $origIters = Config::get('dynamic-encryption.kdf_iters');

            if ($oldSalt) {
                Config::set('dynamic-encryption.kdf_salt', $oldSalt);
            }
            if ($oldIters) {
                Config::set('dynamic-encryption.kdf_iters', (int) $oldIters);
            }

            foreach ($passwords as $pwLabel => $pw) {
                try {
                    $enc = $storageManager->makeEncrypterFromKeyString($pw, $cipher);
                    $encrypters["kdf-old-params({$pwLabel})"] = $enc;
                } catch (\Throwable $e) {
                    $this->warn("Could not build encrypter with old KDF params for '{$pwLabel}': ".$e->getMessage());
                }
            }

            Config::set('dynamic-encryption.kdf_salt', $origSalt);
            Config::set('dynamic-encryption.kdf_iters', $origIters);
        }

        // Strategy 3: Try without salt (production may not have had DYNAMIC_ENCRYPTION_SALT set)
        if (! $this->option('skip-no-salt')) {
            $required = $this->requiredKeySize($cipher);

            foreach ($passwords as $pwLabel => $pw) {
                // Skip base64 keys – they don't go through KDF
                if (str_starts_with($pw, 'base64:')) {
                    continue;
                }

                $algo = (string) Config::get('dynamic-encryption.kdf_algo', 'sha256');
                $iters = (int) Config::get('dynamic-encryption.kdf_iters', 210000);

                // Try with empty string as salt
                try {
                    $keyBytes = hash_pbkdf2($algo, $pw, '', $iters, $required, true);
                    $encrypters["kdf-no-salt({$pwLabel})"] = new Encrypter($keyBytes, $cipher);
                } catch (\Throwable $e) {
                    $this->warn("Could not build no-salt encrypter for '{$pwLabel}': ".$e->getMessage());
                }
            }
        }

        // Strategy 4: Try APP_KEY
        if (! $this->option('skip-app-key')) {
            try {
                $appKey = (string) Config::get('app.key');
                $keyBytes = str_starts_with($appKey, 'base64:')
                    ? base64_decode(substr($appKey, 7), true)
                    : $appKey;

                $encrypters['app-key'] = new Encrypter($keyBytes, $cipher);
            } catch (\Throwable $e) {
                $this->warn('Could not build APP_KEY encrypter: '.$e->getMessage());
            }
        }

        return $encrypters;
    }

    /**
     * Try to decrypt a value with all candidate encrypters.
     */
    protected function tryDecryptWithCandidates(array $encrypters, string $value): ?string
    {
        foreach ($encrypters as $label => $encrypter) {
            try {
                return $encrypter->decryptString($value);
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    protected function requiredKeySize(string $cipher): int
    {
        return match (strtolower($cipher)) {
            'aes-128-cbc', 'aes-128-gcm' => 16,
            'aes-256-cbc', 'aes-256-gcm' => 32,
            default => 32,
        };
    }

    protected function findAllModelsWithEncryption(): array
    {
        $models = [];
        $modelPath = app_path('Models');
        if (! is_dir($modelPath)) {
            return $models;
        }
        foreach (scandir($modelPath) as $file) {
            if (str_ends_with($file, '.php')) {
                $class = 'App\\Models\\'.str_replace('.php', '', $file);
                if (class_exists($class)) {
                    $instance = new $class;
                    if ($this->hasEncryption($instance)) {
                        $models[] = $class;
                    }
                }
            }
        }

        return $models;
    }

    protected function hasEncryption(Model $model): bool
    {
        foreach ($model->getCasts() as $cast) {
            if ($cast === EncryptedNullableCast::class ||
                (is_string($cast) && class_exists($cast) && is_subclass_of($cast, EncryptedNullableCast::class))) {
                return true;
            }
        }

        return false;
    }

    protected function getEncryptedFields(Model $model): array
    {
        $fields = [];
        foreach ($model->getCasts() as $field => $cast) {
            if ($cast === EncryptedNullableCast::class ||
                (is_string($cast) && class_exists($cast) && is_subclass_of($cast, EncryptedNullableCast::class))) {
                $fields[] = $field;
            }
        }

        if (property_exists($model, 'encryptable') && isset($model->encryptable)) {
            $this->warn('Model '.get_class($model).' uses deprecated $encryptable property. Please migrate to Casts.');
            $fields = array_merge($fields, $model->encryptable);
        }

        return array_unique($fields);
    }
}

<?php

namespace Sneakyx\LaravelDynamicEncryption\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;
use Sneakyx\LaravelDynamicEncryption\Services\StorageManager;
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptable;

class RotateEncryptionKey extends Command
{
    protected $signature = 'dynamic-encrypter:rotate {--model=* : Specific FQCN models to rotate} {--all : Rotate for all models using Encryptable} {--dry-run : Dry run without changing data}';

    protected $description = 'Rotate the dynamic encryption key and re-encrypt models.';

    public function handle(StorageManager $storage): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $models = $this->option('model');
        if (empty($models) && ! $this->option('all')) {
            $this->error('No models specified. Use --model=ModelClass or --all to rotate keys.');

            return Command::FAILURE;
        }
        if ($this->option('all')) {
            $models = $this->findAllModelsWithEncryption();
        }

        // get old key from storage
        try {
            $oldKeyString = $storage->getKeyString(true);
        } catch (\Throwable $e) {
            $this->error('Old key not found: '.$e->getMessage());

            return Command::FAILURE;
        }
        $oldEncrypter = $storage->makeEncrypterFromKeyString($oldKeyString);

        // get new key from storage
        try {
            $newKeyString = $storage->getKeyString(false);
        } catch (\Throwable $e) {
            $this->error('New key not found: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run: would use existing old and new keys to re-encrypt data.');
        } else {
            $this->info('Using existing new dynamic key for rotation.');
            Log::info('Dynamic encryption key rotated');
        }

        $newEncrypter = $storage->makeEncrypterFromKeyString($newKeyString);

        $chunk = (int) Config::get('dynamic-encryption.chunk', 200);

        foreach ($models as $fqcn) {
            if (! class_exists($fqcn)) {
                $this->warn("Model not found: {$fqcn}");

                continue;
            }

            $this->info("Re-encrypting model: {$fqcn}");
            $modelInstance = new $fqcn;
            $encryptable = $this->getEncryptedFields($modelInstance);
            if (empty($encryptable)) {
                $this->warn("Model {$fqcn} has no encryptable fields.");

                continue;
            }

            $modelInstance->newQuery()->select(['id'])->orderBy('id')->chunk($chunk, function ($items) use ($fqcn, $encryptable, $oldEncrypter, $newEncrypter, $modelInstance) {
                foreach ($items as $item) {
                    // Reload full row
                    $row = $fqcn::query()->find($item->id);
                    $dirty = false;
                    foreach ($encryptable as $field) {
                        // WICHTIG: getRawOriginal() verwenden, um Cast zu umgehen!
                        $val = $row->getRawOriginal($field);
                        if (is_null($val) || $val === '') {
                            continue;
                        }

                        $prefix = config('dynamic-encryption.prefix', 'dynenc:v1:');

                        // Skip if value doesn't have prefix and doesn't look like encrypted data
                        $hasPrefix = str_starts_with($val, $prefix);
                        if (! $hasPrefix) {
                            // Check if it looks like legacy encrypted data (Laravel's encrypted format starts with eyJ = base64 JSON)
                            if (! str_starts_with($val, 'eyJ')) {
                                $this->warn("Failed to decrypt {$field} for {$row->getKey()}: This is a legacy unencrypted value.");

                                continue;
                            } else {
                                $this->warn("Failed to decrypt {$field} for {$row->getKey()}: This seems to be encrypted, but the correct prefix is missing.");

                                continue;
                            }
                        }

                        $ciphertext = $val;
                        $ciphertext = substr($val, strlen($prefix));

                        try {
                            $decrypted = $oldEncrypter->decryptString($ciphertext);
                        } catch (\Throwable $e) {
                            $this->warn("Failed to decrypt {$field} for {$row->getKey()}: ".$e->getMessage());
                            continue;
                        }

                        $reencrypted = $newEncrypter->encryptString($decrypted);
                        $reencrypted = $prefix.$reencrypted;

                        if ($reencrypted !== $val) {
                            \DB::table($modelInstance->getTable())
                                ->where($row->getKeyName(), $row->getKey())
                                ->update([$field => $reencrypted]);
                            $dirty = true;
                            $this->info("  Re-encrypted {$row->getKey()}");
                        }
                    }
                }
            });
        }

        $this->info('Rotation complete.');

        return Command::SUCCESS;
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
        if (in_array(DynamicEncryptable::class, class_uses_recursive($model))) {
            return true;
        }

        foreach ($model->getCasts() as $cast) {
            if ($cast === EncryptedNullableCast::class || (is_string($cast) && class_exists($cast) && is_subclass_of($cast, EncryptedNullableCast::class))) {
                return true;
            }
        }

        return false;
    }

    protected function getEncryptedFields(Model $model): array
    {
        $fields = [];

        // Prefer getEncryptableAttributes() if available
        if (method_exists($model, 'getEncryptableAttributes')) {
            $fields = array_merge($fields, $model->getEncryptableAttributes());
        }

        // Also scan casts directly (redundant but safe)
        foreach ($model->getCasts() as $field => $cast) {
            if ($cast === EncryptedNullableCast::class ||
                (is_string($cast) && class_exists($cast) && is_subclass_of($cast, EncryptedNullableCast::class))) {
                $fields[] = $field;
            }
        }

        // Legacy support: check for deprecated $encryptable property
        if (property_exists($model, 'encryptable') && isset($model->encryptable)) {
            $this->warn('Model '.get_class($model).' uses deprecated $encryptable property. Please migrate to Casts.');
            $fields = array_merge($fields, $model->encryptable);
        }

        return array_unique($fields);
    }
}

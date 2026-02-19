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
    protected $signature = 'encrypt:rotate {--model=* : Specific FQCN models to rotate} {--all : Rotate for all models using Encryptable} {--dry-run : Dry run without changing data}';

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

            $modelInstance->newQuery()->select(['id'])->orderBy('id')->chunk($chunk, function ($items) use ($fqcn, $encryptable, $oldEncrypter, $newEncrypter, $dryRun) {
                foreach ($items as $item) {
                    // Reload full row
                    $row = $fqcn::query()->find($item->id);
                    $dirty = false;
                    foreach ($encryptable as $field) {
                        $val = $row->getAttribute($field);
                        if (is_null($val)) {
                            continue;
                        }

                        $prefix = config('dynamic-encryption.prefix', 'dynenc:v1:');
                        $ciphertext = $val;
                        $hasPrefix = str_starts_with($val, $prefix);

                        if ($hasPrefix) {
                            $ciphertext = substr($val, strlen($prefix));
                        }

                        try {
                            $decrypted = $oldEncrypter->decryptString($ciphertext);
                        } catch (\Throwable $e) {
                            // cannot decrypt with the old key, skip this field
                            continue;
                        }

                        $reencrypted = $newEncrypter->encryptString($decrypted);
                        if ($hasPrefix) {
                            $reencrypted = $prefix.$reencrypted;
                        }

                        if ($reencrypted !== $val) {
                            $row->setAttribute($field, $reencrypted);
                            $dirty = true;
                        }
                    }
                    if ($dirty && ! $dryRun) {
                        $row->save();
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

        if (method_exists($model, 'getEncryptableAttributes')) {
            $fields = array_merge($fields, $model->getEncryptableAttributes());
        } elseif (isset($model->encryptable)) {
            $fields = array_merge($fields, $model->encryptable);
        }

        foreach ($model->getCasts() as $field => $cast) {
            if ($cast === EncryptedNullableCast::class || (is_string($cast) && class_exists($cast) && is_subclass_of($cast, EncryptedNullableCast::class))) {
                $fields[] = $field;
            }
        }

        return array_unique($fields);
    }
}

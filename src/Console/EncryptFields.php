<?php

namespace Sneakyx\LaravelDynamicEncryption\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;

class EncryptFields extends Command
{
    protected $signature = 'dynamic-encrypter:encrypt
                            {--model=* : Specific FQCN models to process}
                            {--field=* : Specific fields to encrypt (if model is specified)}
                            {--all : Process all models using encryption}
                            {--dry-run : Dry run without changing data}';

    protected $description = 'Encrypt unencrypted fields and store them in the database.';

    public function handle(): int
    {
        $models = $this->option('model');
        $specificFields = $this->option('field');

        if (empty($models) && ! $this->option('all')) {
            $this->error('No models specified. Use --model=ModelClass or --all.');

            return Command::FAILURE;
        }

        if ($this->option('all')) {
            $models = $this->findAllModelsWithEncryption();
        }

        $dryRun = (bool) $this->option('dry-run');
        $prefix = config('dynamic-encryption.prefix', EncryptedNullableCast::STANDARD_PREFIX);
        $chunk = (int) Config::get('dynamic-encryption.chunk', 200);

        foreach ($models as $fqcn) {
            if (! class_exists($fqcn)) {
                $this->warn("Model not found: {$fqcn}");

                continue;
            }

            $this->info("Processing model: {$fqcn}");
            /** @var Model $modelInstance */
            $modelInstance = new $fqcn;
            $tableName = $modelInstance->getTable();

            $encryptedFields = $this->getEncryptedFields($modelInstance);

            $fieldsToProcess = ! empty($specificFields)
                ? array_intersect($specificFields, $encryptedFields)
                : $encryptedFields;

            if (empty($fieldsToProcess)) {
                $this->warn("Model {$fqcn} has no matching fields to encrypt.");

                continue;
            }

            $query = $fqcn::query();
            $totalEncrypted = 0;

            $query->orderBy($modelInstance->getKeyName())->chunk($chunk, function ($items) use ($fieldsToProcess, $prefix, $dryRun, $tableName, &$totalEncrypted) {
                foreach ($items as $item) {
                    $updates = [];
                    foreach ($fieldsToProcess as $field) {
                        $value = $item->getRawOriginal($field);

                        if (empty($value)) {
                            continue;
                        }

                        // Check if already encrypted
                        if (str_starts_with($value, $prefix)) {
                            continue;
                        }

                        // We also check if it's potentially encrypted without prefix (legacy)
                        if (strlen($value) > 20 && str_starts_with($value, 'eyJpdiI')) {
                            $decoded = json_decode(base64_decode($value), true);
                            if (is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac'])) {
                                continue;
                            }
                        }

                        $encrypted = app('encrypter')->encryptString((string) $value);
                        $updates[$field] = $prefix.$encrypted;
                    }

                    if (! empty($updates)) {
                        if (! $dryRun) {
                            DB::table($tableName)->where($item->getKeyName(), $item->getKey())->update($updates);
                        }
                        $totalEncrypted++;
                    }
                }
            });

            $this->info("Encrypted {$totalEncrypted} records for {$fqcn}".($dryRun ? ' (dry run)' : ''));
        }

        $this->info('Task completed.');

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

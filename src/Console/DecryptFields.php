<?php

namespace Sneakyx\LaravelDynamicEncryption\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptable;

class DecryptFields extends Command
{
    protected $signature = 'encrypt:decrypt
                            {--model=* : Specific FQCN models to process}
                            {--field=* : Specific fields to decrypt (if model is specified)}
                            {--all : Process all models using encryption}
                            {--dry-run : Dry run without changing data}';

    protected $description = 'Decrypt fields and store them in plaintext in the database.';

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

            $fieldsToProcess = !empty($specificFields)
                ? array_intersect($specificFields, $encryptedFields)
                : $encryptedFields;

            if (empty($fieldsToProcess)) {
                $this->warn("Model {$fqcn} has no matching encrypted fields to decrypt.");

                continue;
            }

            $query = $fqcn::query();
            $totalDecrypted = 0;

            $query->orderBy($modelInstance->getKeyName())->chunk($chunk, function ($items) use ($fieldsToProcess, $dryRun, $tableName, &$totalDecrypted) {
                foreach ($items as $item) {
                    $updates = [];
                    foreach ($fieldsToProcess as $field) {
                        $value = $item->getRawOriginal($field);

                        if (empty($value)) {
                            continue;
                        }

                        $decrypted = $this->attemptDecrypt($value);

                        if ($decrypted !== null && $decrypted !== $value) {
                            $updates[$field] = $decrypted;
                        }
                    }

                    if (! empty($updates)) {
                        if (! $dryRun) {
                            DB::table($tableName)->where($item->getKeyName(), $item->getKey())->update($updates);
                        }
                        $totalDecrypted++;
                    }
                }
            });

            $this->info("Decrypted {$totalDecrypted} records for {$fqcn}".($dryRun ? ' (dry run)' : ''));
        }

        $this->info('Task completed.');

        return Command::SUCCESS;
    }

    protected function attemptDecrypt(string $value): ?string
    {
        $prefix = config('dynamic-encryption.prefix', EncryptedNullableCast::STANDARD_PREFIX);
        if (str_starts_with($value, $prefix)) {
            $value = substr($value, strlen($prefix));
        }

        try {
            return app('encrypter')->decryptString($value);
        } catch (\Throwable $e) {
            return null;
        }
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

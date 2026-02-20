<?php

namespace Sneakyx\LaravelDynamicEncryption\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;

class AddEncryptionPrefix extends Command
{
    protected $signature = 'dynamic-encrypter:add-prefix
                            {--model=* : Specific FQCN models to process}
                            {--all : Process all models using encryption}
                            {--from= : Filter by updated_at from date (Y-m-d H:i:s)}
                            {--to= : Filter by updated_at to date (Y-m-d H:i:s)}
                            {--dry-run : Dry run without changing data}';

    protected $description = 'Add versioned prefix to already encrypted values that lack it.';

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

            $totalUpdated = 0;

            $query->orderBy($modelInstance->getKeyName())->chunk($chunk, function ($items) use ($fields, $prefix, $dryRun, $tableName, &$totalUpdated) {
                foreach ($items as $item) {
                    $updates = [];
                    foreach ($fields as $field) {
                        $value = $item->getRawOriginal($field);

                        if (empty($value)) {
                            continue;
                        }

                        // 1. Check: Hat es schon den neuen Prefix?
                        if (str_starts_with($value, $prefix)) {
                            continue;
                        }

                        // 2. Check: Ist es valides Base64 und enthält die Laravel-Struktur?
                        // Wir prüfen auch auf 'eyJpdiI' am Anfang wie gewünscht
                        if (strlen($value) > 20 && str_starts_with($value, 'eyJpdiI')) {
                            $decoded = json_decode(base64_decode($value), true);

                            if (is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac'])) {
                                $updates[$field] = $prefix.$value;
                            }
                        }
                    }

                    if (! empty($updates)) {
                        if (! $dryRun) {
                            DB::table($tableName)->where($item->getKeyName(), $item->getKey())->update($updates);
                        }
                        $totalUpdated++;
                    }
                }
            });

            $this->info("Updated {$totalUpdated} records for {$fqcn}".($dryRun ? ' (dry run)' : ''));
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

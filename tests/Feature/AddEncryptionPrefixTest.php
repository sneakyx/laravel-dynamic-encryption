<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptable;
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptionTestLoader;

class AddEncryptionPrefixTest extends Orchestra
{
    use DynamicEncryptionTestLoader;

    protected function getPackageProviders($app)
    {
        return [DynamicEncryptionServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('dynamic-encryption.storage', 'array');
        $app['config']->set('dynamic-encryption.array', 'dynamic_encryption_key');
        $app['config']->set('dynamic-encryption.key', 'password');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('prefixed_test_models', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('secret_cast')->nullable();
            $table->text('secret_trait')->nullable();
            $table->timestamps();
        });

        $this->initializeDynamicEncryptionKeyForTests();
    }

    public function test_it_adds_prefix_to_unprefixed_encrypted_data(): void
    {
        // Erzeuge verschlüsselten Wert OHNE Präfix
        $plain = 'my-secret-data';
        $encryptedWithoutPrefix = app('encrypter')->encryptString($plain);

        // Direkt in DB einfügen um Cast/Trait zu umgehen
        DB::table('prefixed_test_models')->insert([
            'name' => 'Legacy Record',
            'secret_cast' => $encryptedWithoutPrefix,
            'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:add-prefix', ['--model' => [PrefixedTestModel::class]])
            ->expectsOutputToContain('Updated 1 records for Sneakyx\LaravelDynamicEncryption\Tests\Feature\PrefixedTestModel')
            ->assertExitCode(0);

        $record = DB::table('prefixed_test_models')->first();
        $this->assertStringStartsWith('dynenc:v1:', $record->secret_cast);
        $this->assertSame('dynenc:v1:'.$encryptedWithoutPrefix, $record->secret_cast);

        // Über Eloquent prüfen ob es lesbar ist
        $model = PrefixedTestModel::first();
        $this->assertSame($plain, $model->secret_cast);
    }

    public function test_it_avoids_double_prefixing(): void
    {
        $prefixedValue = 'dynenc:v1:already-prefixed-value';

        DB::table('prefixed_test_models')->insert([
            'secret_cast' => $prefixedValue,
            'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:add-prefix', ['--model' => [PrefixedTestModel::class]])
            ->expectsOutputToContain('Updated 0 records')
            ->assertExitCode(0);

        $this->assertSame($prefixedValue, DB::table('prefixed_test_models')->first()->secret_cast);
    }

    public function test_it_ignores_plaintext(): void
    {
        $plaintext = 'plain-text-email@example.com';

        DB::table('prefixed_test_models')->insert([
            'secret_cast' => $plaintext,
            'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:add-prefix', ['--model' => [PrefixedTestModel::class]])
            ->expectsOutputToContain('Updated 0 records')
            ->assertExitCode(0);

        $this->assertSame($plaintext, DB::table('prefixed_test_models')->first()->secret_cast);
    }

    public function test_it_filters_by_date(): void
    {
        $encrypted = app('encrypter')->encryptString('data');

        DB::table('prefixed_test_models')->insert([
            ['secret_cast' => $encrypted, 'updated_at' => '2024-01-01 10:00:00'],
            ['secret_cast' => $encrypted, 'updated_at' => '2025-01-01 10:00:00'],
        ]);

        // Filter: nur ab 2024-12-01
        $this->artisan('dynamic-encrypter:add-prefix', [
            '--model' => [PrefixedTestModel::class],
            '--from' => '2024-12-01 00:00:00',
        ])->expectsOutputToContain('Updated 1 records');

        $records = DB::table('prefixed_test_models')->orderBy('id')->get();
        $this->assertStringStartsNotWith('dynenc:v1:', $records[0]->secret_cast);
        $this->assertStringStartsWith('dynenc:v1:', $records[1]->secret_cast);
    }

    public function test_dry_run_does_not_change_data(): void
    {
        $encrypted = app('encrypter')->encryptString('data');

        DB::table('prefixed_test_models')->insert([
            'secret_cast' => $encrypted,
            'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:add-prefix', [
            '--model' => [PrefixedTestModel::class],
            '--dry-run' => true,
        ])->expectsOutputToContain('Updated 1 records for Sneakyx\LaravelDynamicEncryption\Tests\Feature\PrefixedTestModel (dry run)')
            ->assertExitCode(0);

        $this->assertSame($encrypted, DB::table('prefixed_test_models')->first()->secret_cast);
    }

    public function test_it_handles_trait_fields(): void
    {
        $encrypted = app('encrypter')->encryptString('trait-data');

        DB::table('prefixed_test_models')->insert([
            'secret_trait' => $encrypted,
            'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:add-prefix', ['--model' => [PrefixedTestModel::class]])
            ->assertExitCode(0);

        $record = DB::table('prefixed_test_models')->first();
        $this->assertStringStartsWith('dynenc:v1:', $record->secret_trait);
    }
}

class PrefixedTestModel extends \Illuminate\Database\Eloquent\Model
{
    use DynamicEncryptable;

    protected $table = 'prefixed_test_models';

    protected $casts = [
        'secret_cast' => EncryptedNullableCast::class,
        'secret_trait' => EncryptedNullableCast::class,
    ];
}

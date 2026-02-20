<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptionTestLoader;

class DecryptFieldsTest extends Orchestra
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

        Schema::create('decrypt_models', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('secret_cast')->nullable();
            $table->text('secret_trait')->nullable();
            $table->timestamps();
        });

        $this->initializeDynamicEncryptionKeyForTests();
    }

    public function test_it_decrypts_prefixed_ciphertext_to_plaintext(): void
    {
        $plain = 'to-be-decrypted';
        $prefixed = 'dynenc:v1:'.app('encrypter')->encryptString($plain);

        // Insert encrypted value directly into DB (bypass casts/trait)
        DB::table('decrypt_models')->insert([
            'name' => 'X',
            'secret_cast' => $prefixed,
            'secret_trait' => $prefixed,
            'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:decrypt', ['--model' => [DecryptModel::class]])
            ->expectsOutputToContain('Decrypted 1 records')
            ->assertExitCode(0);

        $row = DB::table('decrypt_models')->first();
        $this->assertSame($plain, $row->secret_cast);
        $this->assertSame($plain, $row->secret_trait);

        $model = DecryptModel::first();
        $this->assertSame($plain, $model->secret_cast);
        $this->assertSame($plain, $model->secret_trait);
    }

    public function test_dry_run_does_not_change_data(): void
    {
        $plain = 'stay-encrypted';
        $prefixed = 'dynenc:v1:'.app('encrypter')->encryptString($plain);

        DB::table('decrypt_models')->insert([
            'secret_cast' => $prefixed,
            'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:decrypt', [
            '--model' => [DecryptModel::class],
            '--dry-run' => true,
        ])->expectsOutputToContain('Decrypted 1 records for Sneakyx\LaravelDynamicEncryption\Tests\Feature\DecryptModel (dry run)')
            ->assertExitCode(0);

        $this->assertSame($prefixed, DB::table('decrypt_models')->first()->secret_cast);
    }

    public function test_field_filter_only_processes_selected_fields(): void
    {
        $plainA = 'alpha';
        $plainB = 'beta';
        $prefixedA = 'dynenc:v1:'.app('encrypter')->encryptString($plainA);
        $prefixedB = 'dynenc:v1:'.app('encrypter')->encryptString($plainB);

        DB::table('decrypt_models')->insert([
            'secret_cast' => $prefixedA,
            'secret_trait' => $prefixedB,
            'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:decrypt', [
            '--model' => [DecryptModel::class],
            '--field' => ['secret_cast'],
        ])->assertExitCode(0);

        $row = DB::table('decrypt_models')->first();
        $this->assertSame($plainA, $row->secret_cast);
        $this->assertSame($prefixedB, $row->secret_trait); // untouched
    }
}

class DecryptModel extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'decrypt_models';

    protected $casts = [
        'secret_cast' => EncryptedNullableCast::class,
        'secret_trait' => EncryptedNullableCast::class,
    ];
}

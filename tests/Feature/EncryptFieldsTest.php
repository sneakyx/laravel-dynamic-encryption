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

class EncryptFieldsTest extends Orchestra
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

        Schema::create('encrypt_models', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('secret_cast')->nullable();
            $table->text('secret_trait')->nullable();
            $table->timestamps();
        });

        $this->initializeDynamicEncryptionKeyForTests();
    }

    public function test_it_encrypts_plaintext_with_prefix(): void
    {
        $plain = 'needs-encryption';

        DB::table('encrypt_models')->insert([
            'name' => 'Y',
            'secret_cast' => $plain,
            'secret_trait' => $plain,
            'updated_at' => now(),
        ]);

        $this->artisan('encrypt:encrypt', ['--model' => [EncryptModel::class]])
            ->expectsOutputToContain('Encrypted 1 records')
            ->assertExitCode(0);

        $row = DB::table('encrypt_models')->first();
        $this->assertStringStartsWith('dynenc:v1:', $row->secret_cast);
        $this->assertStringStartsWith('dynenc:v1:', $row->secret_trait);

        $model = EncryptModel::first();
        $this->assertSame($plain, $model->secret_cast);
        $this->assertSame($plain, $model->secret_trait);
    }

    public function test_dry_run_does_not_change_data(): void
    {
        $plain = 'dry-run-plaintext';

        DB::table('encrypt_models')->insert([
            'secret_cast' => $plain,
            'updated_at' => now(),
        ]);

        $this->artisan('encrypt:encrypt', [
            '--model' => [EncryptModel::class],
            '--dry-run' => true,
        ])->expectsOutputToContain('Encrypted 1 records for Sneakyx\LaravelDynamicEncryption\Tests\Feature\EncryptModel (dry run)')
            ->assertExitCode(0);

        $this->assertSame($plain, DB::table('encrypt_models')->first()->secret_cast);
    }

    public function test_field_filter_only_processes_selected_fields(): void
    {
        $plainA = 'alpha-plain';
        $plainB = 'beta-plain';

        DB::table('encrypt_models')->insert([
            'secret_cast' => $plainA,
            'secret_trait' => $plainB,
            'updated_at' => now(),
        ]);

        $this->artisan('encrypt:encrypt', [
            '--model' => [EncryptModel::class],
            '--field' => ['secret_cast'],
        ])->assertExitCode(0);

        $row = DB::table('encrypt_models')->first();
        $this->assertStringStartsWith('dynenc:v1:', $row->secret_cast);
        $this->assertSame($plainB, $row->secret_trait); // untouched
    }
}

class EncryptModel extends \Illuminate\Database\Eloquent\Model
{
    use DynamicEncryptable;

    protected $table = 'encrypt_models';

    protected $casts = [
        'secret_cast' => EncryptedNullableCast::class,
    ];

    protected array $encryptable = ['secret_trait'];
}

<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Services\StorageManager;

class RotateTest extends Orchestra
{
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
        $app['config']->set('dynamic-encryption.chunk', 50);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_secrets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('token')->nullable();
            $table->timestamps();
        });
    }

    public function test_rotation_reencrypts_data_with_model_option(): void
    {
        $sm = $this->app->make(StorageManager::class);
        $rawOld = random_bytes(32);
        $rawNew = random_bytes(32);

        $bundle = [
            'password' => 'base64:'.base64_encode($rawOld),
        ];
        $this->app['cache']->store('array')->forever('dynamic_encryption_key', $bundle);

        // Create data with old key
        $oldEncrypter = $sm->makeEncrypterFromKeyString($bundle['password']);
        $plain = 'alpha';
        $cipher = 'dynenc:v1:'.$oldEncrypter->encryptString($plain);

        DB::table('test_secrets')->insert([
            'name' => 'A',
            'token' => $cipher,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run command with --model (interactive answers)
        $this->artisan('dynamic-encrypter:rotate', ['--model' => [TestSecret::class]])
            ->expectsQuestion('Old password (Enter for cache value)', '')
            ->expectsQuestion('New password', 'base64:'.base64_encode($rawNew))
            ->expectsConfirmation('Start re-encryption now?', 'yes')
            ->assertExitCode(0);

        // Ensure ciphertext changed but plaintext still decrypts to same
        $after = TestSecret::query()->first();
        $this->assertNotSame($cipher, $after->getOriginal('token'));
        $this->assertSame($plain, $after->token);
    }

    public function test_command_fails_without_all_or_model(): void
    {
        $this->artisan('dynamic-encrypter:rotate')->expectsOutputToContain('No models specified')->assertExitCode(\Illuminate\Console\Command::FAILURE);
    }

    public function test_all_discovers_encryptable_models(): void
    {
        // Put a model file into app/Models dynamically
        $modelsDir = app_path('Models');
        if (! is_dir($modelsDir)) {
            mkdir($modelsDir, 0777, true);
        }
        $modelPath = $modelsDir.DIRECTORY_SEPARATOR.'AllSecret.php';
        file_put_contents($modelPath, <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast;
class AllSecret extends Model {
    protected $table = 'test_secrets';
    protected $fillable = ['name','token'];
    protected function casts(): array {
        return ['token' => EncryptedNullableCast::class];
    }
}
PHP
        );

        // Include the file so class_exists() returns true in the command
        require_once $modelPath;

        $sm = $this->app->make(StorageManager::class);
        $rawOld = random_bytes(32);
        $rawNew = random_bytes(32);

        $bundle = [
            'password' => 'base64:'.base64_encode($rawOld),
        ];
        $this->app['cache']->store('array')->forever('dynamic_encryption_key', $bundle);

        // Seed with old key
        $oldEncrypter = $sm->makeEncrypterFromKeyString($bundle['password']);
        $plain = 'gamma';
        $cipher = 'dynenc:v1:'.$oldEncrypter->encryptString($plain);

        DB::table('test_secrets')->insert([
            'name' => 'C',
            'token' => $cipher,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run with --all (interactive answers)
        $this->artisan('dynamic-encrypter:rotate', ['--all' => true])
            ->expectsQuestion('Old password (Enter for cache value)', '')
            ->expectsQuestion('New password', 'base64:'.base64_encode($rawNew))
            ->expectsConfirmation('Start re-encryption now?', 'yes')
            ->expectsOutputToContain('Re-encrypting model: App\Models\AllSecret')
            ->assertExitCode(0);

        $this->assertSame('gamma', \App\Models\AllSecret::query()->first()->token);

        // Cleanup created file
        @unlink($modelPath);
    }

    public function test_aborts_when_cache_password_missing(): void
    {
        // Ensure cache bundle is missing
        $this->app['cache']->store('array')->forget('dynamic_encryption_key');

        $this->artisan('dynamic-encrypter:rotate', ['--model' => [TestSecret::class]])
            ->expectsOutputToContain('No password found in cache')
            ->assertExitCode(\Illuminate\Console\Command::FAILURE);
    }

    public function test_aborts_on_confirmation_no_without_changes(): void
    {
        $sm = $this->app->make(StorageManager::class);
        $rawOld = random_bytes(32);
        $rawNew = random_bytes(32);

        $bundle = [
            'password' => 'base64:'.base64_encode($rawOld),
        ];
        $this->app['cache']->store('array')->forever('dynamic_encryption_key', $bundle);

        // Seed one row encrypted with old key
        $oldEncrypter = $sm->makeEncrypterFromKeyString($bundle['password']);
        $plain = 'beta';
        $cipher = 'dynenc:v1:'.$oldEncrypter->encryptString($plain);
        DB::table('test_secrets')->insert([
            'name' => 'B', 'token' => $cipher, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:rotate', ['--model' => [TestSecret::class]])
            ->expectsQuestion('Old password (Enter for cache value)', '')
            ->expectsQuestion('New password', 'base64:'.base64_encode($rawNew))
            ->expectsConfirmation('Start re-encryption now?', 'no')
            ->assertExitCode(0);

        $row = DB::table('test_secrets')->first();
        $this->assertSame($cipher, $row->token);
    }

    public function test_cache_updated_when_new_differs_and_hint_printed(): void
    {
        $sm = $this->app->make(StorageManager::class);
        $rawOld = random_bytes(32);
        $rawNew = random_bytes(32);

        $bundle = [
            'password' => 'base64:'.base64_encode($rawOld),
        ];
        $this->app['cache']->store('array')->forever('dynamic_encryption_key', $bundle);

        // Seed with old
        $oldEncrypter = $sm->makeEncrypterFromKeyString($bundle['password']);
        $cipher = 'dynenc:v1:'.$oldEncrypter->encryptString('delta');
        DB::table('test_secrets')->insert([
            'name' => 'D', 'token' => $cipher, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $newString = 'base64:'.base64_encode($rawNew);
        $this->artisan('dynamic-encrypter:rotate', ['--model' => [TestSecret::class]])
            ->expectsQuestion('Old password (Enter for cache value)', '')
            ->expectsQuestion('New password', $newString)
            ->expectsConfirmation('Start re-encryption now?', 'yes')
            ->expectsOutputToContain('Note: The new password has been updated in the cache')
            ->assertExitCode(0);

        $bundleAfter = $this->app['cache']->store('array')->get('dynamic_encryption_key');
        $this->assertSame($newString, $bundleAfter['password'] ?? null);
    }

    public function test_dry_run_does_not_write(): void
    {
        $sm = $this->app->make(StorageManager::class);
        $rawOld = random_bytes(32);
        $rawNew = random_bytes(32);

        $bundle = [
            'password' => 'base64:'.base64_encode($rawOld),
        ];
        $this->app['cache']->store('array')->forever('dynamic_encryption_key', $bundle);

        $cipher = 'dynenc:v1:'.$sm->makeEncrypterFromKeyString($bundle['password'])->encryptString('epsilon');
        DB::table('test_secrets')->insert([
            'name' => 'E', 'token' => $cipher, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('dynamic-encrypter:rotate', ['--model' => [TestSecret::class], '--dry-run' => true])
            ->expectsQuestion('Old password (Enter for cache value)', '')
            ->expectsQuestion('New password', 'base64:'.base64_encode($rawNew))
            ->expectsConfirmation('Start re-encryption now?', 'yes')
            ->expectsOutputToContain('Would re-encrypt')
            ->assertExitCode(0);

        $row = DB::table('test_secrets')->first();
        $this->assertSame($cipher, $row->token);
    }
}

class TestSecret extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'test_secrets';

    protected $fillable = ['name', 'token'];

    protected function casts(): array
    {
        return [
            'token' => \Sneakyx\LaravelDynamicEncryption\Casts\EncryptedNullableCast::class,
        ];
    }
}

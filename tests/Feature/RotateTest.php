<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Feature;

use App\Models\AllSecret;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Services\StorageManager;
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptable;

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
            'password' => 'base64:'.base64_encode($rawNew),
            'old_password' => 'base64:'.base64_encode($rawOld),
        ];
        $this->app['cache']->forever('dynamic_encryption_key', $bundle);

        // Create data with old key
        $oldEncrypter = $sm->makeEncrypterFromKeyString($bundle['old_password']);
        $plain = 'alpha';
        $cipher = 'dynenc:v1:'.$oldEncrypter->encryptString($plain);

        DB::table('test_secrets')->insert([
            'name' => 'A',
            'token' => $cipher,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run command with --model
        $this->artisan('encrypt:rotate', ['--model' => [TestSecret::class]])->assertExitCode(0);

        // Ensure ciphertext changed but plaintext still decrypts to same
        $after = TestSecret::query()->first();
        $this->assertNotSame($cipher, $after->getOriginal('token'));
        $this->assertSame($plain, $after->token);
    }

    public function test_command_fails_without_all_or_model(): void
    {
        $this->artisan('encrypt:rotate')->expectsOutputToContain('No models specified')->assertExitCode(\Illuminate\Console\Command::FAILURE);
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
use Sneakyx\LaravelDynamicEncryption\Traits\DynamicEncryptable;
class AllSecret extends Model {
    use DynamicEncryptable;
    protected $table = 'test_secrets';
    protected $fillable = ['name','token'];
    protected array $encryptable = ['token'];
}
PHP
        );

        $sm = $this->app->make(StorageManager::class);
        $rawOld = random_bytes(32);
        $rawNew = random_bytes(32);

        $bundle = [
            'password' => 'base64:'.base64_encode($rawNew),
            'old_password' => 'base64:'.base64_encode($rawOld),
        ];
        $this->app['cache']->forever('dynamic_encryption_key', $bundle);

        // Seed with old key
        $oldEncrypter = $sm->makeEncrypterFromKeyString($bundle['old_password']);
        $plain = 'gamma';
        $cipher = 'dynenc:v1:'.$oldEncrypter->encryptString($plain);

        DB::table('test_secrets')->insert([
            'name' => 'C',
            'token' => $cipher,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run with --all
        $this->artisan('encrypt:rotate', ['--all' => true])->assertExitCode(0);

        // Cleanup created file
        @unlink($modelPath);

        $this->assertSame('gamma', AllSecret::query()->first()->token);
    }
}

class TestSecret extends \Illuminate\Database\Eloquent\Model
{
    use DynamicEncryptable;

    protected $table = 'test_secrets';

    protected $fillable = ['name', 'token'];

    protected array $encryptable = ['token'];
}

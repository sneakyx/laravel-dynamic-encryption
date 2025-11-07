<?php

namespace Sneakyx\LaravelDynamicEncryption\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Sneakyx\LaravelDynamicEncryption\Providers\DynamicEncryptionServiceProvider;
use Sneakyx\LaravelDynamicEncryption\Services\StorageManager;
use Sneakyx\LaravelDynamicEncryption\Traits\Encryptable;

class RotateTest extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [DynamicEncryptionServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('dynamic-encryption.storage', 'memcache');
        $app['config']->set('dynamic-encryption.key', 'dynamic_encryption_key');
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
        $raw = random_bytes(32);
        $sm->storeKey('base64:' . base64_encode($raw));

        // Create data
        TestSecret::create(['name' => 'A', 'token' => 'alpha']);
        TestSecret::create(['name' => 'B', 'token' => 'beta']);

        // Capture current ciphertexts
        $before = TestSecret::query()->get()->pluck('token')->all();

        // Run command with --model
        $this->artisan('encrypt:rotate', ['--model' => [TestSecret::class]])->assertExitCode(0);

        // Ensure ciphertext changed but plaintext still decrypts to same
        $after = TestSecret::query()->get();
        $this->assertNotSame($before[0], $after[0]->getOriginal('token'));
        $this->assertNotSame($before[1], $after[1]->getOriginal('token'));

        $this->assertSame('alpha', $after[0]->token);
        $this->assertSame('beta', $after[1]->token);
    }

    public function test_command_fails_without_all_or_model(): void
    {
        $this->artisan('encrypt:rotate')->expectsOutputToContain('No models specified')->assertExitCode(\Illuminate\Console\Command::FAILURE);
    }

    public function test_all_discovers_encryptable_models(): void
    {
        // Put a model file into app/Models dynamically
        $modelsDir = app_path('Models');
        if (!is_dir($modelsDir)) {
            mkdir($modelsDir, 0777, true);
        }
        $modelPath = $modelsDir . DIRECTORY_SEPARATOR . 'AllSecret.php';
        file_put_contents($modelPath, <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Sneakyx\LaravelDynamicEncryption\Traits\Encryptable;
class AllSecret extends Model {
    use Encryptable;
    protected $table = 'test_secrets';
    protected $fillable = ['name','token'];
    protected array $encryptable = ['token'];
}
PHP);

        // Seed
        TestSecret::create(['name' => 'C', 'token' => 'gamma']);

        $sm = $this->app->make(StorageManager::class);
        $sm->storeKey('base64:' . base64_encode(random_bytes(32)));

        // Run with --all
        $this->artisan('encrypt:rotate', ['--all' => true])->assertExitCode(0);

        // Cleanup created file
        @unlink($modelPath);

        $this->assertSame('gamma', TestSecret::query()->first()->token);
    }
}

class TestSecret extends \Illuminate\Database\Eloquent\Model
{
    use Encryptable;
    protected $table = 'test_secrets';
    protected $fillable = ['name', 'token'];
    protected array $encryptable = ['token'];
}

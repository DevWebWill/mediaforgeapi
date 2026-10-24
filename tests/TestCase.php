<?php

namespace Tests;

use App\Models\ApiClient;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    /**
     * Salvaguarda: las pruebas nunca deben tocar la base de datos ni el Redis reales.
     * Se comprueba antes de que RefreshDatabase ejecute las migraciones.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $config = $app['config'];

        if ($config->get('database.default') !== 'sqlite'
            || $config->get('database.connections.sqlite.database') !== ':memory:'
            || $config->get('cache.default') !== 'array'
            || $config->get('queue.default') !== 'sync') {
            fwrite(STDERR, "Entorno de pruebas inseguro: revisa phpunit.xml (DB/cache/cola reales).\n");
            exit(1);
        }

        return $app;
    }

    /**
     * Crea un cliente con una clave válida y devuelve [cliente, cabeceras de autenticación].
     *
     * @param  array<string, mixed>  $attributes
     * @return array{ApiClient, array<string, string>}
     */
    protected function apiClient(array $attributes = []): array
    {
        $client = ApiClient::factory()->create($attributes);
        $issued = app(ApiKeyService::class)->issue($client, 'test');

        return [$client, ['Authorization' => 'Bearer '.$issued->plainTextToken, 'Accept' => 'application/json']];
    }

    protected function fixture(string $name, ?string $clientName = null): UploadedFile
    {
        $path = base_path('tests/Fixtures/media/'.$name);

        // Se copia a un temporal para que la subida no mueva ni altere la fixture original
        $tmp = tempnam(sys_get_temp_dir(), 'mf');
        copy($path, $tmp);

        return new UploadedFile($tmp, $clientName ?? $name, null, null, true);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Console\Command;

class CreateApiKey extends Command
{
    protected $signature = 'mediaforge:key:create
        {client : Id público del cliente (cli_...)}
        {--name=default : Nombre de la clave}
        {--expires-days= : Días hasta la caducidad}';

    protected $description = 'Emite una nueva clave API para un cliente';

    public function handle(ApiKeyService $keys): int
    {
        $client = ApiClient::query()->wherePublicId((string) $this->argument('client'))->first();

        if ($client === null) {
            $this->error('Cliente no encontrado.');

            return self::FAILURE;
        }

        $days = $this->option('expires-days');
        $issued = $keys->issue($client, (string) $this->option('name'), $days !== null ? now()->addDays((int) $days) : null);

        $this->info("Clave {$issued->key->key_id} creada para {$client->public_id}.");
        $this->warn('Guarda este token ahora; no se volverá a mostrar:');
        $this->line($issued->plainTextToken);

        return self::SUCCESS;
    }
}

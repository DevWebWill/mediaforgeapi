<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class ListApiClients extends Command
{
    protected $signature = 'mediaforge:client:list';

    protected $description = 'Lista los clientes API y sus claves (sin secretos)';

    public function handle(): int
    {
        $rows = [];

        foreach (ApiClient::query()->with('apiKeys')->orderBy('id')->get() as $client) {
            foreach ($client->apiKeys as $key) {
                $rows[] = [
                    $client->public_id,
                    $client->name,
                    $client->status->value,
                    $key->key_id,
                    $key->name,
                    $key->isUsable() ? 'activa' : 'inactiva',
                    $key->expires_at?->toDateTimeString() ?? '-',
                    $key->last_used_at?->toDateTimeString() ?? '-',
                ];
            }

            if ($client->apiKeys->isEmpty()) {
                $rows[] = [$client->public_id, $client->name, $client->status->value, '-', '-', '-', '-', '-'];
            }
        }

        $this->table(['Cliente', 'Nombre', 'Estado', 'Key ID', 'Clave', 'Uso', 'Caduca', 'Último uso'], $rows);

        return self::SUCCESS;
    }
}

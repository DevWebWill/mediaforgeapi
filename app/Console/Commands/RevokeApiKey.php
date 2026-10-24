<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Console\Command;

class RevokeApiKey extends Command
{
    protected $signature = 'mediaforge:key:revoke {key_id : Identificador público de la clave}';

    protected $description = 'Revoca una clave API de forma inmediata';

    public function handle(ApiKeyService $keys): int
    {
        $key = ApiKey::query()->where('key_id', (string) $this->argument('key_id'))->first();

        if ($key === null) {
            $this->error('Clave no encontrada.');

            return self::FAILURE;
        }

        $keys->revoke($key);
        $this->info("Clave {$key->key_id} revocada.");

        return self::SUCCESS;
    }
}

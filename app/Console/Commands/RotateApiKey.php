<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Console\Command;

class RotateApiKey extends Command
{
    protected $signature = 'mediaforge:key:rotate
        {key_id : Identificador público de la clave}
        {--grace-minutes=0 : Minutos durante los que la clave anterior seguirá funcionando}';

    protected $description = 'Emite una clave nueva y retira la anterior';

    public function handle(ApiKeyService $keys): int
    {
        $key = ApiKey::query()->with('apiClient')->where('key_id', (string) $this->argument('key_id'))->first();

        if ($key === null || ! $key->isUsable()) {
            $this->error('Clave no encontrada o ya inactiva.');

            return self::FAILURE;
        }

        $issued = $keys->rotate($key, max(0, (int) $this->option('grace-minutes')));

        $this->info("Nueva clave {$issued->key->key_id}; la clave {$key->key_id} queda retirada.");
        $this->warn('Guarda este token ahora; no se volverá a mostrar:');
        $this->line($issued->plainTextToken);

        return self::SUCCESS;
    }
}

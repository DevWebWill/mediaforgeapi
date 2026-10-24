<?php

namespace App\Console\Commands;

use App\Enums\JobPriority;
use App\Models\ApiClient;
use App\Services\ApiKeys\ApiKeyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateApiClient extends Command
{
    protected $signature = 'mediaforge:client:create
        {name : Nombre descriptivo del cliente}
        {--key-name=default : Nombre de la clave inicial}
        {--rate-limit= : Peticiones por minuto}
        {--max-active-jobs= : Trabajos activos simultáneos}
        {--quota-mb= : Cuota de almacenamiento en MB}
        {--max-upload-mb= : Tamaño máximo por archivo en MB}
        {--max-priority= : Prioridad máxima (low|normal|high)}';

    protected $description = 'Crea un cliente API y emite su primera clave';

    public function handle(ApiKeyService $keys): int
    {
        $maxPriority = $this->option('max-priority');
        if ($maxPriority !== null && JobPriority::tryFrom($maxPriority) === null) {
            $this->error('Prioridad no válida. Valores: '.implode(', ', JobPriority::values()));

            return self::INVALID;
        }

        $mb = fn (?string $v): ?int => $v !== null ? (int) $v * 1024 * 1024 : null;
        $int = fn (?string $v): ?int => $v !== null ? (int) $v : null;

        [$client, $issued] = DB::transaction(function () use ($keys, $maxPriority, $mb, $int) {
            $client = ApiClient::create([
                'name' => $this->argument('name'),
                'rate_limit_per_minute' => $int($this->option('rate-limit')),
                'max_active_jobs' => $int($this->option('max-active-jobs')),
                'storage_quota_bytes' => $mb($this->option('quota-mb')),
                'max_upload_bytes' => $mb($this->option('max-upload-mb')),
                'max_priority' => $maxPriority,
            ]);

            return [$client, $keys->issue($client, (string) $this->option('key-name'))];
        });

        $this->info("Cliente creado: {$client->public_id}");
        $this->line("Clave: {$issued->key->key_id}");
        $this->newLine();
        $this->warn('Guarda este token ahora; no se volverá a mostrar:');
        $this->line($issued->plainTextToken);

        return self::SUCCESS;
    }
}

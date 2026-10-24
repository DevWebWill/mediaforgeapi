<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\ApiClient;
use App\Models\MediaJob;
use Illuminate\Console\Command;

class ListFailedJobs extends Command
{
    protected $signature = 'mediaforge:jobs:failed
        {--client= : Public id del cliente}
        {--code= : Filtra por código de error}
        {--hours=24 : Antigüedad máxima en horas}
        {--limit=50}';

    protected $description = 'Lista los trabajos fallidos recientes con su error';

    public function handle(): int
    {
        $query = MediaJob::query()
            ->with('apiClient:id,public_id,name')
            ->where('status', JobStatus::Failed)
            ->where('finished_at', '>=', now()->subHours((int) $this->option('hours')))
            ->orderByDesc('id')
            ->limit((int) $this->option('limit'));

        if ($this->option('client')) {
            $client = ApiClient::query()->where('public_id', $this->option('client'))->first();

            if ($client === null) {
                $this->error('Cliente no encontrado.');

                return self::FAILURE;
            }

            $query->where('api_client_id', $client->id);
        }

        if ($this->option('code')) {
            $query->where('error_code', $this->option('code'));
        }

        $rows = $query->get()->map(fn (MediaJob $job): array => [
            $job->public_id,
            $job->apiClient->name,
            $job->operation,
            $job->attempts.'/'.$job->max_attempts,
            $job->error_code,
            mb_strimwidth((string) $job->error_message, 0, 70, '…'),
            $job->finished_at?->toDateTimeString(),
        ]);

        $this->table(['Trabajo', 'Cliente', 'Operación', 'Intentos', 'Código', 'Mensaje', 'Finalizado'], $rows);

        return self::SUCCESS;
    }
}

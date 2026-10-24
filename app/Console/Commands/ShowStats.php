<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\MediaJob;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Throwable;

class ShowStats extends Command
{
    protected $signature = 'mediaforge:stats {--hours=24 : Ventana de tiempo en horas} {--json : Salida en JSON}';

    protected $description = 'Métricas operativas: trabajos, tiempos, errores, colas y webhooks';

    public function handle(): int
    {
        $since = now()->subHours((int) $this->option('hours'));

        $byStatus = MediaJob::query()->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)->all();

        $finished = MediaJob::query()->where('status', JobStatus::Completed)->where('finished_at', '>=', $since);

        $byOperation = (clone $finished)
            ->selectRaw('operation, count(*) as total, avg(processing_ms) as avg_ms, max(processing_ms) as max_ms')
            ->groupBy('operation')->get()
            ->mapWithKeys(fn ($row) => [$row->operation => [
                'completed' => (int) $row->total,
                'avg_processing_ms' => (int) round((float) $row->avg_ms),
                'max_processing_ms' => (int) $row->max_ms,
            ]])->all();

        $errors = MediaJob::query()->where('status', JobStatus::Failed)->where('finished_at', '>=', $since)
            ->selectRaw('error_code, count(*) as total')->groupBy('error_code')->pluck('total', 'error_code')
            ->map(fn ($v) => (int) $v)->all();

        $queues = [];
        foreach ([...array_values((array) config('mediaforge.jobs.queues')), (string) config('mediaforge.webhooks.queue')] as $queue) {
            try {
                $queues[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $queues[$queue] = null;
            }
        }

        $webhooks = WebhookDelivery::query()->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)->all();

        $stats = [
            'window_hours' => (int) $this->option('hours'),
            'jobs_by_status' => $byStatus,
            'active_jobs' => MediaJob::query()->active()->count(),
            'completed_by_operation' => $byOperation,
            'failed_by_code' => $errors,
            'deadlines_missed' => (clone $finished)->where('deadline_missed_by_seconds', '>', 0)->count(),
            'queue_sizes' => $queues,
            'webhook_deliveries' => $webhooks,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Últimas {$stats['window_hours']} h · trabajos activos: {$stats['active_jobs']} · plazos incumplidos: {$stats['deadlines_missed']}");
        $this->table(['Estado', 'Trabajos'], collect($byStatus)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->table(['Operación', 'Completados', 'Media ms', 'Máx ms'], collect($byOperation)->map(fn ($v, $k) => [$k, ...array_values($v)])->values()->all());
        $this->table(['Error', 'Fallidos'], collect($errors)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->table(['Cola', 'Pendientes'], collect($queues)->map(fn ($v, $k) => [$k, $v ?? 'n/d'])->values()->all());
        $this->table(['Webhooks', 'Entregas'], collect($webhooks)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }
}

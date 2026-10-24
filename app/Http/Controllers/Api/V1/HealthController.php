<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Estado operativo de las dependencias. No expone versiones, rutas, hosts ni errores:
 * solo "ok" / "fail" por componente.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'queue' => $this->check(fn () => config('queue.default') !== 'redis' || Redis::connection()->ping()),
            'storage' => $this->check(fn () => $this->checkStorage()),
            'ffmpeg' => $this->check(fn () => is_executable((string) config('mediaforge.binaries.ffmpeg'))),
            'ffprobe' => $this->check(fn () => is_executable((string) config('mediaforge.binaries.ffprobe'))),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            return $probe() === false ? 'fail' : 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }

    private function checkStorage(): bool
    {
        $disk = Storage::disk((string) config('mediaforge.storage.disk'));
        $probe = '.health/'.Str::random(16);

        $disk->put($probe, 'ok');
        $disk->delete($probe);

        $root = config('filesystems.disks.'.config('mediaforge.storage.disk').'.root');
        $free = is_string($root) ? @disk_free_space($root) : false;

        return $free === false || $free >= (int) config('mediaforge.storage.min_free_bytes');
    }
}

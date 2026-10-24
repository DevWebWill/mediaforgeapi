<?php

use App\Media\Operations;

return [

    /*
    | Motor de procesamiento: "ffmpeg" (real) o "simulated" (no ejecuta FFmpeg;
    | útil en pruebas del ciclo de vida de los trabajos).
    */
    'processor' => env('MEDIAFORGE_PROCESSOR', 'ffmpeg'),

    'binaries' => [
        'ffmpeg' => env('FFMPEG_BINARY', '/usr/bin/ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', '/usr/bin/ffprobe'),
    ],

    'ffmpeg' => [
        // 0 = FFmpeg decide según los núcleos disponibles
        'threads' => (int) env('MEDIAFORGE_FFMPEG_THREADS', 0),
        // Baja la prioridad de CPU de FFmpeg para no degradar la API
        'nice' => (bool) env('MEDIAFORGE_FFMPEG_NICE', true),
        // Debe ser menor que jobs.timeout para que el trabajo pueda cerrarse limpiamente
        'timeout' => (int) env('MEDIAFORGE_FFMPEG_TIMEOUT', 3300),
        'probe_timeout' => (int) env('MEDIAFORGE_FFPROBE_TIMEOUT', 60),
        'max_output_bytes' => (int) env('MEDIAFORGE_MAX_OUTPUT_MB', 4096) * 1024 * 1024,
        // Contenedores que ffprobe/ffmpeg pueden abrir (demuxers); nunca protocolos de red
        'allowed_demuxers' => ['mov', 'matroska', 'avi', 'mpeg', 'mpegts', 'mp3', 'aac', 'wav', 'ogg', 'flac'],
        'heartbeat_seconds' => 5,
    ],

    'limits' => [
        // Duración máxima de un archivo de entrada (se comprueba al subir y al procesar)
        'max_duration_seconds' => (int) env('MEDIAFORGE_MAX_DURATION_SECONDS', 14400),
        'max_input_pixels' => (int) env('MEDIAFORGE_MAX_INPUT_PIXELS', 4096 * 2304),
    ],

    'storage' => [
        'disk' => env('MEDIAFORGE_DISK', 'media'),
        // Espacio libre mínimo que debe quedar en disco tras aceptar una subida
        'min_free_bytes' => (int) env('MEDIAFORGE_MIN_FREE_MB', 2048) * 1024 * 1024,
    ],

    // Retención: al caducar, la limpieza programada borra el archivo (0 = sin caducidad)
    'retention' => [
        'originals_hours' => (int) env('MEDIAFORGE_RETENTION_ORIGINALS_HOURS', 168),
        'results_hours' => (int) env('MEDIAFORGE_RETENTION_RESULTS_HOURS', 168),
        // Directorios temporales huérfanos (worker caído) más antiguos que esto se eliminan
        'tmp_hours' => (int) env('MEDIAFORGE_RETENTION_TMP_HOURS', 6),
    ],

    'uploads' => [
        'max_bytes' => (int) env('MEDIAFORGE_MAX_UPLOAD_MB', 2048) * 1024 * 1024,
        // Segundos iniciales que se decodifican al subir para detectar archivos dañados (0 = desactivado)
        'decode_check_seconds' => (float) env('MEDIAFORGE_UPLOAD_DECODE_CHECK_SECONDS', 3),

        // Extensión permitida => tipos MIME aceptados tras detectar la firma real del archivo
        'formats' => [
            'mp4' => ['video/mp4', 'video/quicktime', 'audio/mp4', 'video/3gpp'],
            'm4v' => ['video/mp4', 'video/x-m4v', 'video/quicktime'],
            'mov' => ['video/quicktime', 'video/mp4'],
            'mkv' => ['video/x-matroska', 'video/webm'],
            'webm' => ['video/webm', 'video/x-matroska', 'audio/webm'],
            'avi' => ['video/x-msvideo', 'video/avi'],
            'mpeg' => ['video/mpeg'],
            'mpg' => ['video/mpeg'],
            'mp3' => ['audio/mpeg', 'audio/mp3'],
            'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
            'aac' => ['audio/aac', 'audio/x-hx-aac-adts'],
            'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
            'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
            'flac' => ['audio/flac', 'audio/x-flac'],
        ],
    ],

    'jobs' => [
        'max_attempts' => (int) env('MEDIAFORGE_JOB_MAX_ATTEMPTS', 3),
        // Debe ser menor que QUEUE_TIMEOUT del worker y que REDIS_QUEUE_RETRY_AFTER
        'timeout' => (int) env('MEDIAFORGE_JOB_TIMEOUT', 3500),
        // Segundos de espera antes de cada reintento (errores transitorios)
        'backoff' => [30, 120, 300],
        // Un trabajo "processing" sin heartbeat durante este tiempo se considera interrumpido
        'stale_after_seconds' => (int) env('MEDIAFORGE_JOB_STALE_AFTER', 300),
        'idempotency_ttl_hours' => (int) env('MEDIAFORGE_IDEMPOTENCY_TTL_HOURS', 24),

        // Trabajos FFmpeg simultáneos en todo el servicio (todas las réplicas de workers)
        'max_concurrent' => (int) env('MEDIAFORGE_MAX_CONCURRENT_JOBS', 1),
        // Espera antes de volver a intentar cuando no hay hueco libre
        'concurrency_wait_seconds' => 15,
        // Ventana total durante la que la cola puede reintentar un mensaje (esperas incluidas)
        'retry_window_hours' => (int) env('MEDIAFORGE_JOB_RETRY_WINDOW_HOURS', 24),

        // Recuperación (mediaforge:jobs:recover): mensajes perdidos o workers caídos
        'recover' => [
            'pending_after_seconds' => 120,
            'queued_after_seconds' => (int) env('MEDIAFORGE_RECOVER_QUEUED_AFTER', 7200),
        ],

        // Estimación de finalización: muestras mínimas por operación para estimar
        'estimate_min_samples' => 5,

        'queues' => [
            'high' => 'media-high',
            'normal' => 'media',
            'low' => 'media-low',
        ],
    ],

    'webhooks' => [
        'queue' => env('MEDIAFORGE_WEBHOOK_QUEUE', 'webhooks'),
        'max_endpoints_per_client' => 10,
        // Intentos totales por entrega y espera (s) antes de cada reintento
        'max_attempts' => 6,
        'backoff' => [10, 60, 300, 1800, 7200],
        'connect_timeout' => 5,
        'timeout' => 10,
        // Antigüedad máxima aceptable de la firma (documentada para los receptores)
        'signature_tolerance_seconds' => 300,
        'rotation_grace_minutes' => 1440,
        // HTTP sin TLS solo fuera de producción (p. ej. en la red Docker local)
        'allow_http' => (bool) env('MEDIAFORGE_WEBHOOK_ALLOW_HTTP', env('APP_ENV') !== 'production'),
        'allowed_ports' => array_map('intval', explode(',', (string) env('MEDIAFORGE_WEBHOOK_ALLOWED_PORTS', '443,80,8443'))),
        // Hosts internos de confianza que pueden resolver a IPs privadas
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEDIAFORGE_WEBHOOK_ALLOWED_HOSTS', ''))))),
    ],

    // Límites por defecto de un cliente API (sobrescribibles por cliente)
    'client_defaults' => [
        'rate_limit_per_minute' => (int) env('MEDIAFORGE_RATE_LIMIT_PER_MINUTE', 120),
        'max_active_jobs' => (int) env('MEDIAFORGE_MAX_ACTIVE_JOBS', 20),
        'storage_quota_bytes' => (int) env('MEDIAFORGE_STORAGE_QUOTA_MB', 51200) * 1024 * 1024,
        'max_priority' => 'normal',
    ],

    'operations' => [
        Operations\AnalyzeOperation::class,
        Operations\CompressOperation::class,
        Operations\ConvertOperation::class,
        Operations\ResizeOperation::class,
        Operations\TrimOperation::class,
        Operations\ExtractAudioOperation::class,
        Operations\ThumbnailOperation::class,
    ],

    /*
    | Perfiles: conjuntos versionados de parámetros para una operación.
    | Son puntos de partida razonables, no garantizan el cumplimiento de las
    | especificaciones vigentes de cada plataforma, que el cliente debe validar.
    */
    'profiles' => [
        'generic-web' => [
            'version' => 1,
            'description' => 'H.264/AAC en MP4 optimizado para reproducción web progresiva.',
            'operations' => ['compress', 'convert'],
            'parameters' => [
                'format' => 'mp4',
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
                'crf' => 23,
                'preset' => 'medium',
                'max_width' => 1920,
                'max_height' => 1920,
                'audio_bitrate_kbps' => 128,
            ],
        ],
        'instagram-reels' => [
            'version' => 1,
            'description' => 'Vertical 9:16 1080x1920, H.264/AAC, 30 fps.',
            'operations' => ['resize'],
            'parameters' => [
                'width' => 1080,
                'height' => 1920,
                'strategy' => 'crop',
                'format' => 'mp4',
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
                'crf' => 21,
                'preset' => 'medium',
                'fps' => 30,
                'audio_bitrate_kbps' => 128,
            ],
            'max_duration_seconds' => 900,
        ],
        'tiktok' => [
            'version' => 1,
            'description' => 'Vertical 9:16 1080x1920, H.264/AAC, 30 fps.',
            'operations' => ['resize'],
            'parameters' => [
                'width' => 1080,
                'height' => 1920,
                'strategy' => 'crop',
                'format' => 'mp4',
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
                'crf' => 21,
                'preset' => 'medium',
                'fps' => 30,
                'audio_bitrate_kbps' => 128,
            ],
            'max_duration_seconds' => 600,
        ],
        'youtube-shorts' => [
            'version' => 1,
            'description' => 'Vertical 9:16 1080x1920, H.264/AAC.',
            'operations' => ['resize'],
            'parameters' => [
                'width' => 1080,
                'height' => 1920,
                'strategy' => 'pad',
                'format' => 'mp4',
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
                'crf' => 21,
                'preset' => 'medium',
                'audio_bitrate_kbps' => 192,
            ],
            'max_duration_seconds' => 180,
        ],
        'youtube-standard' => [
            'version' => 1,
            'description' => 'Horizontal hasta 1920x1080, H.264/AAC, sin forzar FPS.',
            'operations' => ['compress', 'convert'],
            'parameters' => [
                'format' => 'mp4',
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
                'crf' => 20,
                'preset' => 'medium',
                'max_width' => 1920,
                'max_height' => 1080,
                'audio_bitrate_kbps' => 192,
            ],
        ],
    ],

];

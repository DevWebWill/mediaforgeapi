# MediaForge API

Servicio independiente de procesamiento multimedia (FFmpeg) con Laravel 12 y colas asíncronas.

## Stack

| Servicio    | Descripción                                   |
|-------------|-----------------------------------------------|
| `app`       | PHP 8.5 FPM + FFmpeg                          |
| `nginx`     | Servidor web (`http://localhost:8081`)        |
| `mysql`     | MySQL 8.4                                     |
| `redis`     | Redis 8 (colas y caché)                       |
| `queue`     | Worker de colas de Laravel (conexión `redis`) con FFmpeg, CPU/RAM limitadas |
| `queue-webhooks` | Worker ligero para la cola `webhooks`    |
| `scheduler` | `php artisan schedule:work`                   |

## Puesta en marcha

```bash
cp .env.example .env   # cambiar DB_PASSWORD, DB_ROOT_PASSWORD y REDIS_PASSWORD
docker compose up -d --build
```

Al arrancar, `app` instala dependencias (si falta `vendor/`), genera `APP_KEY` si está vacía y ejecuta las migraciones (`RUN_MIGRATIONS=true`).

Comprobar estado: `curl http://localhost:8081/up`

## Comandos habituales

```bash
docker compose exec app php artisan <comando>
docker compose exec app composer <comando>
docker compose logs -f queue
docker compose exec app php artisan queue:restart   # tras cambiar código de los jobs
docker compose up -d --scale queue=3         # más workers en paralelo
docker compose down                          # parar (añadir -v para borrar datos)
```

## API (`/api/v1`)

Autenticación: `Authorization: Bearer <api-key>`. Cada cliente solo ve sus propios archivos y trabajos.

```bash
docker compose exec app php artisan mediaforge:client:create "Aplicación cliente"   # muestra la clave una sola vez
docker compose exec app php artisan mediaforge:client:list
docker compose exec app php artisan mediaforge:key:rotate <key_id> --grace-minutes=60
docker compose exec app php artisan mediaforge:key:revoke <key_id>
docker compose exec app php artisan mediaforge:client:status <cli_...> suspended
```

| Método | Ruta                          | Descripción                                        |
|--------|-------------------------------|----------------------------------------------------|
| GET    | `/api/v1/health`              | Estado de dependencias (público, 200/503)          |
| GET    | `/api/v1/operations[/{op}]`   | Catálogo de operaciones y parámetros               |
| GET    | `/api/v1/profiles[/{id}]`     | Perfiles versionados                               |
| POST   | `/api/v1/files`               | Subir archivo (`multipart`, campo `file`) → 201    |
| GET    | `/api/v1/files[/{id}]`        | Listar / consultar archivos                        |
| GET    | `/api/v1/files/{id}/download` | Descargar archivo                                  |
| DELETE | `/api/v1/files/{id}`          | Eliminar (409 si lo usa un trabajo activo)         |
| POST   | `/api/v1/jobs`                | Crear trabajo → 202 (`Idempotency-Key` opcional)   |
| GET    | `/api/v1/jobs[/{id}]`         | Listar (filtros `status`, `operation`, `reference`) / consultar |
| POST   | `/api/v1/jobs/{id}/cancel`    | 200 cancelado · 202 cancelación solicitada · 409   |
| GET    | `/api/v1/jobs/{id}/result`    | Resultado (409 si no está `completed`)             |
| GET    | `/api/v1/jobs/{id}/logs`      | Historial de estados                               |
| POST   | `/api/v1/webhooks`            | Registrar webhook → 201 (devuelve `secret` una vez) |
| GET/PATCH/DELETE | `/api/v1/webhooks[/{id}]` | Listar / consultar / modificar / eliminar     |
| POST   | `/api/v1/webhooks/{id}/rotate-secret` | Nuevo secreto (el anterior firma 24 h más) |
| POST   | `/api/v1/webhooks/{id}/ping`  | Evento de prueba `webhook.ping`                    |
| GET    | `/api/v1/webhooks/{id}/deliveries` | Historial de entregas                         |
| POST   | `/api/v1/webhooks/{id}/deliveries/{dlv}/redeliver` | Reenviar una entrega          |

Contrato completo: [docs/openapi.yaml](docs/openapi.yaml). Webhooks y verificación de firma: [docs/webhooks.md](docs/webhooks.md).

```bash
curl -H "Authorization: Bearer $KEY" -F file=@video.mp4 http://localhost:8081/api/v1/files
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -H "Idempotency-Key: post-42-video" \
  -d '{"file_id":"file_...","operation":"resize","profile":"tiktok","reference":"post-42"}' \
  http://localhost:8081/api/v1/jobs
```

Errores: `{"error":{"code","message","details?","request_id"}}`. Estados: `pending → queued → processing → completed | failed | cancelled` (`retrying` entre intentos por errores transitorios).

Idempotencia: misma clave + misma petición devuelve el trabajo existente (`Idempotent-Replayed: true`); misma clave con otra petición → 409. Ámbito por cliente, caduca a las 24 h (`MEDIAFORGE_IDEMPOTENCY_TTL_HOURS`).

Los archivos se guardan en `storage/app/media` (privado, no servido por nginx) y caducan según la retención configurada.

Pruebas: `docker compose exec app php artisan test` (sqlite en memoria; abortan si detectan la BD real).

## Procesamiento (FFmpeg)

`MEDIAFORGE_PROCESSOR=ffmpeg` (por defecto). `simulated` solo sirve para pruebas.

Operaciones: `analyze`, `compress`, `convert` (mp4/mov/mkv/webm, h264/h265/vp9, aac/opus), `resize` (fit/crop/pad), `trim` (accurate/fast), `extract_audio` (mp3/m4a) y `thumbnail` (representativa o en un instante; jpg/png/webp). Los parámetros están en `GET /api/v1/operations`. Perfiles: `tiktok`, `instagram-reels`, `youtube-shorts`, `youtube-standard`, `generic-web`. Son puntos de partida y no garantizan el cumplimiento de las normas de cada plataforma.

Las operaciones que recodifican vídeo admiten `max_video_bitrate_kbps` (64–25000,
VBV con buffer de dos segundos), `audio_sample_rate` (44100/48000),
`audio_channels` (1/2) y `deinterlace` (booleano, desactivado por defecto).
`constant_video_bitrate_kbps` (64–25000, solo H.264) activa CBR con relleno,
excluye `max_video_bitrate_kbps` y sustituye CRF; sirve para el tamaño mínimo
de LinkedIn incluso con escenas estáticas.
Permiten normalizar vídeo sin filtros arbitrarios: H.264 MP4 con
bandas (`resize`, `strategy=pad`), 30 FPS y audio AAC estéreo 48 kHz.
No se cambian los valores anteriores si se omiten estos parámetros.
La aplicación consumidora conserva su copia privada, descarga y valida el resultado y elimina
mediante la API los archivos remotos de entrada/salida. La retención y el
comando de limpieza siguen siendo obligatorios para temporales huérfanos.

Validaciones:

- **Al subir**: firma real del archivo, ffprobe limitado a protocolo `file` y demuxers permitidos, y decodificación de los primeros segundos para detectar archivos corruptos o truncados.
- **Al procesar**: duración, resolución, presencia de audio, rangos de tiempo y compatibilidad de códecs.

Seguridad de ejecución:

- FFmpeg se ejecuta sin shell, con `nice`, timeout y tamaño máximo de salida.
- Cada intento usa su propio directorio temporal.
- Los resultados solo se registran si el worker sigue siendo el dueño del trabajo.

Errores del trabajo:

- `input_invalid`, `input_unsupported` e `input_not_found` son definitivos y no se reintentan.
- `processing_timeout` también es definitivo.
- El resto de errores se reintentan con backoff hasta `MEDIAFORGE_JOB_MAX_ATTEMPTS`.

## Robustez y operación

- **Concurrencia**: como máximo `MEDIAFORGE_MAX_CONCURRENT_JOBS` FFmpeg simultáneos en total, aunque se escale `queue`. Los trabajos que esperan hueco no gastan intentos.
- **Recuperación** (`mediaforge:jobs:recover`, cada minuto): reencola trabajos `pending` que no llegaron a Redis, `queued` o `retrying` perdidos, y `processing` sin heartbeat porque el worker murió.
- **Limpieza** (`mediaforge:cleanup`, cada 15 min): borra archivos caducados que no estén en uso, temporales huérfanos y claves de idempotencia caducadas.
- **`scheduled_at`**: el trabajo no se procesa antes de esa fecha.
- **`estimated_completion_at`**: estimación basada en el histórico real y la cola pendiente.

```bash
docker compose exec app php artisan mediaforge:jobs:failed --hours=24 [--client=cli_...] [--code=input_invalid]
docker compose exec app php artisan mediaforge:jobs:retry job_... --reason="FFmpeg actualizado"
docker compose exec app php artisan mediaforge:jobs:recover --dry-run
docker compose exec app php artisan mediaforge:cleanup --dry-run
docker compose exec app php artisan mediaforge:stats --hours=24 [--json]   # métricas: estados, tiempos, errores, colas, webhooks
docker compose exec app php artisan schedule:list
```

Logs estructurados con contexto (`request_id`, `job_id`, operación, estado, intento, duración). Con `LOG_STACK=json` se emiten como una línea JSON por evento en stderr, lo recomendado en producción. Los mensajes internos de FFmpeg nunca llegan a la respuesta pública.

## Webhooks

Eventos `job.completed`, `job.failed` y `job.cancelled`:

- Van firmados con HMAC-SHA256 (`MediaForge-Signature: t=...,v1=...`).
- Se entregan at-least-once y se reintentan con backoff: 10 s, 1 min, 5 min, 30 min y 2 h.
- Están protegidos contra SSRF: la IP se valida en cada envío y no se siguen redirecciones.

Detalles y ejemplos de verificación en PHP y Node: [docs/webhooks.md](docs/webhooks.md).

## Colas

Cada prioridad usa su propia cola (`media-high`, `media`, `media-low`) y el worker `queue` las consume en ese orden. Los webhooks van por la cola `webhooks`, que atiende `queue-webhooks`.

Las colas y la caché usan Redis; los jobs fallidos se guardan en MySQL (`php artisan queue:failed` / `queue:retry`).

| Variable                   | Por defecto     | Uso                                                     |
|----------------------------|-----------------|---------------------------------------------------------|
| `QUEUE_NAMES`              | `media-high,media,media-low,default` | Colas que procesa el worker, por prioridad              |
| `QUEUE_TIMEOUT`            | `3600`          | Segundos máximos por job (`MEDIAFORGE_JOB_TIMEOUT`=3500 debe ser menor) |
| `QUEUE_CPUS` / `QUEUE_MEMORY` | `3` / `4g`   | Límite de recursos del worker FFmpeg                    |
| `REDIS_QUEUE_RETRY_AFTER`  | `3700`          | Debe ser mayor que `QUEUE_TIMEOUT`                      |
| `REDIS_QUEUE_AFTER_COMMIT` | `true`          | Los jobs despachados en una transacción esperan al commit |

Redis tiene contraseña, persistencia AOF y `maxmemory-policy noeviction` para no perder jobs.

## Variables Docker (`.env`)

| Variable           | Por defecto | Uso                                  |
|--------------------|-------------|--------------------------------------|
| `APP_PORT`         | `8081`      | Puerto HTTP en el host               |
| `FORWARD_DB_PORT`  | `3307`      | Puerto MySQL en el host (solo local) |
| `FORWARD_REDIS_PORT` | `6380`    | Puerto Redis en el host (solo local) |
| `DB_ROOT_PASSWORD` | —           | Contraseña root de MySQL             |
| `HOST_UID/GID`     | `1000`      | Usuario de los contenedores PHP      |
| `APP_BUILD_TARGET` | `dev`       | `dev` o `prod`                       |

## Variables de MediaForge

| Variable | Por defecto | Uso |
|----------|-------------|-----|
| `MEDIAFORGE_PROCESSOR` | `ffmpeg` | `ffmpeg` o `simulated` |
| `MEDIAFORGE_MAX_CONCURRENT_JOBS` | `1` | FFmpeg simultáneos (global) |
| `MEDIAFORGE_FFMPEG_THREADS` | `0` | Hilos por FFmpeg (0 = automático) |
| `MEDIAFORGE_FFMPEG_NICE` | `true` | Ejecutar FFmpeg con baja prioridad |
| `MEDIAFORGE_FFMPEG_TIMEOUT` | `3300` | Segundos máximos por ejecución de FFmpeg |
| `MEDIAFORGE_JOB_TIMEOUT` | `3500` | Timeout del job (< `QUEUE_TIMEOUT`) |
| `MEDIAFORGE_JOB_MAX_ATTEMPTS` | `3` | Intentos reales de procesamiento |
| `MEDIAFORGE_JOB_RETRY_WINDOW_HOURS` | `24` | Ventana máxima de reintentos/esperas en cola |
| `MEDIAFORGE_JOB_STALE_AFTER` | `300` | Segundos sin heartbeat para considerar muerto un worker |
| `MEDIAFORGE_MAX_UPLOAD_MB` | `2048` | Tamaño máximo de subida |
| `MEDIAFORGE_MAX_OUTPUT_MB` | `4096` | Tamaño máximo de un resultado |
| `MEDIAFORGE_MAX_DURATION_SECONDS` | `14400` | Duración máxima de entrada |
| `MEDIAFORGE_MAX_INPUT_PIXELS` | `9437184` | Resolución máxima de entrada (4096×2304) |
| `MEDIAFORGE_MIN_FREE_MB` | `2048` | Espacio libre mínimo para aceptar subidas |
| `MEDIAFORGE_RETENTION_ORIGINALS_HOURS` / `_RESULTS_HOURS` / `_TMP_HOURS` | `168` / `168` / `6` | Retención |
| `MEDIAFORGE_RATE_LIMIT_PER_MINUTE` / `MAX_ACTIVE_JOBS` / `STORAGE_QUOTA_MB` | `120` / `20` / `51200` | Límites por defecto de cada cliente |
| `MEDIAFORGE_WEBHOOK_ALLOW_HTTP` | `true` fuera de producción | Permitir webhooks `http` |
| `MEDIAFORGE_WEBHOOK_ALLOWED_PORTS` | `443,80,8443` | Puertos permitidos |
| `MEDIAFORGE_WEBHOOK_ALLOWED_HOSTS` | — | Hosts internos de confianza (omiten la protección SSRF) |

## Integración con otros servicios

Los contenedores comparten la red `mediaforge-network`. Cualquier servicio consumidor puede unirse a ella como red externa y llamar a la API en `http://mediaforge-api`. Para recibir webhooks en un host interno (p. ej. `http://consumer-api/...`), añádelo a `MEDIAFORGE_WEBHOOK_ALLOWED_HOSTS`:

```yaml
networks:
  mediaforge:
    external: true
    name: mediaforge-network
```

## Producción

```bash
APP_BUILD_TARGET=prod APP_IMAGE_TAG=1.0 docker compose build
```

La imagen `prod` incluye el código y las dependencias sin las de desarrollo, con OPcache sin revalidación y `php artisan optimize` al arrancar. En producción no se debe montar el código como volumen.

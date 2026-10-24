# MediaForge API

API en Laravel 12 para el procesamiento de vídeos (FFmpeg) mediante colas de Laravel.

## Stack

| Servicio    | Descripción                                   |
|-------------|-----------------------------------------------|
| `app`       | PHP 8.5 FPM + FFmpeg                          |
| `nginx`     | Servidor web (`http://localhost:8081`)        |
| `mysql`     | MySQL 8.4 (base de datos y colas)             |
| `queue`     | Worker de colas de Laravel (driver `database`) |
| `scheduler` | `php artisan schedule:work`                   |

## Puesta en marcha

```bash
cp .env.example .env
docker compose up -d --build
```

Al arrancar, `app` instala dependencias (si falta `vendor/`), genera `APP_KEY` si está vacía y ejecuta las migraciones (`RUN_MIGRATIONS=true`).

Comprobar estado: `curl http://localhost:8081/up`

## Comandos habituales

```bash
docker compose exec app php artisan <comando>
docker compose exec app composer <comando>
docker compose logs -f queue
docker compose restart queue                 # tras cambiar código de los jobs
docker compose up -d --scale queue=3         # más workers en paralelo
docker compose down                          # parar (añadir -v para borrar datos)
```

## Colas

Los jobs se encolan en la cola `media`:

```php
ProcessVideo::dispatch($video)->onQueue('media');
```

Variables en `.env`: `QUEUE_NAMES`, `QUEUE_TIMEOUT` (segundos por job), `QUEUE_TRIES` y `DB_QUEUE_RETRY_AFTER` (debe ser mayor que `QUEUE_TIMEOUT`).

## Variables Docker (`.env`)

| Variable           | Por defecto | Uso                                  |
|--------------------|-------------|--------------------------------------|
| `APP_PORT`         | `8081`      | Puerto HTTP en el host               |
| `FORWARD_DB_PORT`  | `3307`      | Puerto MySQL en el host              |
| `DB_ROOT_PASSWORD` | —           | Contraseña root de MySQL             |
| `HOST_UID/GID`     | `1000`      | Usuario de los contenedores PHP      |
| `APP_BUILD_TARGET` | `dev`       | `dev` o `prod`                       |

## Integración con otros servicios

Los contenedores comparten la red `mediaforge-network`. Otro proyecto (p. ej. socialaiapi) puede unirse a ella como red externa y llamar a la API en `http://mediaforge-api`:

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

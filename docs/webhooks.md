# Webhooks de MediaForge

MediaForge avisa cuando un trabajo termina, así no hace falta consultar `GET /jobs/{id}` continuamente.

## Registro

```bash
curl -X POST http://localhost:8081/api/v1/webhooks \
  -H "Authorization: Bearer $MEDIAFORGE_KEY" -H "Content-Type: application/json" \
  -d '{"url":"https://consumer.example.com/webhooks/mediaforge","events":["job.completed","job.failed","job.cancelled"]}'
```

La respuesta (201) incluye `secret` (`whsec_...`). **Solo se muestra esta vez**: guárdalo como secreto de configuración.

Reglas de la URL:

- En producción solo se admite `https`.
- No se admiten credenciales, fragmentos ni puertos distintos de `MEDIAFORGE_WEBHOOK_ALLOWED_PORTS`.
- El host no puede resolver a una IP privada, de loopback, link-local, CGNAT ni de metadatos cloud. La comprobación se repite en cada envío y la conexión se fija a la IP validada, para evitar ataques de DNS rebinding.
- Para un servicio interno de confianza (p. ej. `http://consumer-api` en la red Docker), añade su host a `MEDIAFORGE_WEBHOOK_ALLOWED_HOSTS`.

Al crear un trabajo, `webhook_url` es opcional y debe coincidir exactamente con la URL de un webhook activo. Si se indica, solo ese webhook recibe los eventos de ese trabajo. Si no se indica, los reciben todos los webhooks suscritos del cliente.

## Petición que recibes

```http
POST /webhooks/mediaforge HTTP/1.1
Content-Type: application/json
User-Agent: MediaForge-Webhooks/1.0
MediaForge-Event-Id: evt_01jb8x...
MediaForge-Event-Type: job.completed
MediaForge-Delivery-Id: dlv_01jb8x...
MediaForge-Delivery-Attempt: 1
MediaForge-Signature: t=1730000000,v1=5257a869e7ec...

{"id":"evt_01jb8x...","object":"event","type":"job.completed","created_at":"2026-10-24T10:00:00+00:00","data":{"job":{ ...igual que GET /jobs/{id}... }}}
```

| Evento          | Cuándo                                                        |
|-----------------|---------------------------------------------------------------|
| `job.completed` | El trabajo terminó bien. Usa `GET /jobs/{id}/result`.         |
| `job.failed`    | Fallo definitivo, con `data.job.error.code` y `data.job.error.message`. |
| `job.cancelled` | El trabajo se canceló.                                        |
| `webhook.ping`  | Prueba manual (`POST /webhooks/{id}/ping`). Se envía siempre. |

## Entrega y reintentos

- **At-least-once**: el mismo evento puede llegar más de una vez. Deduplica por `MediaForge-Event-Id`.
- Responde **2xx en menos de 10 s**. Procesa en segundo plano si lo necesitas.
- Cualquier otra respuesta, un timeout o una redirección (no se siguen) se reintenta tras 10 s, 1 min, 5 min, 30 min y 2 h: 6 intentos en total.
- El orden de llegada no está garantizado. Usa `data.job.status` y `data.job.finished_at`.
- Para consultar el historial, usa `GET /webhooks/{id}/deliveries`. Para forzar un reenvío, usa `POST /webhooks/{id}/deliveries/{delivery}/redeliver`.

## Verificar la firma

`MediaForge-Signature` contiene un timestamp `t` y una o más firmas `v1`:

```
v1 = hex( HMAC-SHA256( secret, "{t}.{cuerpo crudo}" ) )
```

1. Usa el **cuerpo crudo** de la petición, no el JSON reserializado.
2. Rechaza la petición si `|ahora - t| > 300` segundos (protección contra repeticiones).
3. Acepta la petición si **alguna** firma `v1` coincide. Compara en tiempo constante.

Tras `POST /webhooks/{id}/rotate-secret`, las peticiones llevan durante el periodo de gracia (24 h por defecto) dos firmas `v1`: una con el secreto nuevo y otra con el anterior. Así puedes actualizar el secreto sin perder eventos.

### PHP (Laravel)

```php
use Illuminate\Http\Request;

Route::post('/webhooks/mediaforge', function (Request $request) {
    $body = $request->getContent();
    $header = (string) $request->header('MediaForge-Signature');
    $secret = config('services.mediaforge.webhook_secret');

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === 't') $timestamp = (int) $value;
        if ($key === 'v1') $signatures[] = $value;
    }

    abort_if($timestamp === null || abs(time() - $timestamp) > 300, 400, 'Firma caducada');

    $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    $valid = collect($signatures)->contains(fn ($s) => hash_equals($expected, $s));
    abort_unless($valid, 400, 'Firma no válida');

    $event = json_decode($body, true);

    // Deduplicación: ignora eventos ya procesados
    if (! Cache::add('mediaforge:event:'.$event['id'], true, now()->addDays(3))) {
        return response()->noContent();
    }

    dispatch(new HandleMediaForgeEvent($event)); // procesar en segundo plano

    return response()->noContent();
});
```

### Node.js (Express)

```js
import crypto from 'node:crypto';
import express from 'express';

const app = express();

app.post('/webhooks/mediaforge', express.raw({ type: 'application/json' }), (req, res) => {
  const body = req.body.toString('utf8');
  const header = req.get('MediaForge-Signature') ?? '';
  let timestamp = null;
  const signatures = [];

  for (const part of header.split(',')) {
    const [key, value = ''] = part.trim().split('=', 2);
    if (key === 't') timestamp = Number(value);
    if (key === 'v1') signatures.push(value);
  }

  if (!timestamp || Math.abs(Date.now() / 1000 - timestamp) > 300) {
    return res.status(400).send('Firma caducada');
  }

  const expected = crypto
    .createHmac('sha256', process.env.MEDIAFORGE_WEBHOOK_SECRET)
    .update(`${timestamp}.${body}`)
    .digest('hex');

  const valid = signatures.some(
    (s) => s.length === expected.length && crypto.timingSafeEqual(Buffer.from(s), Buffer.from(expected)),
  );
  if (!valid) return res.status(400).send('Firma no válida');

  const event = JSON.parse(body);
  // Deduplicar por event.id y procesar en segundo plano
  res.sendStatus(204);
});
```

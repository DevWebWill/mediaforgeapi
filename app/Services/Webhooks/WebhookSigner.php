<?php

namespace App\Services\Webhooks;

/**
 * Firma de webhooks: HMAC-SHA256 sobre "{timestamp}.{cuerpo}".
 * Cabecera: MediaForge-Signature: t=1700000000,v1=<hex>[,v1=<hex del secreto anterior>]
 * El timestamp permite al receptor rechazar repeticiones antiguas.
 */
class WebhookSigner
{
    /** @param  list<string>  $secrets */
    public function header(string $body, array $secrets, int $timestamp): string
    {
        $parts = ['t='.$timestamp];

        foreach ($secrets as $secret) {
            $parts[] = 'v1='.$this->sign($body, $secret, $timestamp);
        }

        return implode(',', $parts);
    }

    public function sign(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Verificación de referencia (la misma lógica que debe implementar el receptor). */
    public function verify(string $body, string $header, string $secret, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = $this->sign($body, $secret, $timestamp);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

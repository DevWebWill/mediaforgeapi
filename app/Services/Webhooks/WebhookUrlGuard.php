<?php

namespace App\Services\Webhooks;

use App\Services\Webhooks\Exceptions\UnsafeWebhookUrlException;

/**
 * Protección SSRF para las URLs de webhook.
 *
 * - Solo https (http únicamente si se permite por configuración, fuera de producción).
 * - Sin credenciales en la URL ni fragmentos; puertos en lista permitida.
 * - El host se resuelve y se rechaza si alguna IP es privada, reservada, loopback,
 *   link-local, CGNAT o de metadatos cloud. La IP validada se fija en la conexión
 *   (CURLOPT_RESOLVE) para evitar DNS rebinding entre validación y envío.
 * - Los hosts de "allowed_hosts" (servicios internos de confianza) omiten la
 *   comprobación de IP y de puerto.
 */
class WebhookUrlGuard
{
    /** Rangos bloqueados además de los que filter_var marca como privados/reservados */
    private const array BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '100.64.0.0/10',   // CGNAT
        '169.254.0.0/16',  // link-local / metadatos (169.254.169.254)
        '192.0.0.0/24',
        '198.18.0.0/15',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',   // IPv4 mapeada: se valida aparte la IPv4 contenida
        '64:ff9b::/96',
        'fc00::/7',        // ULA
        'fe80::/10',       // link-local
        'fd00:ec2::254/128',
    ];

    /**
     * Valida la URL y devuelve las IPs a las que se debe conectar
     * (vacío para hosts internos permitidos, que se resuelven con normalidad).
     *
     * @return array{host: string, port: int, ips: list<string>}
     *
     * @throws UnsafeWebhookUrlException
     */
    public function inspect(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || strlen($url) > 2048) {
            throw new UnsafeWebhookUrlException('La URL no es válida.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));

        if ($scheme !== 'https' && ! ($scheme === 'http' && config('mediaforge.webhooks.allow_http'))) {
            throw new UnsafeWebhookUrlException('La URL debe usar https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrlException('La URL no puede incluir credenciales.');
        }

        if (isset($parts['fragment'])) {
            throw new UnsafeWebhookUrlException('La URL no puede incluir fragmentos (#).');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (in_array($host, (array) config('mediaforge.webhooks.allowed_hosts'), true)) {
            return ['host' => $host, 'port' => $port, 'ips' => []];
        }

        if (! in_array($port, (array) config('mediaforge.webhooks.allowed_ports'), true)) {
            throw new UnsafeWebhookUrlException('Puerto no permitido para webhooks.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($ips === []) {
            throw new UnsafeWebhookUrlException('El host de la URL no se puede resolver.');
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new UnsafeWebhookUrlException('La URL apunta a una dirección de red no permitida.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => $ips];
    }

    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // IPv4 mapeada en IPv6 (::ffff:10.0.0.1): se evalúa la IPv4
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return $this->isPublicIp($m[1]);
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        if (! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return ($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask);
    }
}

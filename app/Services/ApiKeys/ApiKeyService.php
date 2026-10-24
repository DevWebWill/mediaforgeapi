<?php

namespace App\Services\ApiKeys;

use App\Models\ApiClient;
use App\Models\ApiKey;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Formato del token: mfk_<key_id>_<secreto>
 *  - key_id: 16 caracteres hex, público, identifica la clave sin revelar el secreto.
 *  - secreto: 48 caracteres base62 generados con un CSPRNG.
 *
 * Solo se almacena el SHA-256 del secreto. Al tener alta entropía no necesita un
 * hash lento (bcrypt/argon), lo que evita penalizar cada petición.
 */
final class ApiKeyService
{
    private const string PREFIX = 'mfk';

    private const string PATTERN = '/^mfk_([a-f0-9]{16})_([A-Za-z0-9]{48})$/';

    private const int LAST_USED_RESOLUTION_SECONDS = 60;

    public function issue(ApiClient $client, string $name, ?CarbonInterface $expiresAt = null): IssuedApiKey
    {
        $keyId = bin2hex(random_bytes(8));
        $secret = Str::random(48);

        $key = $client->apiKeys()->create([
            'name' => $name,
            'key_id' => $keyId,
            'secret_hash' => $this->hash($secret),
            'expires_at' => $expiresAt,
        ]);

        return new IssuedApiKey($key, self::PREFIX.'_'.$keyId.'_'.$secret);
    }

    /**
     * Devuelve la clave válida asociada al token o null. No distingue entre
     * clave inexistente, revocada, caducada o secreto incorrecto para no filtrar información.
     */
    public function authenticate(string $token): ?ApiKey
    {
        if (! preg_match(self::PATTERN, $token, $matches)) {
            return null;
        }

        [, $keyId, $secret] = $matches;

        $key = ApiKey::query()->with('apiClient')->where('key_id', $keyId)->first();

        if ($key === null || ! hash_equals($key->secret_hash, $this->hash($secret)) || ! $key->isUsable()) {
            return null;
        }

        $this->touch($key);

        return $key;
    }

    public function revoke(ApiKey $key): void
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * Emite una clave nueva y retira la anterior. Con un periodo de gracia la clave
     * anterior sigue funcionando hasta que caduca, para rotar sin cortar el servicio.
     */
    public function rotate(ApiKey $key, int $graceMinutes = 0): IssuedApiKey
    {
        return DB::transaction(function () use ($key, $graceMinutes): IssuedApiKey {
            $issued = $this->issue($key->apiClient, $key->name, $key->expires_at);

            if ($graceMinutes > 0) {
                $graceEnd = now()->addMinutes($graceMinutes);
                if ($key->expires_at === null || $key->expires_at->greaterThan($graceEnd)) {
                    $key->forceFill(['expires_at' => $graceEnd])->save();
                }
            } else {
                $this->revoke($key);
            }

            return $issued;
        });
    }

    private function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function touch(ApiKey $key): void
    {
        if ($key->last_used_at === null || $key->last_used_at->diffInSeconds(now()) >= self::LAST_USED_RESOLUTION_SECONDS) {
            ApiKey::query()->whereKey($key->id)->update(['last_used_at' => now()]);
        }
    }
}

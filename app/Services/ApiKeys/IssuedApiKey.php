<?php

namespace App\Services\ApiKeys;

use App\Models\ApiKey;
use SensitiveParameter;

/**
 * Resultado de emitir una clave. El token en claro solo existe en este objeto
 * y debe mostrarse una única vez al administrador.
 */
final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $key,
        #[SensitiveParameter] public string $plainTextToken,
    ) {}
}

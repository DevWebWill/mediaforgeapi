<?php

namespace App\Services\Webhooks\Exceptions;

use RuntimeException;

/** La URL de webhook no es segura (posible SSRF) o no es válida. El mensaje es público. */
class UnsafeWebhookUrlException extends RuntimeException {}

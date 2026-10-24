<?php

namespace App\Enums;

/**
 * Códigos de error estables del contrato HTTP público.
 */
enum ApiErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case ClientSuspended = 'client_suspended';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case RateLimited = 'rate_limited';
    case ActiveJobsLimitExceeded = 'active_jobs_limit_exceeded';
    case StorageQuotaExceeded = 'storage_quota_exceeded';
    case FileTooLarge = 'file_too_large';
    case UnsupportedMediaType = 'unsupported_media_type';
    case MediaLimitExceeded = 'media_limit_exceeded';
    case UploadFailed = 'upload_failed';
    case InsufficientStorage = 'insufficient_storage';
    case FileInUse = 'file_in_use';
    case WebhookLimitExceeded = 'webhook_limit_exceeded';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case InvalidIdempotencyKey = 'invalid_idempotency_key';
    case JobNotCancellable = 'job_not_cancellable';
    case JobNotCompleted = 'job_not_completed';
    case ServiceUnavailable = 'service_unavailable';
    case HttpError = 'http_error';
    case InternalError = 'internal_error';
}

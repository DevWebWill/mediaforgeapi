<?php

namespace App\Enums;

enum WebhookEventType: string
{
    case JobCompleted = 'job.completed';
    case JobFailed = 'job.failed';
    case JobCancelled = 'job.cancelled';
    case Ping = 'webhook.ping';

    public static function forStatus(JobStatus $status): ?self
    {
        return match ($status) {
            JobStatus::Completed => self::JobCompleted,
            JobStatus::Failed => self::JobFailed,
            JobStatus::Cancelled => self::JobCancelled,
            default => null,
        };
    }

    /** Eventos a los que se puede suscribir un endpoint. @return list<string> */
    public static function subscribable(): array
    {
        return [self::JobCompleted->value, self::JobFailed->value, self::JobCancelled->value];
    }
}

<?php

namespace App\Actions\Jobs;

use App\Enums\ApiErrorCode;
use App\Enums\JobStatus;
use App\Exceptions\ApiException;
use App\Models\MediaJob;
use App\Services\Jobs\JobStateMachine;

/**
 * - pending / queued / retrying: se cancela inmediatamente.
 * - processing: cancelación cooperativa; el worker la detecta y detiene solo su proceso.
 * - estados finales: 409.
 */
final class CancelMediaJob
{
    public function __construct(private readonly JobStateMachine $stateMachine) {}

    /** @return bool true si quedó cancelado; false si la cancelación está solicitada y pendiente */
    public function handle(MediaJob $job): bool
    {
        if ($this->stateMachine->cancel($job)) {
            return true;
        }

        if ($this->stateMachine->requestCancellation($job)) {
            return false;
        }

        $job->refresh();

        if ($job->status === JobStatus::Cancelled) {
            return true;
        }

        throw new ApiException(ApiErrorCode::JobNotCancellable, 'El trabajo ya ha finalizado y no puede cancelarse.', 409, [
            'status' => $job->status->value,
        ]);
    }
}

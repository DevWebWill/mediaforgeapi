<?php

namespace Tests\Unit;

use App\Enums\JobStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JobStatusTest extends TestCase
{
    /** @return array<string, array{JobStatus, JobStatus, bool}> */
    public static function transitions(): array
    {
        return [
            'pending → queued' => [JobStatus::Pending, JobStatus::Queued, true],
            'queued → processing' => [JobStatus::Queued, JobStatus::Processing, true],
            'processing → completed' => [JobStatus::Processing, JobStatus::Completed, true],
            'processing → retrying' => [JobStatus::Processing, JobStatus::Retrying, true],
            'retrying → processing' => [JobStatus::Retrying, JobStatus::Processing, true],
            'queued → completed' => [JobStatus::Queued, JobStatus::Completed, false],
            'pending → retrying' => [JobStatus::Pending, JobStatus::Retrying, false],
            'completed → processing' => [JobStatus::Completed, JobStatus::Processing, false],
            'failed → queued' => [JobStatus::Failed, JobStatus::Queued, false],
            'cancelled → processing' => [JobStatus::Cancelled, JobStatus::Processing, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_transitions(JobStatus $from, JobStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_terminal_states_have_no_transitions(): void
    {
        foreach (JobStatus::cases() as $status) {
            $this->assertSame($status->isTerminal(), $status->allowedTransitions() === []);
        }
    }
}

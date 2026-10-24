<?php

namespace App\Actions\Jobs;

use App\Models\MediaJob;

final readonly class CreatedMediaJob
{
    public function __construct(
        public MediaJob $job,
        public bool $replayed,
    ) {}
}

<?php

namespace App\Jobs\Generation;

use App\Jobs\Concerns\WorksOnVideo;
use App\Models\Video;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class GenerationJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WorksOnVideo;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public Video $video) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }
}

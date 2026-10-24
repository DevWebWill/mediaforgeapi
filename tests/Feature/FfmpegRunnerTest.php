<?php

namespace Tests\Feature;

use App\Media\Ffmpeg\Exceptions\FfmpegFailedException;
use App\Media\Ffmpeg\FfmpegRunner;
use App\Media\Processing\Exceptions\ProcessingCancelledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\TestCase;

class FfmpegRunnerTest extends TestCase
{
    private string $workdir;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mediaforge.ffmpeg.nice' => false, 'mediaforge.ffmpeg.heartbeat_seconds' => 0]);
        $this->workdir = sys_get_temp_dir().'/mf-runner-'.bin2hex(random_bytes(4));
        mkdir($this->workdir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->workdir.'/*') ?: []);
        rmdir($this->workdir);

        parent::tearDown();
    }

    public function test_reports_progress_and_completes(): void
    {
        $progress = [];

        (new FfmpegRunner)->run(
            ['-f', 'lavfi', '-i', 'testsrc=duration=3:size=320x240:rate=25', '-c:v', 'libx264', '-preset', 'ultrafast', '-f', 'mp4', 'out.mp4'],
            $this->workdir,
            3.0,
            onHeartbeat: function (?int $value) use (&$progress): void {
                $progress[] = $value;
            },
        );

        $this->assertFileExists($this->workdir.'/out.mp4');
        $this->assertNotEmpty($progress);
        $this->assertLessThanOrEqual(99, max(array_filter($progress, 'is_int') ?: [0]));
    }

    public function test_cancellation_stops_the_process(): void
    {
        $started = microtime(true);

        try {
            (new FfmpegRunner)->run(
                ['-re', '-f', 'lavfi', '-i', 'testsrc=duration=60', '-f', 'null', 'out.null'],
                $this->workdir,
                60.0,
                shouldCancel: fn (): bool => microtime(true) - $started > 0.5,
            );
            $this->fail('Se esperaba la cancelación.');
        } catch (ProcessingCancelledException) {
            $this->assertLessThan(10, microtime(true) - $started);
        }
    }

    public function test_timeout_stops_the_process(): void
    {
        config(['mediaforge.ffmpeg.timeout' => 1]);

        $this->expectException(ProcessTimedOutException::class);

        (new FfmpegRunner)->run(['-re', '-f', 'lavfi', '-i', 'testsrc=duration=60', '-f', 'null', 'out.null'], $this->workdir, 60.0);
    }

    public function test_failure_exposes_stderr_tail_and_classifies_input_errors(): void
    {
        file_put_contents($this->workdir.'/bad.mp4', str_repeat('x', 2048));

        try {
            (new FfmpegRunner)->run(['-i', 'bad.mp4', '-f', 'null', 'out.null'], $this->workdir);
            $this->fail('Se esperaba un error de FFmpeg.');
        } catch (FfmpegFailedException $e) {
            $this->assertNotSame(0, $e->exitCode);
            $this->assertTrue($e->isInputError(), $e->stderrTail);
        }
    }

    public function test_command_never_uses_a_shell_and_limits_output_size(): void
    {
        config(['mediaforge.ffmpeg.threads' => 2, 'mediaforge.ffmpeg.max_output_bytes' => 1000]);

        $command = (new FfmpegRunner)->command(['-i', 'in.mp4', 'out; rm -rf /.mp4']);

        $this->assertSame('out; rm -rf /.mp4', end($command));
        $this->assertContains('-nostdin', $command);
        $this->assertSame(['-threads', '2', '-fs', '1000'], array_slice($command, -5, 4));
    }
}

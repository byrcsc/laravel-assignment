<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Concurrency;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ParallelRun
{
    public const string UNSUPPORTED_REASON = 'This test needs ext-pcntl and ext-posix.';

    public const string SINGLE_WRITER_REASON = 'SQLite takes one writer at a time, so a forked race measures its busy timeout rather than the guarantee under test.';

    public static function isSupported(): bool
    {
        return function_exists('pcntl_fork') && function_exists('posix_kill');
    }

    /** SQLite serializes every writer, so a race against it proves nothing the server engines do not. */
    public static function needsServerDatabase(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    /**
     * @param  Closure(int): string  $work
     * @return list<string>
     */
    public static function run(int $processes, Closure $work, float $startDelay = 0.3): array
    {
        $reports = sys_get_temp_dir().'/'.uniqid('assignment-parallel-', true);

        if (! mkdir($reports)) {
            if (! is_dir($reports)) {
                throw new RuntimeException("Unable to create the report directory [{$reports}].");
            }
        }

        DB::disconnect();

        $startAt = microtime(true) + $startDelay;
        $processIds = [];

        for ($child = 0; $child < $processes; $child++) {
            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new RuntimeException('Unable to fork a child process.');
            }

            if ($processId === 0) {
                self::work("{$reports}/{$child}", $child, $startAt, $work);
            }

            $processIds[] = $processId;
        }

        foreach ($processIds as $processId) {
            pcntl_waitpid($processId, $status);
        }

        DB::reconnect();

        return self::collect($reports, $processes);
    }

    /** @param Closure(int): string $work */
    private static function work(string $report, int $child, float $startAt, Closure $work): never
    {
        try {
            DB::reconnect();
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            $outcome = $work($child);
        } catch (Throwable $throwable) {
            $outcome = 'failed: '.$throwable->getMessage();
        }

        file_put_contents($report, $outcome);
        posix_kill(posix_getpid(), SIGKILL);

        throw new RuntimeException('A child process survived SIGKILL.');
    }

    /** @return list<string> */
    private static function collect(string $reports, int $processes): array
    {
        $outcomes = [];

        for ($child = 0; $child < $processes; $child++) {
            $file = "{$reports}/{$child}";
            $outcomes[] = is_file($file)
                ? (string) file_get_contents($file)
                : 'failed: missing child report';

            @unlink($file);
        }

        @rmdir($reports);

        return $outcomes;
    }
}

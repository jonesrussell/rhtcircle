<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The monitor commands, invoked the way production invokes them.
 *
 * All three were **completely unrunnable** and no test noticed. The provider's
 * command handlers called `$this->entityTypeManager()` and `$this->database()`,
 * neither of which existed on the provider or on the `ServiceProvider` base, so
 * every command died with "Call to undefined method" the instant it was run
 * through `bin/waaseyaa`.
 *
 * `ScheduleAndCommandsTest` constructs `MonitorPublicCommand`,
 * `MonitorTriageCommand` and `MonitorRedactEventCommand` directly with fixture
 * collaborators. That is the right way to test their logic and it is not a
 * substitute for this: it never executes the provider handler, which is the
 * only path production takes. The commands were thoroughly tested and entirely
 * broken at the same time.
 *
 * So these tests run the real binary in a subprocess. Nothing is constructed by
 * hand, and a missing method, a bad binding or a broken handler signature fails
 * here rather than the first time an operator types the command.
 *
 * They deliberately assert on **exit codes and operator-facing output**, not on
 * database state — the collaborators' behaviour is covered elsewhere. What is
 * unique here is that the entry point resolves at all.
 */
final class MonitorCliEntryPointTest extends TestCase
{
    private string $projectRoot;
    private string $databasePath;
    /** @var array<string, string> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->databasePath = sys_get_temp_dir() . '/rhtcircle-cli-' . bin2hex(random_bytes(8)) . '.sqlite';

        $this->env = [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'WAASEYAA_DB' => $this->databasePath,
            'WAASEYAA_APP_SECRET' => 'base64:' . base64_encode(random_bytes(32)),
            'WAASEYAA_JWT_SECRET' => 'cli-entrypoint-secret',
            // Never set the dev fallback account: it masks access denials, so a
            // run with it set cannot show what production would do.
            'WAASEYAA_DEV_FALLBACK_ACCOUNT' => 'false',
        ];

        $this->runCli('db:init');
    }

    protected function tearDown(): void
    {
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // ------------------------------------------------------------------

    /** @return iterable<string, array{list<string>}> */
    public static function monitorCommands(): iterable
    {
        yield 'monitor-public --dry-run' => [['sagamok:monitor-public', '--dry-run']];
        yield 'monitor-redact-event' => [['sagamok:monitor-redact-event', '--event=999999', '--reason=member_request']];
    }

    #[Test]
    #[DataProvider('monitorCommands')]
    public function theCommandResolvesAndRunsThroughTheRealBinary(array $argv): void
    {
        [$exit, $output] = $this->runCli(...$argv);

        // The specific failure this pins. A provider whose handler calls a
        // method that does not exist produces exactly this, and nothing in the
        // unit-level command tests can see it.
        self::assertStringNotContainsString('Call to undefined method', $output);
        self::assertStringNotContainsString('Fatal error', $output);
        self::assertStringNotContainsString('Uncaught', $output);

        // Exit code is 0 or 1 — a *decision*, not a crash. 255 is PHP's fatal.
        self::assertContains($exit, [0, 1], sprintf('unexpected exit %d: %s', $exit, $output));
    }

    #[Test]
    public function theKillSwitchStopsADryRunFromFetchingAnything(): void
    {
        // `enabled => false` ships in config/waaseyaa.php. A dry run still makes
        // real HTTP requests to another Nation's website, so the switch that
        // says "we are not monitoring right now" has to cover it.
        [$exit, $output] = $this->runCli('sagamok:monitor-public', '--dry-run');

        self::assertSame(0, $exit);
        self::assertStringContainsString('disabled in configuration', $output);
        self::assertStringContainsString('Nothing was fetched', $output);
    }

    #[Test]
    public function theScheduledTaskIsNotLiveWhileTheEntryIsDisabled(): void
    {
        // Asserted against the runtime schedule, not `schedule:list` output:
        // that command prints discovered-but-disabled entries with a "Next"
        // time and no disabled marker, so its output cannot answer this
        // question. See the framework issue filed alongside this change.
        $probe = <<<'PHP'
            <?php
            require getenv('ROOT') . '/vendor/autoload.php';
            $k = new Waaseyaa\Foundation\Kernel\HttpKernel(getenv('ROOT'));
            $k->bootForCli();
            $names = [];
            foreach ($k->getSchedule()->tasks() as $t) { $names[] = $t->name; }
            // Explicit marker: the kernel emits log lines that contain
            // `[timestamps]`, so scanning for the first bracket finds a log
            // entry rather than this payload.
            echo "\n__TASKS__" . json_encode($names) . "__END__\n";
            PHP;
        $file = sys_get_temp_dir() . '/monitor_sched_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, $probe);

        try {
            [$exit, $output] = $this->runPhp($file);
            self::assertSame(0, $exit, $output);

            self::assertSame(
                1,
                preg_match('/__TASKS__(.*)__END__/s', $output, $matches),
                'the probe must emit its payload: ' . $output,
            );
            $names = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($names);

            // Positive control: the schedule must actually have tasks, or
            // "monitor is absent" would be trivially true.
            self::assertNotEmpty($names, 'the framework schedule must have live tasks');
            self::assertNotContains('sagamok-monitor-public', $names, 'the monitor task must not be live');
        } finally {
            @unlink($file);
        }
    }

    // ------------------------------------------------------------------

    /** @return array{int, string} */
    private function runCli(string ...$argv): array
    {
        return $this->exec([PHP_BINARY, $this->projectRoot . '/vendor/bin/waaseyaa', ...$argv]);
    }

    /** @return array{int, string} */
    private function runPhp(string $script): array
    {
        return $this->exec([PHP_BINARY, $script]);
    }

    /**
     * @param list<string> $command
     * @return array{int, string}
     */
    private function exec(array $command): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectRoot,
            $this->env + ['ROOT' => $this->projectRoot, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout . "\n" . $stderr];
    }
}

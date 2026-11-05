<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Commands;

use Crab\Config\Config;
use Crab\Console\Input;
use Crab\Console\Output;
use Crab\OpenApi\Commands\Generate;
use Crab\OpenApi\DocumentBuilder;
use Crab\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GenerateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-openapi-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_dir($file)) {
                rmdir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    public function testWritesAndChecksTheConfiguredFile(): void
    {
        $command = $this->command();
        [$output, $out, $error] = self::outputStreams();

        self::assertSame(0, $command->handle(new Input([]), $output));
        self::assertFileExists($this->directory . '/openapi.json');
        self::assertStringContainsString('Wrote ', self::contents($out));
        self::assertSame('', self::contents($error));

        [$checked, $checkedOut, $checkedError] = self::outputStreams();
        self::assertSame(0, $command->handle(new Input(['--check']), $checked));
        self::assertStringContainsString('is up to date.', self::contents($checkedOut));
        self::assertSame('', self::contents($checkedError));

        file_put_contents($this->directory . '/openapi.json', "{}\n");
        [$drift, $driftOut, $driftError] = self::outputStreams();
        self::assertSame(1, $command->handle(new Input(['--check']), $drift));
        self::assertSame('', self::contents($driftOut));
        self::assertStringContainsString('is out of date.', self::contents($driftError));
    }

    public function testCheckRefusesAMissingFile(): void
    {
        [$output, $out, $error] = self::outputStreams();

        self::assertSame(1, $this->command()->handle(new Input(['--check']), $output));
        self::assertSame('', self::contents($out));
        self::assertStringContainsString('There is no ', self::contents($error));
    }

    public function testStdoutAndConflictingStrictOptions(): void
    {
        [$output, $out, $error] = self::outputStreams();

        self::assertSame(0, $this->command()->handle(new Input(['--stdout']), $output));
        $document = json_decode(self::contents($out), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame('', self::contents($error));
        self::assertFileDoesNotExist($this->directory . '/openapi.json');

        [$conflict, $conflictOut, $conflictError] = self::outputStreams();
        self::assertSame(1, $this->command()->handle(new Input(['--strict', '--no-strict']), $conflict));
        self::assertSame('', self::contents($conflictOut));
        self::assertStringContainsString('Use --strict or --no-strict', self::contents($conflictError));

        [$checkConflict, $checkConflictOut, $checkConflictError] = self::outputStreams();
        self::assertSame(1, $this->command()->handle(new Input(['--stdout', '--check']), $checkConflict));
        self::assertSame('', self::contents($checkConflictOut));
        self::assertStringContainsString('Use --check with a file', self::contents($checkConflictError));
    }

    public function testAMissingOutputDirectoryIsACommandError(): void
    {
        $path = $this->directory . '/missing/openapi.json';
        [$output, $out, $error] = self::outputStreams();

        self::assertSame(1, $this->command()->handle(new Input(['--output=' . $path]), $output));
        self::assertSame('', self::contents($out));
        self::assertStringContainsString('does not exist', self::contents($error));
    }

    public function testStrictFailureWritesNothingAndNoStrictReportsTheGap(): void
    {
        $router = new Router();
        $router->get('/values', static fn(): array => []);
        $command = new Generate($this->builder($router), $this->config());

        [$strict, $strictOut, $strictError] = self::outputStreams();
        self::assertSame(1, $command->handle(new Input([]), $strict));
        self::assertSame('', self::contents($strictOut));
        self::assertStringContainsString('returns array', self::contents($strictError));
        self::assertFileDoesNotExist($this->directory . '/openapi.json');

        [$loose, $looseOut, $looseError] = self::outputStreams();
        self::assertSame(0, $command->handle(new Input(['--no-strict']), $loose));
        self::assertStringContainsString('Wrote ', self::contents($looseOut));
        self::assertStringContainsString('warning: ', self::contents($looseError));
        self::assertFileExists($this->directory . '/openapi.json');
    }

    public function testAnExplicitOutputCanGoToStdoutOrAnAbsoluteFile(): void
    {
        [$stdout, $out] = self::outputStreams();
        self::assertSame(0, $this->command()->handle(new Input(['--output=-']), $stdout));
        self::assertStringStartsWith('{', self::contents($out));

        $absolute = $this->directory . '/custom.json';
        [$output] = self::outputStreams();
        self::assertSame(0, $this->command()->handle(new Input(['--output=' . $absolute]), $output));
        self::assertFileExists($absolute);
    }

    public function testOutputNeedsAnExplicitValue(): void
    {
        [$output, $out, $error] = self::outputStreams();

        self::assertSame(1, $this->command()->handle(new Input(['--output']), $output));
        self::assertSame('', self::contents($out));
        self::assertStringContainsString('needs a value', self::contents($error));
        self::assertFileDoesNotExist($this->directory . '/openapi.json');

        [$empty, $emptyOut, $emptyError] = self::outputStreams();
        self::assertSame(1, $this->command()->handle(new Input(['--output=']), $empty));
        self::assertSame('', self::contents($emptyOut));
        self::assertStringContainsString('needs a value', self::contents($emptyError));
    }

    #[DataProvider('invalidOutputPaths')]
    public function testInvalidOutputPathsAreRefusedBeforeGeneration(string $path): void
    {
        $router = new Router();
        $router->get('/unknown', static fn(): array => []);
        $command = new Generate($this->builder($router), $this->config());
        [$output, $out, $error] = self::outputStreams();

        self::assertSame(1, $command->handle(new Input(['--output=' . $path]), $output));
        self::assertSame('', self::contents($out));
        self::assertStringContainsString('local file path', self::contents($error));
        self::assertSame([], glob($this->directory . '/*'));
    }

    public static function invalidOutputPaths(): array
    {
        return [
            ["broken\0.json"],
            ['php://stdout'],
            ['https://example.test/openapi.json'],
        ];
    }

    public function testFailedReplacementCleansUpTheTemporaryFile(): void
    {
        $path = $this->directory . '/existing';
        mkdir($path);
        [$output, $out, $error] = self::outputStreams();

        self::assertSame(1, $this->command()->handle(new Input(['--output=' . $path]), $output));
        self::assertSame('', self::contents($out));
        self::assertStringContainsString('Could not write', self::contents($error));
        self::assertDirectoryExists($path);
        self::assertSame([$path], glob($this->directory . '/*'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAShortWritePreservesTheExistingDocumentAndCleansUp(): void
    {
        if (!function_exists('posix_setrlimit') || !function_exists('pcntl_signal') || !defined('SIGXFSZ')) {
            self::markTestSkipped('A file size limit and SIGXFSZ handling are needed to force a short disk write.');
        }
        $path = $this->directory . '/openapi.json';
        file_put_contents($path, "old document\n");
        [$output, $out, $error] = self::outputStreams();
        $command = $this->command();
        $limits = posix_getrlimit();
        $soft = is_int($limits['soft filesize']) ? $limits['soft filesize'] : POSIX_RLIMIT_INFINITY;
        $hard = is_int($limits['hard filesize']) ? $limits['hard filesize'] : POSIX_RLIMIT_INFINITY;
        pcntl_signal(SIGXFSZ, SIG_IGN);
        self::assertTrue(posix_setrlimit(POSIX_RLIMIT_FSIZE, 64, $hard));
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) !== 0) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            return false;
        });
        try {
            $status = $command->handle(new Input([]), $output);
        } finally {
            restore_error_handler();
            error_reporting($reporting);
            posix_setrlimit(POSIX_RLIMIT_FSIZE, $soft, $hard);
        }

        self::assertSame(1, $status);
        self::assertSame("old document\n", file_get_contents($path));
        self::assertSame('', self::contents($out));
        self::assertStringContainsString('Could not write', self::contents($error));
        self::assertSame([$path], glob($this->directory . '/*'));
    }

    private function command(): Generate
    {
        $router = new Router();
        $router->get('/hello', static fn(): string => 'hello')->name('hello');
        return new Generate($this->builder($router), $this->config());
    }

    private function builder(Router $router): DocumentBuilder
    {
        return new DocumentBuilder($router, $this->config());
    }

    private function config(): Config
    {
        return new Config([
            'app' => ['name' => 'Command Test', 'path' => $this->directory],
            'openapi' => ['output' => 'openapi.json', 'strict' => true],
        ]);
    }

    /**
     * @return array{Output, resource, resource}
     */
    private static function outputStreams(): array
    {
        $out = fopen('php://memory', 'w+b');
        $error = fopen('php://memory', 'w+b');
        return [new Output($out, $error), $out, $error];
    }

    /**
     * @param resource $stream
     */
    private static function contents($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }
}

<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests;

use Crab\Console\Output;
use PHPUnit\Framework\TestCase;

final class ReadmeTest extends TestCase
{
    public function testInstallationExamplesGenerateAndCheckADocument(): void
    {
        $readme = file_get_contents(dirname(__DIR__) . '/README.md');
        preg_match_all('/```php\n(.*?)\n```/s', $readme, $blocks);
        $directory = sys_get_temp_dir() . '/crab-openapi-readme-' . bin2hex(random_bytes(6));
        mkdir($directory);
        mkdir($directory . '/config');
        mkdir($directory . '/routes');
        $out = fopen('php://memory', 'w+b');
        $error = fopen('php://memory', 'w+b');
        try {
            $application = str_replace(
                'use App\\Setup;',
                'use Crab\\OpenApi\\Tests\\Fixtures\\ReadmeSetup as Setup;',
                $blocks[1][0],
            );
            file_put_contents($directory . '/app.php', "<?php\n" . $application);
            file_put_contents($directory . '/config/openapi.php', "<?php\n" . $blocks[1][1]);
            file_put_contents($directory . '/routes/web.php', <<<'PHP'
                <?php
                Crab\Route::get('/hello', static fn(): string => 'hello');
                PHP);
            $app = require $directory . '/app.php';
            $output = new Output($out, $error);

            self::assertSame(0, $app->console->run(['openapi:generate'], $output));
            self::assertSame(0, $app->console->run(['openapi:generate', '--check'], $output));
            $document = json_decode(file_get_contents($directory . '/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['title' => 'Shop API', 'version' => '1.0.0'], $document['info']);
            self::assertSame(
                ['text/plain'],
                array_keys($document['paths']['/hello']['get']['responses']['200']['content']),
            );
            rewind($error);
            self::assertSame('', stream_get_contents($error));
        } finally {
            fclose($out);
            fclose($error);
            $files = array_map(static fn(string $path): string => $directory . '/' . $path, [
                'openapi.json',
                'app.php',
                'config/openapi.php',
                'routes/web.php',
            ]);
            foreach (array_filter($files, is_file(...)) as $file) {
                unlink($file);
            }
            rmdir($directory . '/config');
            rmdir($directory . '/routes');
            rmdir($directory);
        }
    }
}

<?php

declare(strict_types=1);

namespace Crab\OpenApi\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\OpenApi\DocumentBuilder;
use Crab\OpenApi\GenerationException;

final readonly class Generate implements Command
{
    public function __construct(
        private DocumentBuilder $builder,
        private Config $config,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Generate the OpenAPI 3.1 document for the application's routes

            Options:
              --output=openapi.json   Write here; "openapi.output" unless set
              --stdout                Write the document to standard output
              --check                 Fail when the file is different from the generated document
              --strict                Fail when a route cannot be described (the default)
              --no-strict             Write the document with the generic parts recorded as warnings

            Generation loads the application in its define phase. It starts no
            server and opens no database or network connection.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        if ($input->flag('strict') && $input->flag('no-strict')) {
            $output->error('Use --strict or --no-strict, not both.');
            return 1;
        }
        $writesStdout = $input->flag('stdout') || $input->option('output') === '-';
        if ($writesStdout && $input->flag('check')) {
            $output->error('Use --check with a file, once the document has been generated.');
            return 1;
        }
        $strict = match (true) {
            $input->flag('strict') => true,
            $input->flag('no-strict') => false,
            default => null,
        };
        try {
            $result = $this->builder->build($strict);
        } catch (GenerationException $error) {
            foreach ($error->problems as $problem) {
                $output->error($problem);
            }
            return 1;
        }

        foreach ($result->warnings as $warning) {
            $output->error('warning: ' . $warning);
        }
        $json =
            json_encode(
                $result->document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";

        if ($writesStdout) {
            $output->write($json);
            return 0;
        }

        $path = $this->path($input);
        if ($input->flag('check')) {
            if (!is_file($path)) {
                $output->error("There is no {$path} to check. Run `php cua openapi:generate` first.");
                return 1;
            }
            if (file_get_contents($path) !== $json) {
                $output->error("{$path} is out of date. Run `php cua openapi:generate` and review the change.");
                return 1;
            }
            $output->line("{$path} is up to date.");
            return 0;
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            $output->error("Could not write {$path}: the directory {$directory} does not exist.");
            return 1;
        }

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            $output->error("Could not write {$path}.");
            return 1;
        }
        $output->line("Wrote {$path}.");
        return 0;
    }

    private function path(Input $input): string
    {
        $given = $input->option('output');
        $path = is_string($given) && $given !== ''
            ? $given
            : (string) $this->config->get('openapi.output', 'openapi.json');
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }
        $base = $this->config->get('app.path');
        $base = is_string($base) && $base !== '' ? $base : (getcwd() ?: '.');
        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
}

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

            Generation reads the application's declarations. It starts no
            server and does not call actions or construct their services.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $given = $input->value('output', example: 'openapi.json');
        } catch (\InvalidArgumentException $error) {
            $output->error($error->getMessage());
            return 1;
        }
        if ($given === '') {
            $output->error('The --output option needs a value, such as --output=openapi.json.');
            return 1;
        }
        $path = $given ?? $this->config->get('openapi.output', 'openapi.json');
        if (!is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            $output->error('The output must be a local file path; use --output=openapi.json or --output=-.');
            return 1;
        }
        if ($input->flag('strict') && $input->flag('no-strict')) {
            $output->error('Use --strict or --no-strict, not both.');
            return 1;
        }
        $writesStdout = $input->flag('stdout') || $path === '-';
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

        $path = $this->path($path);
        if ($input->flag('check')) {
            if (!is_file($path)) {
                $output->error("There is no {$path} to check. Run `php cua openapi:generate` first.");
                return 1;
            }
            $contents = @file_get_contents($path);
            if ($contents === false) {
                $output->error("Could not read {$path}.");
                return 1;
            }
            if ($contents !== $json) {
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

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) {
            $output->error("Could not write {$path}.");
            return 1;
        }
        try {
            $written = @fwrite($stream, $json);
            $closed = @fclose($stream);
            if ($written !== strlen($json) || !$closed || !@rename($temporary, $path)) {
                $output->error("Could not write {$path}.");
                return 1;
            }
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
            @unlink($temporary);
        }
        $output->line("Wrote {$path}.");
        return 0;
    }

    private function path(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }
        $base = $this->config->get('app.path');
        $base = is_string($base) && $base !== '' ? $base : (getcwd() ?: '.');
        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
}

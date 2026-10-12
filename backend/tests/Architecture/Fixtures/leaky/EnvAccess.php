<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Leaky;

final class EnvAccess
{
    public function read(): array
    {
        $name = getenv('AWS_LAMBDA_FUNCTION_NAME');
        $memory = $_SERVER['AWS_LAMBDA_FUNCTION_MEMORY_SIZE'];
        $version = $_ENV["AWS_LAMBDA_FUNCTION_VERSION"];
        $runtime = array_key_exists('BREF_RUNTIME', $_SERVER);
        $dynamic = getenv("AWS_LAMBDA_LOG_{$name}");

        return [$name, $memory, $version, $runtime, $dynamic];
    }
}

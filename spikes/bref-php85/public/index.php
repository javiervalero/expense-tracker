<?php

declare(strict_types=1);

// FPM-mode handler (BREF_RUNTIME=fpm): echoes what PHP sees, as JSON.
header('Content-Type: application/json');
$env = [];
foreach (['APP_SPIKE_VAR', 'BREF_RUNTIME', 'AWS_LAMBDA_FUNCTION_NAME'] as $name) {
    $env['env_' . strtolower($name)] = getenv($name) === false ? null : getenv($name);
}
echo json_encode([
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'http_cloudfront_viewer_address' => $_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS'] ?? null,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
    'php_version' => PHP_VERSION,
] + $env, JSON_THROW_ON_ERROR);

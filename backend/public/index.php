<?php

declare(strict_types=1);

// Front controller under php-fpm (Phase 0 skeleton; Phase 1 replaces the body with the router).
require __DIR__ . '/../vendor/autoload.php';

$method = \is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri = \is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$response = (new App\Http\HealthEndpoint())->handle($method, explode('?', $uri, 2)[0]);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;

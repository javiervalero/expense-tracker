<?php

declare(strict_types=1);

// Front controller under php-fpm: read the body (capped at the JSON limit + 1 byte, so an oversized body is
// detectable but never held in full), build the request, run the kernel, emit the response.
require __DIR__ . '/../vendor/autoload.php';

use App\Api\ApiFactory;
use App\Http\JsonBody;
use App\Http\ResponseEmitter;

$body = file_get_contents('php://input', false, null, 0, JsonBody::MAX_BYTES + 1);
$response = ApiFactory::fromEnvironment(getenv())->handleGlobals($_SERVER, $body === false ? '' : $body);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
ResponseEmitter::native()->emit($response, is_string($method) ? $method : 'GET');

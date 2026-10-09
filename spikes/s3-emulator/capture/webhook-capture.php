<?php

declare(strict_types=1);

/*
 * Tiny webhook capture endpoint (router script for `php -S`).
 *   POST /hook       stores headers and raw body, answers 200
 *   GET  /captures   returns every capture as JSON (oldest first)
 *   DELETE /captures removes all captures
 *   GET  /health     200 "ok"
 */

const CAPTURE_DIR = '/data/captures';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (!is_dir(CAPTURE_DIR)) {
    mkdir(CAPTURE_DIR, 0777, true);
}

function respond_json(int $status, mixed $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

if ($path === '/health') {
    echo 'ok';
} elseif ($path === '/hook' && $method === 'POST') {
    $raw = file_get_contents('php://input');
    $entry = [
        'received_at' => microtime(true),
        'headers' => getallheaders(),
        'raw_body' => $raw,
    ];
    $name = sprintf('%s/%s-%s.json', CAPTURE_DIR, number_format($entry['received_at'], 6, '.', ''), bin2hex(random_bytes(4)));
    file_put_contents($name, json_encode($entry, JSON_UNESCAPED_SLASHES));
    respond_json(200, ['stored' => basename($name)]);
} elseif ($path === '/captures' && $method === 'GET') {
    $files = glob(CAPTURE_DIR . '/*.json') ?: [];
    sort($files);
    respond_json(200, array_map(static fn (string $f): mixed => json_decode(file_get_contents($f), true), $files));
} elseif ($path === '/captures' && $method === 'DELETE') {
    array_map('unlink', glob(CAPTURE_DIR . '/*.json') ?: []);
    respond_json(200, ['cleared' => true]);
} else {
    respond_json(404, ['error' => 'not found']);
}

<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

use InvalidArgumentException;
use JsonException;

/**
 * The bundled OpenAPI document (contract/dist/openapi.json), queried by method, path and status.
 *
 * Limits: only exact status codes are looked up (no `2XX` ranges, no `default`).
 */
final readonly class OpenApiDocument
{
    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @param string               $raw  the file contents as written, registered with the schema validator untouched
     *                                   (decoding to arrays and re-encoding would turn `{}` into `[]`)
     * @param array<string, mixed> $data the decoded document, used for lookups only
     */
    private function __construct(private string $raw, private array $data) {}

    public static function fromFile(string $file): self
    {
        $json = is_file($file) ? file_get_contents($file) : false;
        if ($json === false) {
            throw new InvalidArgumentException("Cannot read OpenAPI document: $file");
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Cannot parse OpenAPI document $file: " . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || !isset($data['openapi']) || !is_array($data['paths'] ?? null)) {
            throw new InvalidArgumentException("Not an OpenAPI document: $file");
        }

        return new self($json, self::map($data));
    }

    /** The raw document, to register with the schema validator. */
    public function json(): string
    {
        return $this->raw;
    }

    /** Spec path template (`/expenses/{id}`) for a concrete path; a literal path wins over a template. */
    public function pathTemplate(string $method, string $path): ?string
    {
        $path = explode('#', explode('?', $path, 2)[0], 2)[0];
        $candidates = [];
        foreach (array_keys($this->paths()) as $template) {
            if ($this->operation($method, $template) === null) {
                continue;
            }
            if ($template === $path) {
                return $template;
            }
            $regex = '#^' . preg_replace('#\\\{[^/}]+\\\}#', '[^/]+', preg_quote($template, '#')) . '$#';
            if (preg_match($regex, $path) === 1) {
                $candidates[] = $template;
            }
        }

        return $candidates[0] ?? null;
    }

    /** Required headers are only listed here; the validator checks their presence, not their schema or value. */
    public function response(string $method, string $template, int $status): ?OpenApiResponse
    {
        $pointer = '/paths/' . self::escape($template) . '/' . strtolower($method) . '/responses/' . $status;
        $response = $this->resolve($pointer);
        if ($response === null) {
            return null;
        }
        [$pointer, $node] = $response;

        $required = [];
        foreach (array_keys(self::map($node['headers'] ?? null)) as $name) {
            $resolved = $this->resolve($pointer . '/headers/' . self::escape($name));
            if (($resolved[1]['required'] ?? false) === true) {
                $required[] = $name;
            }
        }
        $schemas = [];
        foreach (self::map($node['content'] ?? null) as $type => $media) {
            $schemas[strtolower($type)] = array_key_exists('schema', self::map($media))
                ? $pointer . '/content/' . self::escape($type) . '/schema'
                : null;
        }

        return new OpenApiResponse($required, $schemas);
    }

    /** @return array<string, mixed> */
    private function paths(): array
    {
        return self::map($this->data['paths'] ?? null);
    }

    /** @return array<string, mixed>|null */
    private function operation(string $method, string $template): ?array
    {
        $method = strtolower($method);
        $item = self::map($this->paths()[$template] ?? null);

        return in_array($method, self::METHODS, true) && is_array($item[$method] ?? null) ? self::map($item[$method]) : null;
    }

    /**
     * Follows `$ref` chains that start with `#/`.
     *
     * @return array{0: string, 1: array<string, mixed>}|null final pointer and node
     */
    private function resolve(string $pointer): ?array
    {
        for ($hops = 0; $hops < 10; ++$hops) {
            $node = $this->data;
            foreach (explode('/', substr($pointer, 1)) as $part) {
                $key = str_replace(['~1', '~0'], ['/', '~'], $part);
                if (!is_array($node) || !array_key_exists($key, $node)) {
                    return null;
                }
                $node = $node[$key];
            }
            if (!is_array($node)) {
                return null;
            }
            if (!isset($node['$ref'])) {
                return [$pointer, self::map($node)];
            }
            $ref = $node['$ref'];
            if (!is_string($ref) || !str_starts_with($ref, '#/')) {
                return null;
            }
            $pointer = substr($ref, 1);
        }

        return null;
    }

    /** JSON pointer escaping of one path segment. */
    public static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        $map = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}

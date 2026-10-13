<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Checks a real response (status, content type, required headers, body) against the OpenAPI document.
 *
 * The whole document is registered under a fixed `$id`, so a schema is addressed as `$id#/json/pointer` and the
 * `#/components/...` refs inside it resolve against the same document.
 *
 * Opis stops at the first error on purpose: with several errors enabled its additionalProperties message also lists
 * properties that are valid, which misleads.
 */
final readonly class ContractValidator
{
    private const string DOCUMENT_ID = 'https://contract.invalid/openapi.json';

    private Validator $validator;

    public function __construct(private OpenApiDocument $document)
    {
        $this->validator = new Validator();
        $this->validator->resolver()?->registerRaw($document->json(), self::DOCUMENT_ID);
    }

    /**
     * @param array<string, string> $headers response headers (names are case-insensitive)
     *
     * @return list<ContractViolation>
     */
    public function validate(string $method, string $path, int $status, array $headers, string $body): array
    {
        $method = strtoupper($method);
        $template = $this->document->pathTemplate($method, $path);
        if ($template === null) {
            return [new ContractViolation(ContractViolation::OPERATION, "no such operation: $method $path")];
        }
        $response = $this->document->response($method, $template, $status);
        if ($response === null) {
            return [new ContractViolation(ContractViolation::STATUS, "$method $template documents no $status response")];
        }

        $headers = array_change_key_case($headers, CASE_LOWER);
        $violations = [];
        foreach ($response->requiredHeaders() as $name) {
            if (!array_key_exists(strtolower($name), $headers)) {
                $violations[] = new ContractViolation(ContractViolation::HEADER, "required header $name is missing");
            }
        }

        return [...$violations, ...$this->checkBody($method, $template, $status, $response, $headers['content-type'] ?? null, $body)];
    }

    /** @return list<ContractViolation> */
    private function checkBody(string $method, string $template, int $status, OpenApiResponse $response, ?string $contentType, string $body): array
    {
        $operation = "$method $template $status";
        if ($method === 'HEAD' || $response->mediaTypes() === []) {
            return $body === '' ? [] : [new ContractViolation(ContractViolation::BODY, "$operation must have no body")];
        }

        $mediaType = strtolower(trim(explode(';', (string) $contentType)[0]));
        $schemaPointer = $response->schemaPointer($mediaType);
        if ($schemaPointer === null) {
            $documented = implode(', ', $response->mediaTypes());
            $shown = $mediaType === '' ? 'no content type' : $mediaType;

            return [new ContractViolation(ContractViolation::CONTENT_TYPE, "$shown is not documented for $operation (documented: $documented)")];
        }

        try {
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [new ContractViolation(ContractViolation::BODY, 'not valid JSON: ' . $e->getMessage())];
        }

        $result = $this->validator->validate($data, self::DOCUMENT_ID . '#' . self::fragment($schemaPointer));
        if ($result->isValid() || $result->error() === null) {
            return [];
        }

        $violations = [];
        foreach ((new ErrorFormatter())->formatKeyed($result->error()) as $pointer => $messages) {
            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                $violations[] = new ContractViolation(ContractViolation::BODY, $pointer . ': ' . (is_string($message) ? $message : 'invalid'));
            }
        }

        return $violations;
    }

    /** Percent-encodes what a URI fragment cannot hold (`{`, `}`, spaces) but keeps `/` and `~`. */
    private static function fragment(string $pointer): string
    {
        return str_replace('%2F', '/', rawurlencode($pointer));
    }
}

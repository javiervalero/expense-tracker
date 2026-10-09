<?php

declare(strict_types=1);

namespace Spike;

use Aws\DynamoDb\DynamoDbClient;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Opis\JsonSchema\Validator;

final class Probe
{
    private const DRAFT_2020_12 = 'https://json-schema.org/draft/2020-12/schema';

    /**
     * @param array{amount: int} $data
     */
    public function schemaIsValid(array $data): bool
    {
        $schema = json_encode([
            '$schema' => self::DRAFT_2020_12,
            'type' => 'object',
            'properties' => ['amount' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['amount'],
            'prefixItems' => [],
            'unevaluatedProperties' => false,
        ], JSON_THROW_ON_ERROR);
        $validator = new Validator();
        $result = $validator->validate(json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR), $schema);

        return $result->isValid();
    }

    /**
     * @return list<string>
     */
    public function clientClasses(): array
    {
        $config = [
            'region' => 'eu-west-1',
            'version' => 'latest',
            'credentials' => ['key' => 'dummy', 'secret' => 'dummy'],
            'endpoint' => 'http://127.0.0.1:1',
        ];

        return [
            (new DynamoDbClient($config))::class,
            (new S3Client($config + ['use_path_style_endpoint' => true]))::class,
            (new SqsClient($config))::class,
        ];
    }

    public function add(int $left, int $right): int
    {
        return $left + $right;
    }
}

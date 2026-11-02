<?php

declare(strict_types=1);

namespace App\Receipts\Http;

use App\Http\Input\MemberErrors;
use App\Http\Input\ObjectMembers;
use App\Http\Input\StringMember;
use App\Http\Problem\FieldError;
use App\Http\Problem\ValidationProblem;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;

/**
 * The decoded body of `POST /expenses/{id}/receipt/upload`: `{contentType, size}` and nothing else (C4). The type must
 * be exactly one of the four MIME strings; the size a JSON integer from 1 to {@see ReceiptLimits::MAX_BYTES}. A float
 * (even `100.0`), a numeric string, `null`, a boolean or a list is a `422` at its pointer, never a `TypeError`; an
 * integer beyond 64 bits arrives as a string ({@see \App\Http\JsonBody}) and is refused as one.
 *
 * Both are only what the client DECLARES: the policy pins them in S3, and the worker checks the real file.
 *
 * All the errors are reported together, in member order, then the unknown members (at most 5, safe pointers: the rules
 * of {@see ObjectMembers}). A message names the member and never repeats a value.
 */
final readonly class ReceiptUploadInput
{
    private const string CONTENT_TYPE = 'contentType';
    private const string SIZE = 'size';
    private const string REQUIRED = 'This field is required.';

    private function __construct(
        public ReceiptContentType $contentType,
        public int $size,
    ) {}

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws ValidationProblem
     */
    public static function fromBody(#[\SensitiveParameter] array $body): self
    {
        $type = self::contentType($body);
        $size = self::size($body);
        $unknown = ObjectMembers::unknownErrors($body, [self::CONTENT_TYPE, self::SIZE]);
        $errors = [...MemberErrors::of($type), ...MemberErrors::of($size), ...$unknown];
        // The instanceof checks only narrow the types for static analysis; $errors is non-empty whenever one is true.
        if ($errors !== [] || $type instanceof FieldError || $size instanceof FieldError) {
            throw new ValidationProblem($errors);
        }

        return new self($type, $size);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function contentType(#[\SensitiveParameter] array $body): ReceiptContentType|FieldError
    {
        $text = StringMember::required($body, self::CONTENT_TYPE);
        if ($text instanceof FieldError) {
            return $text;
        }

        return ReceiptContentType::tryFrom($text) ?? new FieldError('/' . self::CONTENT_TYPE, ReceiptFieldMessages::contentType());
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function size(#[\SensitiveParameter] array $body): int|FieldError
    {
        if (!array_key_exists(self::SIZE, $body)) {
            return new FieldError('/' . self::SIZE, self::REQUIRED);
        }
        $size = $body[self::SIZE];
        if (!is_int($size) || $size < 1 || $size > ReceiptLimits::MAX_BYTES) {
            return new FieldError('/' . self::SIZE, ReceiptFieldMessages::size());
        }

        return $size;
    }
}

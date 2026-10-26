<?php

declare(strict_types=1);

namespace App\Auth\Http;

use App\Http\Input\ObjectMembers;
use App\Http\Problem\FieldError;
use App\Http\Problem\ValidationProblem;
use JsonSerializable;
use LogicException;
use WeakMap;

/**
 * The decoded body of a credentials request (register, later login): exactly `email` and `password`, both strings.
 * This is the shape only. What an address or a password must look like is the job of {@see \App\Auth\Email} and
 * {@see \App\Auth\Password\PasswordPolicy}, which also refuse control characters.
 *
 * A missing member, a value that is not a string (null, number, boolean, list or object) and any other key are
 * reported together as a {@see ValidationProblem}. A pointer names the member but a message never repeats a value or a
 * key; the unknown-member rules (at most 5 reported, safe pointers) are those of {@see ObjectMembers}. The input comes from
 * {@see \App\Http\JsonBody::decode()}, which does not tell a JSON array from an object with sequential integer keys;
 * that is harmless here, because only two string members are accepted.
 *
 * The password is not a property but an entry in a static WeakMap (freed with the object), like the value of a
 * session token: `var_export`, `(array)` casts and `get_object_vars` do not find it; `var_dump` and `print_r` use
 * `__debugInfo` (which hides the address too); `json_encode`, `serialize` and `unserialize` throw; the object cannot be cloned.
 */
final class CredentialsInput implements JsonSerializable
{
    private const EMAIL = 'email';
    private const PASSWORD = 'password';

    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $passwords = null;

    private function __construct(
        public readonly string $email,
        #[\SensitiveParameter]
        string $password,
    ) {
        self::passwords()[$this] = $password;
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws ValidationProblem
     */
    public static function fromBody(#[\SensitiveParameter] array $body): self
    {
        $errors = [];
        foreach ([self::EMAIL, self::PASSWORD] as $member) {
            if (!array_key_exists($member, $body)) {
                $errors[] = new FieldError('/' . $member, 'This field is required.');
            } elseif (!is_string($body[$member])) {
                $errors[] = new FieldError('/' . $member, 'Must be a string.');
            }
        }
        $errors = [...$errors, ...ObjectMembers::unknownErrors($body, [self::EMAIL, self::PASSWORD])];
        if ($errors !== []) {
            throw new ValidationProblem($errors);
        }
        $email = $body[self::EMAIL];
        $password = $body[self::PASSWORD];
        assert(is_string($email) && is_string($password));

        return new self($email, $password);
    }

    public function password(): string
    {
        return self::passwords()[$this] ?? throw new LogicException('Credentials have no password.');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['email' => '[redacted]', 'password' => '[redacted]'];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Credentials must not be encoded as JSON.');
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Credentials must not be serialised.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new LogicException('Credentials must not be unserialised.');
    }

    public function __clone()
    {
        throw new LogicException('Credentials must not be cloned.');
    }

    /**
     * @return WeakMap<self, string>
     */
    private static function passwords(): WeakMap
    {
        return self::$passwords ??= new WeakMap();
    }
}

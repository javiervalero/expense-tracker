<?php

declare(strict_types=1);

namespace App\Expenses\Listing;

use App\Expenses\Expense;
use App\Expenses\ExpenseDate;
use App\Expenses\ExpenseKeys;
use App\Infrastructure\Id\Uuid;
use JsonException;

/**
 * The opaque cursor of a list (B18): base64url without padding of the JSON `{"v":1,"sk":"EXP#<uuid>","lsi":
 * "EXP#<date>#<uuid>","f":"<filter hash>"}` of the last EXAMINED item. It is a client-made value like any other
 * query parameter, so {@see self::decode()} refuses anything {@see self::encode()} could not have produced, before
 * any store call: at most 512 characters of the strict alphabet, JSON of depth 2 with exactly the four members, `v`
 * the integer 1, three strings, `sk` and `lsi` of the same uuid, a date inside the range and the hash of the very
 * filters of this request. Last, the decoded cursor must encode back to the very input: any other spelling of the same
 * data (padding, stray bits, blanks, reordered or repeated members) is refused. A well-formed forged position is
 * accepted: it only repositions inside the caller's partition and [from, to] (B18, no MAC). The partition is never in
 * it (it comes from the session).
 */
final readonly class ListCursor
{
    public const int MAX_CHARS = 512;
    private const int VERSION = 1;
    private const int JSON_DEPTH = 2;
    private const string ALPHABET = '/\A[A-Za-z0-9_-]+\z/D';
    private const string INDEX_KEY = '/\AEXP#(\d{4}-\d{2}-\d{2})#(.*)\z/Ds';
    /** The members, sorted, as `array_keys` of a decoded cursor must be. */
    private const array MEMBERS = ['f', 'lsi', 'sk', 'v'];

    private function __construct(private string $sk, private string $lsi, private string $filterHash) {}

    /** The cursor that continues after `$examined`, for the filters with this hash. */
    public static function after(Expense $examined, string $filterHash): self
    {
        return new self(
            ExpenseKeys::PREFIX . $examined->id,
            ExpenseKeys::indexKey($examined->date, $examined->id),
            $filterHash,
        );
    }

    public function encode(): string
    {
        return self::base64url(json_encode(
            ['v' => self::VERSION, 'sk' => $this->sk, 'lsi' => $this->lsi, 'f' => $this->filterHash],
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @throws InvalidQuery for anything that this class did not write for these filters
     */
    public static function decode(string $raw, ExpenseDate $from, ExpenseDate $to, string $filterHash): self
    {
        $members = self::members($raw);
        $sk = $members['sk'] ?? null;
        $lsi = $members['lsi'] ?? null;
        $hash = $members['f'] ?? null;
        if (
            ($members['v'] ?? null) !== self::VERSION
            || !is_string($sk) || !is_string($lsi) || !is_string($hash)
            || !hash_equals($filterHash, $hash)
            || !self::positionIsValid($sk, $lsi, $from, $to)
        ) {
            throw InvalidQuery::cursor();
        }

        $cursor = new self($sk, $lsi, $hash);
        // Exactly what encode() writes: no duplicate or reordered members, no blanks, no other spelling of the same data.
        if ($cursor->encode() !== $raw) {
            throw InvalidQuery::cursor();
        }

        return $cursor;
    }

    /** @return array{SK: string, LSI1SK: string} */
    public function startKey(): array
    {
        return ['SK' => $this->sk, 'LSI1SK' => $this->lsi];
    }

    /**
     * The decoded JSON object with exactly the four members (their types are checked by the caller).
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidQuery
     */
    private static function members(string $raw): array
    {
        if (strlen($raw) > self::MAX_CHARS || preg_match(self::ALPHABET, $raw) !== 1) {
            throw InvalidQuery::cursor();
        }
        $json = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($json === false) {
            throw InvalidQuery::cursor();
        }
        try {
            $members = json_decode($json, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidQuery::cursor();
        }
        if (!is_array($members)) {
            throw InvalidQuery::cursor();
        }
        $names = array_keys($members);
        sort($names);

        return $names === self::MEMBERS ? $members : throw InvalidQuery::cursor();
    }

    /** `sk` is `EXP#<uuid>`, `lsi` is `EXP#<date>#<the same uuid>`, and the date is inside `[from, to]`. */
    private static function positionIsValid(string $sk, string $lsi, ExpenseDate $from, ExpenseDate $to): bool
    {
        if (!str_starts_with($sk, ExpenseKeys::PREFIX) || preg_match(self::INDEX_KEY, $lsi, $parts) !== 1) {
            return false;
        }
        $id = substr($sk, strlen(ExpenseKeys::PREFIX));
        $date = ExpenseDate::parse($parts[1]);

        return Uuid::isValid($id)
            && $parts[2] === $id
            && $date !== null
            && !$from->isAfter($date)
            && !$date->isAfter($to);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

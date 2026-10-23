<?php

declare(strict_types=1);

namespace App\Categories;

use App\Auth\Session\Principal;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Id\Uuid;
use InvalidArgumentException;
use LogicException;

/**
 * The one rule for "is this category id one of the owner's?" (B16). Built for one {@see Principal}; it reads nothing
 * until the first {@see self::resolve()}, {@see self::has()} or {@see self::all()} that needs the list, and then reads
 * the owner's categories once for its lifetime. That includes a failed read: the exception is kept and rethrown by
 * every later call, so a request makes at most one query even when storage fails.
 *
 * One resolver per request, built from that request's Principal. It is a snapshot taken at the first read: do not
 * keep it in a long-lived service, and do not reuse it after a category write in the same request.
 *
 * Not `readonly`: the memoised list and failure are its only mutable state, and a holder object would add a class
 * for no gain.
 */
final class CategoryResolver
{
    /** @var array<string, Category>|null the owner's categories by id, sorted; null until read */
    private ?array $byId = null;

    private ?StorageFailure $failure = null;

    public function __construct(
        private readonly CategoryLister $categories,
        private readonly Principal $owner,
    ) {}

    /**
     * Guards against a resolver built for another user: a caller that mixes principals would otherwise answer one
     * user's category questions with another user's list. The message names no id.
     *
     * @throws LogicException when `$principal` is not the owner this resolver was built for
     */
    public function assertFor(Principal $principal): void
    {
        if ($principal->userId !== $this->owner->userId) {
            throw new LogicException('The category resolver belongs to another user.');
        }
    }

    /**
     * The id itself when it is one of the owner's categories, otherwise null: a missing id, one that is not a
     * lower-case UUID (no storage read), an unknown or deleted one, or another user's.
     *
     * @throws InvalidArgumentException when the principal's user id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function resolve(?string $id): ?string
    {
        if ($id === null || !Uuid::isValid($id)) {
            return null;
        }

        return isset($this->loadedById()[$id]) ? $id : null;
    }

    /**
     * @throws InvalidArgumentException when the principal's user id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function has(string $id): bool
    {
        return $this->resolve($id) !== null;
    }

    /**
     * Sorted by folded name (byte order of {@see CategoryName::foldedValue()}), then id.
     *
     * @return list<Category>
     *
     * @throws InvalidArgumentException when the principal's user id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function all(): array
    {
        return array_values($this->loadedById());
    }

    private static function compare(Category $a, Category $b): int
    {
        $byName = strcmp($a->name->foldedValue(), $b->name->foldedValue());

        return $byName !== 0 ? $byName : strcmp($a->id, $b->id);
    }

    /**
     * @return array<string, Category>
     */
    private function loadedById(): array
    {
        if ($this->byId !== null) {
            return $this->byId;
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            $sorted = $this->categories->listAll($this->owner);
        } catch (StorageFailure $failure) {
            $this->failure = $failure;

            throw $failure;
        }
        usort($sorted, self::compare(...));
        $byId = [];
        foreach ($sorted as $category) {
            $byId[$category->id] = $category;
        }

        return $this->byId = $byId;
    }
}

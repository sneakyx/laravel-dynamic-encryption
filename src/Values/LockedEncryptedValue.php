<?php

namespace Sneakyx\LaravelDynamicEncryption\Values;

/**
 * Placeholder object representing an encrypted value that could not be
 * decrypted yet because the required key/password is not available.
 *
 * This object is intentionally lightweight and safe to serialize.
 */
final class LockedEncryptedValue implements \JsonSerializable, \Stringable
{
    public function __construct(
        public readonly string $attribute,
        public readonly string|int|null $ownerId = null
    ) {}

    public function isLocked(): bool
    {
        return true;
    }

    public function __toString(): string
    {
        // Do not leak data; present as empty when cast to string
        return '';
    }

    public function jsonSerialize(): mixed
    {
        // Represent as null in JSON contexts
        return null;
    }
}

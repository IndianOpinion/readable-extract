<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

/**
 * One credited person, organisation or agency.
 */
final class Author implements \JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $url = null,
        public readonly AuthorKind $kind = AuthorKind::Person,
        public readonly AuthorRole $role = AuthorRole::Author,
    ) {
    }

    /** @return array{name: string, url: ?string, kind: string, role: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'kind' => $this->kind->value,
            'role' => $this->role->value,
        ];
    }

    /** @return array{name: string, url: ?string, kind: string, role: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

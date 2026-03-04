<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Message;

readonly class Response
{
    /**
     * @param list<Operation> $operations
     */
    public function __construct(
        public string $destAddr,
        public string $mDomain,
        public array $operations,
        public int $version = 1,
        public int $seq = 1,
    ) {
    }

    public function getOperation(string $name): ?Operation
    {
        foreach ($this->operations as $operation) {
            if ($operation->name === $name) {
                return $operation;
            }
        }

        return null;
    }

    public function getFirstOperation(): ?Operation
    {
        return $this->operations[0] ?? null;
    }

    public function isSuccessful(): bool
    {
        foreach ($this->operations as $operation) {
            if (!$operation->isSuccessful()) {
                return false;
            }
        }

        return true;
    }
}

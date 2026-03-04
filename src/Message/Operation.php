<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Message;

/**
 * Represents a single KOAP operation within a management domain.
 *
 * @phpstan-type OperationPayload array<string, mixed>
 */
readonly class Operation
{
    /**
     * @param OperationPayload $payload
     */
    public function __construct(
        public string $name,
        public int $seq,
        public array $payload = [],
        public ?string $executionStatus = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->executionStatus === null || $this->executionStatus === 'success';
    }
}

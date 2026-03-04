<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Message;

readonly class Request
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
}

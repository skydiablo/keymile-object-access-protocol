<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Model;

/**
 * Represents a node in the MileGate Managed Object Model tree,
 * as returned by a discover operation.
 */
readonly class ManagedObject
{
    /**
     * @param list<ManagedObject> $children
     */
    public function __construct(
        public string $moType,
        public string $adfReference,
        public string $addressFragment,
        public string $moName,
        public string $state,
        public string $adminState,
        public bool $hasChildren,
        public array $children = [],
        public string $assignedMoName = '',
        public string $label = '',
        public int $koapVersion = 1,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            moType: (string) ($data['moType'] ?? $data['motype'] ?? ''),
            adfReference: (string) ($data['adfReference'] ?? $data['adfreference'] ?? ''),
            addressFragment: (string) ($data['addressFragment'] ?? $data['addressfragment'] ?? ''),
            moName: (string) ($data['moName'] ?? $data['moname'] ?? ''),
            state: (string) ($data['state'] ?? ''),
            adminState: (string) ($data['adminState'] ?? $data['adminstate'] ?? ''),
            hasChildren: ($data['hasChildren'] ?? $data['haschildren'] ?? 'false') === 'true',
            assignedMoName: (string) ($data['assignedMoName'] ?? $data['assignedmoname'] ?? ''),
            koapVersion: (int) ($data['koapVersion'] ?? $data['koapversion'] ?? 1),
        );
    }
}

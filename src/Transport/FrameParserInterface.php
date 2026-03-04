<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport;

/**
 * Strategy for splitting a raw byte stream into individual KOAP messages.
 *
 * Since the exact wire framing of the KOAP protocol is not yet fully documented,
 * this interface allows swapping different framing strategies (null-terminated,
 * length-prefixed, raw XML detection, etc.).
 */
interface FrameParserInterface
{
    /**
     * Feed raw data from the socket into the parser.
     * Returns an array of complete message strings extracted from the buffer.
     *
     * @return list<string>
     */
    public function feed(string $data): array;

    /**
     * Reset the internal buffer.
     */
    public function reset(): void;
}

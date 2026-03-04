<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport\Frame;

use SkyDiablo\Keymile\Koap\Transport\FrameParserInterface;

/**
 * Splits messages using a null byte (\x00) as delimiter.
 *
 * Some proprietary protocols use null-byte termination to separate
 * individual XML messages on the wire.
 */
class NullTerminatedFrameParser implements FrameParserInterface
{
    private string $buffer = '';

    public function feed(string $data): array
    {
        $this->buffer .= $data;
        $messages = [];

        while (($pos = strpos($this->buffer, "\x00")) !== false) {
            $message = trim(substr($this->buffer, 0, $pos));
            $this->buffer = substr($this->buffer, $pos + 1);

            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function reset(): void
    {
        $this->buffer = '';
    }
}

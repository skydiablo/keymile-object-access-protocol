<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport\Frame;

use SkyDiablo\Keymile\Koap\Transport\FrameParserInterface;

/**
 * Splits messages using a 4-byte big-endian length prefix.
 *
 * Each frame is: [4 bytes length][payload]
 * where length is the number of bytes in the payload.
 */
class LengthPrefixedFrameParser implements FrameParserInterface
{
    private const int HEADER_SIZE = 4;

    private string $buffer = '';

    public function feed(string $data): array
    {
        $this->buffer .= $data;
        $messages = [];

        while (strlen($this->buffer) >= self::HEADER_SIZE) {
            $header = substr($this->buffer, 0, self::HEADER_SIZE);
            /** @var array{length: int} $unpacked */
            $unpacked = unpack('Nlength', $header);
            $length = $unpacked['length'];

            if (strlen($this->buffer) < self::HEADER_SIZE + $length) {
                break;
            }

            $message = substr($this->buffer, self::HEADER_SIZE, $length);
            $this->buffer = substr($this->buffer, self::HEADER_SIZE + $length);

            $trimmed = trim($message);
            if ($trimmed !== '') {
                $messages[] = $trimmed;
            }
        }

        return $messages;
    }

    public function reset(): void
    {
        $this->buffer = '';
    }
}

<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport\Mtp;

use SkyDiablo\Keymile\Koap\Exception\ProtocolException;

/**
 * Consistent Overhead Byte Stuffing (COBS) encoder/decoder.
 *
 * COBS replaces all 0x00 bytes in the data with run-length pointers,
 * allowing 0x00 to be used as an unambiguous frame delimiter.
 *
 * Each group starts with a code byte N (1–254):
 *   - N-1 literal bytes follow
 *   - If N < 255, a 0x00 byte is appended to the output
 *   - If N = 255, no 0x00 is appended (block was full, 254 literals)
 */
class CobsCodec
{
    /**
     * Decode a COBS-encoded byte string back to the original data.
     */
    public static function decode(string $data): string
    {
        $len = strlen($data);
        $result = '';
        $i = 0;

        while ($i < $len) {
            $code = ord($data[$i]);
            if ($code === 0) {
                break;
            }
            $i++;

            $literalCount = $code - 1;
            if ($i + $literalCount > $len) {
                throw new ProtocolException('COBS decode: unexpected end of data');
            }

            $result .= substr($data, $i, $literalCount);
            $i += $literalCount;

            if ($code < 0xFF && $i < $len) {
                $result .= "\x00";
            }
        }

        return $result;
    }

    /**
     * Encode data using COBS (replace all 0x00 bytes with run-length pointers).
     */
    public static function encode(string $data): string
    {
        $len = strlen($data);
        $result = '';
        $groupStart = 0;

        while ($groupStart <= $len) {
            $nextZero = strpos($data, "\x00", $groupStart);

            if ($nextZero === false) {
                // No more zeros — encode remaining data in blocks of 254
                $remaining = $len - $groupStart;

                while ($remaining > 254) {
                    $result .= chr(0xFF) . substr($data, $groupStart, 254);
                    $groupStart += 254;
                    $remaining -= 254;
                }

                /** @var int<0, 254> $remaining */
                $result .= chr($remaining + 1) . substr($data, $groupStart, $remaining);
                break;
            }

            $blockLen = $nextZero - $groupStart;

            while ($blockLen > 254) {
                $result .= chr(0xFF) . substr($data, $groupStart, 254);
                $groupStart += 254;
                $blockLen -= 254;
            }

            /** @var int<0, 254> $blockLen */
            $result .= chr($blockLen + 1) . substr($data, $groupStart, $blockLen);
            $groupStart = $nextZero + 1; // skip the 0x00 byte
        }

        return $result;
    }
}

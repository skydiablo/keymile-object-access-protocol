<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport\Mtp;

use SkyDiablo\Keymile\Koap\Exception\ProtocolException;

/**
 * Encodes outgoing MTP frames (with COBS framing) and decodes incoming payloads.
 *
 * Each message on the wire is a COBS frame containing a 12-byte MTP header
 * followed by the application payload. Compressed payloads are standard zlib
 * streams decompressible with gzuncompress().
 */
class MtpCodec
{
    private const int MTP_PAYLOAD_TYPE_KOAP = 1;
    private const int MTP_VERSION = 4;

    private int $messageNumber = 0;

    /**
     * Build a COBS-framed MTP message ready to be sent over TCP.
     */
    public function encode(MtpMessageType $type, string $payload, int $version = self::MTP_VERSION): string
    {
        $this->messageNumber++;

        $header = $this->buildMtpHeader($type, false, $this->messageNumber, $version);
        $message = $header . $payload;

        $cobsEncoded = CobsCodec::encode($message);
        $cobsLen = strlen($cobsEncoded) + MtpFrame::COBS_FRAME_END_SIZE;

        // Frame: [0x00][START_OF_FRAME][len_hi][len_lo][cobs_data][0x00][END_OF_FRAME]
        return "\x00" . chr(MtpFrame::COBS_START_OF_FRAME)
            . pack('n', $cobsLen)
            . $cobsEncoded
            . "\x00" . chr(MtpFrame::COBS_END_OF_FRAME);
    }

    /**
     * Decode a compressed MTP frame payload to XML.
     * Returns the payload as-is if not compressed.
     */
    public function decodePayload(MtpFrame $frame): string
    {
        if (!$frame->isCompressed()) {
            return $frame->payload;
        }

        $result = @gzuncompress($frame->payload);
        if ($result === false) {
            throw new ProtocolException('Failed to decompress MTP payload');
        }

        return $result;
    }

    public function getMessageNumber(): int
    {
        return $this->messageNumber;
    }

    public function setMessageNumber(int $messageNumber): void
    {
        $this->messageNumber = $messageNumber;
    }

    private function buildMtpHeader(
        MtpMessageType $type,
        bool $fromNE,
        int $messageNumber,
        int $version,
    ): string {
        $command = $type->value | ($fromNE ? 0x80 : 0x00);

        return pack(
            'CCCCN',
            $command,
            self::MTP_PAYLOAD_TYPE_KOAP,
            MtpFrame::COMPRESSION_RAW,
            $version,
            $messageNumber,
        ) . "\x00\x00\x00\x00"; // 4 bytes extensions
    }
}

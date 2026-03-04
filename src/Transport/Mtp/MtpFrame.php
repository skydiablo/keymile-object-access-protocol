<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport\Mtp;

/**
 * Represents a decoded MTP frame.
 *
 * Wire format (COBS framing):
 *   [0x00] [frame_type] [2B length BE] [COBS-encoded payload] [0x00] [end_code]
 *
 * After COBS decoding, the payload contains:
 *   [12B MTP header] [application payload]
 *
 * MTP header layout (12 bytes):
 *   [0]    command byte: bit 7 = direction (0=toNE, 1=fromNE), bits 0-6 = request/response type
 *   [1]    payload type: 1=KOAP, 2=KFTP, 3=BINARY
 *   [2]    compression: 1=uncompressed, 2=zlib
 *   [3]    MTP version
 *   [4-7]  message number (big-endian)
 *   [8-11] extensions (reserved, all zeros)
 */
readonly class MtpFrame
{
    public const int COBS_FRAME_START_SIZE = 4;
    public const int COBS_FRAME_END_SIZE = 2;
    public const int MTP_HEADER_SIZE = 12;
    public const int COMPRESSION_RAW = 0x01;
    public const int COMPRESSION_ZLIB = 0x02;

    public const int COBS_START_OF_FRAME = 1;
    public const int COBS_SEGMENT_BOUNDARY = 2;
    public const int COBS_END_OF_FRAME = 0;

    public function __construct(
        public MtpMessageType $type,
        public int $messageNumber,
        public int $compression,
        public string $payload,
    ) {
    }

    public function isCompressed(): bool
    {
        return $this->compression === self::COMPRESSION_ZLIB;
    }
}

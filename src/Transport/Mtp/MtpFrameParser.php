<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport\Mtp;

use SkyDiablo\Keymile\Koap\Exception\ProtocolException;

/**
 * Parses COBS-framed MTP messages from a TCP byte stream.
 *
 * Frame structure on the wire:
 *   [0x00] [start_code] [length_hi] [length_lo] [COBS-encoded data] [0x00] [end_code]
 *
 * start_code: 1 = START_OF_FRAME, 2 = SEGMENT_BOUNDARY (continuation)
 * end_code:   0 = END_OF_FRAME (last segment), 2 = SEGMENT_BOUNDARY (more to follow)
 *
 * Large MTP messages may be split across multiple COBS segments. The parser
 * accumulates decoded data from all segments until END_OF_FRAME is received.
 */
class MtpFrameParser
{
    private string $buffer = '';

    /** Accumulated COBS-decoded data across segments of a multi-part message. */
    private string $pendingMessage = '';

    /**
     * Feed raw TCP data and extract complete MTP frames.
     *
     * @return list<MtpFrame>
     */
    public function feed(string $data): array
    {
        $this->buffer .= $data;
        $frames = [];

        while (strlen($this->buffer) >= MtpFrame::COBS_FRAME_START_SIZE) {
            if ($this->buffer[0] !== "\x00") {
                $pos = strpos($this->buffer, "\x00");
                if ($pos === false) {
                    $this->buffer = '';
                    break;
                }
                $this->buffer = substr($this->buffer, $pos);
                continue;
            }

            $startCode = ord($this->buffer[1]);
            $frameLength = unpack('n', $this->buffer, 2);
            if ($frameLength === false) {
                break;
            }

            /** @var int $length */
            $length = $frameLength[1];
            $totalSize = MtpFrame::COBS_FRAME_START_SIZE + $length;

            if (strlen($this->buffer) < $totalSize) {
                break;
            }

            $cobsDataLen = $length - MtpFrame::COBS_FRAME_END_SIZE;
            $cobsData = substr($this->buffer, MtpFrame::COBS_FRAME_START_SIZE, $cobsDataLen);

            // Read the end_code (last byte of the frame)
            $endCode = ord($this->buffer[$totalSize - 1]);

            $this->buffer = substr($this->buffer, $totalSize);

            if ($startCode === MtpFrame::COBS_START_OF_FRAME) {
                $this->pendingMessage = '';
            }

            try {
                $this->pendingMessage .= CobsCodec::decode($cobsData);
            } catch (\Throwable $e) {
                $this->pendingMessage = '';
                throw new ProtocolException('COBS decode failed: ' . $e->getMessage(), 0, $e);
            }

            if ($endCode === MtpFrame::COBS_SEGMENT_BOUNDARY) {
                continue;
            }

            // END_OF_FRAME — message is complete
            $decoded = $this->pendingMessage;
            $this->pendingMessage = '';

            $frame = $this->parseDecodedMessage($decoded);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    public function reset(): void
    {
        $this->buffer = '';
        $this->pendingMessage = '';
    }

    private function parseDecodedMessage(string $data): ?MtpFrame
    {
        if (strlen($data) < MtpFrame::MTP_HEADER_SIZE) {
            return null;
        }

        $command = ord($data[0]);
        $requestType = $command & 0x7F;

        $compression = ord($data[2]);
        $messageNumber = unpack('N', $data, 4);
        if ($messageNumber === false) {
            return null;
        }

        $type = MtpMessageType::tryFrom($requestType);
        if ($type === null) {
            return null;
        }

        $payload = substr($data, MtpFrame::MTP_HEADER_SIZE);

        return new MtpFrame(
            type: $type,
            messageNumber: $messageNumber[1],
            compression: $compression,
            payload: $payload,
        );
    }
}

<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Tests\Unit\Transport\Mtp;

use SkyDiablo\Keymile\Koap\Exception\ProtocolException;
use SkyDiablo\Keymile\Koap\Transport\Mtp\CobsCodec;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpCodec;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpFrame;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpFrameParser;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpMessageType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MtpCodecTest extends TestCase
{
    private MtpCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new MtpCodec();
    }

    #[Test]
    public function encodeProducesCobsFrame(): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><request version="1" seq="1"></request>';

        $raw = $this->codec->encode(MtpMessageType::Init, $xml);

        // Must start with 0x00 (COBS delimiter) + 0x01 (START_OF_FRAME)
        $this->assertSame("\x00", $raw[0]);
        $this->assertSame(chr(MtpFrame::COBS_START_OF_FRAME), $raw[1]);

        // Must end with 0x00 (delimiter) + 0x00 (END_OF_FRAME)
        $this->assertSame("\x00\x00", substr($raw, -2));
    }

    #[Test]
    public function encodeIncrementsMessageNumber(): void
    {
        $this->codec->encode(MtpMessageType::Init, 'test');
        $this->assertSame(1, $this->codec->getMessageNumber());

        $this->codec->encode(MtpMessageType::Login, 'test');
        $this->assertSame(2, $this->codec->getMessageNumber());

        $this->codec->encode(MtpMessageType::Data, 'test');
        $this->assertSame(3, $this->codec->getMessageNumber());
    }

    #[Test]
    public function encodedFrameCanBeParsed(): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><request version="1" seq="1"></request>';

        $raw = $this->codec->encode(MtpMessageType::Data, $xml);

        $parser = new MtpFrameParser();
        $frames = $parser->feed($raw);

        $this->assertCount(1, $frames);
        $this->assertSame(MtpMessageType::Data, $frames[0]->type);
        $this->assertSame(1, $frames[0]->messageNumber);
        $this->assertSame(MtpFrame::COMPRESSION_RAW, $frames[0]->compression);
        $this->assertSame($xml, $frames[0]->payload);
    }

    #[Test]
    public function decodePayloadReturnsRawXmlUnchanged(): void
    {
        $xml = '<response version="1"><mdomain id="main"></mdomain></response>';

        $frame = new MtpFrame(
            type: MtpMessageType::Data,
            messageNumber: 3,
            compression: MtpFrame::COMPRESSION_RAW,
            payload: $xml,
        );

        $this->assertSame($xml, $this->codec->decodePayload($frame));
    }

    #[Test]
    public function decodePayloadDecompressesZlibContent(): void
    {
        $xml = '<response version="1" seq="3"><mdomain id="cfgm"><operation seq="1" name="test">'
            . '<execution status="success"/></operation></mdomain></response>';

        $compressed = gzcompress($xml, 1);
        $this->assertNotFalse($compressed);

        $frame = new MtpFrame(
            type: MtpMessageType::Data,
            messageNumber: 3,
            compression: MtpFrame::COMPRESSION_ZLIB,
            payload: $compressed,
        );

        $this->assertSame($xml, $this->codec->decodePayload($frame));
    }

    #[Test]
    public function decodePayloadThrowsForInvalidCompressedData(): void
    {
        $frame = new MtpFrame(
            type: MtpMessageType::Data,
            messageNumber: 3,
            compression: MtpFrame::COMPRESSION_ZLIB,
            payload: "\x78\x01invalid_data\x00\x00\x00\x00",
        );

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Failed to decompress');

        $this->codec->decodePayload($frame);
    }

    #[Test]
    public function setMessageNumberOverridesCounter(): void
    {
        $this->codec->setMessageNumber(10);
        $this->assertSame(10, $this->codec->getMessageNumber());

        $this->codec->encode(MtpMessageType::Data, 'test');
        $this->assertSame(11, $this->codec->getMessageNumber());
    }

    #[Test]
    public function encodeSetsCorrectMessageType(): void
    {
        $parser = new MtpFrameParser();

        $types = [
            MtpMessageType::Init,
            MtpMessageType::Login,
            MtpMessageType::Data,
            MtpMessageType::Loopback,
        ];

        foreach ($types as $type) {
            $raw = $this->codec->encode($type, 'test');
            $frames = $parser->feed($raw);
            $this->assertCount(1, $frames);
            $this->assertSame($type, $frames[0]->type);
            $parser->reset();
        }
    }

    #[Test]
    public function encodeNeverCompressesClientMessages(): void
    {
        $parser = new MtpFrameParser();

        $raw = $this->codec->encode(MtpMessageType::Data, str_repeat('x', 1000));
        $frames = $parser->feed($raw);

        $this->assertCount(1, $frames);
        $this->assertFalse($frames[0]->isCompressed());
    }

    #[Test]
    public function encodedFrameContainsNoCobsZerosInPayload(): void
    {
        $xml = '<?xml version="1.0"?><test/>';
        $raw = $this->codec->encode(MtpMessageType::Init, $xml);

        // The inner COBS-encoded region (between start and end markers) must not contain 0x00
        $cobsLen = unpack('n', $raw, 2);
        $this->assertNotFalse($cobsLen);
        $innerLen = $cobsLen[1] - MtpFrame::COBS_FRAME_END_SIZE;
        $cobsData = substr($raw, MtpFrame::COBS_FRAME_START_SIZE, $innerLen);

        $this->assertStringNotContainsString("\x00", $cobsData);
    }

    #[Test]
    public function roundTripWithPayloadContainingZeroBytes(): void
    {
        $payload = "data\x00with\x00nulls";

        $raw = $this->codec->encode(MtpMessageType::Data, $payload);

        $parser = new MtpFrameParser();
        $frames = $parser->feed($raw);

        $this->assertCount(1, $frames);
        $this->assertSame($payload, $frames[0]->payload);
    }
}

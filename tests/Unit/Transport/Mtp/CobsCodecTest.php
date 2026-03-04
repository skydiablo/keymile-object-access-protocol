<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Tests\Unit\Transport\Mtp;

use SkyDiablo\Keymile\Koap\Exception\ProtocolException;
use SkyDiablo\Keymile\Koap\Transport\Mtp\CobsCodec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CobsCodecTest extends TestCase
{
    #[Test]
    public function encodesEmptyString(): void
    {
        $this->assertSame("\x01", CobsCodec::encode(''));
    }

    #[Test]
    public function decodesEmptyString(): void
    {
        $this->assertSame('', CobsCodec::decode("\x01"));
    }

    #[Test]
    public function roundTripWithNoZeros(): void
    {
        $data = "Hello World";
        $this->assertSame($data, CobsCodec::decode(CobsCodec::encode($data)));
    }

    #[Test]
    public function roundTripWithSingleZero(): void
    {
        $data = "\x00";
        $this->assertSame($data, CobsCodec::decode(CobsCodec::encode($data)));
    }

    #[Test]
    public function roundTripWithConsecutiveZeros(): void
    {
        $data = "\x00\x00\x00";
        $this->assertSame($data, CobsCodec::decode(CobsCodec::encode($data)));
    }

    #[Test]
    public function roundTripWithMixedData(): void
    {
        $data = "\x01\x02\x00\x03\x04\x05\x00\x06";
        $this->assertSame($data, CobsCodec::decode(CobsCodec::encode($data)));
    }

    #[Test]
    public function roundTripWithLargeBlocksOver254(): void
    {
        $data = str_repeat("\xFF", 300);
        $this->assertSame($data, CobsCodec::decode(CobsCodec::encode($data)));
    }

    #[Test]
    public function roundTripWithBinaryData(): void
    {
        $data = '';
        for ($i = 0; $i < 256; $i++) {
            $data .= chr($i);
        }
        $this->assertSame($data, CobsCodec::decode(CobsCodec::encode($data)));
    }

    #[Test]
    public function roundTripMtpHeader(): void
    {
        // Simulate a 12-byte MTP header with zeros (extensions field)
        $header = "\x01\x01\x01\x04\x00\x00\x00\x01\x00\x00\x00\x00";
        $payload = "<?xml version=\"1.0\"?><test/>";
        $data = $header . $payload;

        $encoded = CobsCodec::encode($data);
        $decoded = CobsCodec::decode($encoded);

        $this->assertSame($data, $decoded);
        $this->assertStringNotContainsString("\x00", $encoded);
    }

    #[Test]
    public function encodeRemovesAllZeroBytes(): void
    {
        $data = "\x00\x01\x00\x02\x00";
        $encoded = CobsCodec::encode($data);

        $this->assertStringNotContainsString("\x00", $encoded);
    }

    #[Test]
    public function decodeTruncatedDataThrows(): void
    {
        $this->expectException(ProtocolException::class);

        // Code byte says 5 literals follow, but only 2 bytes available
        CobsCodec::decode("\x05\x01\x02");
    }

    #[Test]
    public function roundTripWithCompressedPayload(): void
    {
        $xml = '<response version="1"><mdomain id="main"></mdomain></response>';
        $compressed = gzcompress($xml, 1);
        $this->assertNotFalse($compressed);

        // Compressed data typically contains null bytes
        $header = "\x84\x01\x02\x04\x00\x00\x00\x03\x00\x00\x00\x00";
        $data = $header . $compressed;

        $encoded = CobsCodec::encode($data);
        $decoded = CobsCodec::decode($encoded);

        $this->assertSame($data, $decoded);
    }
}

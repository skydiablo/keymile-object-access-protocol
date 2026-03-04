<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Tests\Unit\Transport\Mtp;

use SkyDiablo\Keymile\Koap\Transport\Mtp\CobsCodec;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpFrame;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpFrameParser;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpMessageType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MtpFrameParserTest extends TestCase
{
    private MtpFrameParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MtpFrameParser();
    }

    #[Test]
    public function parsesCompleteInitResponseFrame(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<initresponse version="2">' . "\n"
            . '  <mtpversion>4</mtpversion>' . "\n"
            . '</initresponse>';

        $wire = $this->buildCobsFrame(MtpMessageType::Init, true, 0, MtpFrame::COMPRESSION_RAW, $xml);
        $frames = $this->parser->feed($wire);

        $this->assertCount(1, $frames);
        $this->assertSame(MtpMessageType::Init, $frames[0]->type);
        $this->assertSame(0, $frames[0]->messageNumber);
        $this->assertSame(MtpFrame::COMPRESSION_RAW, $frames[0]->compression);
        $this->assertSame($xml, $frames[0]->payload);
    }

    #[Test]
    public function parsesLoginResponseFrame(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<login_resp ver="1"><auth>success</auth><sessionId>42</sessionId></login_resp>';

        $wire = $this->buildCobsFrame(MtpMessageType::Login, true, 0, MtpFrame::COMPRESSION_RAW, $xml);
        $frames = $this->parser->feed($wire);

        $this->assertCount(1, $frames);
        $this->assertSame(MtpMessageType::Login, $frames[0]->type);
        $this->assertStringContainsString('success', $frames[0]->payload);
    }

    #[Test]
    public function parsesCompressedDataResponseFrame(): void
    {
        $xml = '<response version="1" seq="3"><mdomain id="main"></mdomain></response>';
        $compressed = gzcompress($xml, 1);
        $this->assertNotFalse($compressed);

        $wire = $this->buildCobsFrame(MtpMessageType::Data, true, 3, MtpFrame::COMPRESSION_ZLIB, $compressed);
        $frames = $this->parser->feed($wire);

        $this->assertCount(1, $frames);
        $this->assertTrue($frames[0]->isCompressed());
        $this->assertSame(3, $frames[0]->messageNumber);
    }

    #[Test]
    public function parsesLoopbackFrame(): void
    {
        $wire = $this->buildCobsFrame(MtpMessageType::Loopback, true, 5, MtpFrame::COMPRESSION_RAW, 'MTP keepalive');
        $frames = $this->parser->feed($wire);

        $this->assertCount(1, $frames);
        $this->assertSame(MtpMessageType::Loopback, $frames[0]->type);
        $this->assertSame('MTP keepalive', $frames[0]->payload);
    }

    #[Test]
    public function handlesFragmentedInput(): void
    {
        $xml = '<response version="1" seq="5"><mdomain id="main"></mdomain></response>';
        $wire = $this->buildCobsFrame(MtpMessageType::Data, true, 5, MtpFrame::COMPRESSION_RAW, $xml);

        $splitPoint = (int) (strlen($wire) / 2);

        $frames = $this->parser->feed(substr($wire, 0, $splitPoint));
        $this->assertCount(0, $frames);

        $frames = $this->parser->feed(substr($wire, $splitPoint));
        $this->assertCount(1, $frames);
        $this->assertSame($xml, $frames[0]->payload);
    }

    #[Test]
    public function parsesMultipleFramesInSingleChunk(): void
    {
        $xml1 = '<response version="1" seq="3"><mdomain id="cfgm"></mdomain></response>';
        $xml2 = '<response version="1" seq="4"><mdomain id="main"></mdomain></response>';

        $wire1 = $this->buildCobsFrame(MtpMessageType::Data, true, 3, MtpFrame::COMPRESSION_RAW, $xml1);
        $wire2 = $this->buildCobsFrame(MtpMessageType::Data, true, 4, MtpFrame::COMPRESSION_RAW, $xml2);

        $frames = $this->parser->feed($wire1 . $wire2);

        $this->assertCount(2, $frames);
        $this->assertSame(3, $frames[0]->messageNumber);
        $this->assertSame(4, $frames[1]->messageNumber);
    }

    #[Test]
    public function resetClearsBuffer(): void
    {
        $xml = '<response version="1" seq="3"><mdomain id="main"></mdomain></response>';
        $wire = $this->buildCobsFrame(MtpMessageType::Data, true, 3, MtpFrame::COMPRESSION_RAW, $xml);

        $this->parser->feed(substr($wire, 0, 10));
        $this->parser->reset();

        $frames = $this->parser->feed($wire);
        $this->assertCount(1, $frames);
    }

    #[Test]
    public function skipsGarbageBeforeFrameDelimiter(): void
    {
        $xml = '<response version="1" seq="3"><mdomain id="main"></mdomain></response>';
        $wire = $this->buildCobsFrame(MtpMessageType::Data, true, 3, MtpFrame::COMPRESSION_RAW, $xml);

        $garbage = "\xFF\xFE\xFD";
        $frames = $this->parser->feed($garbage . $wire);

        $this->assertCount(1, $frames);
        $this->assertSame($xml, $frames[0]->payload);
    }

    /**
     * Build a COBS-framed MTP wire message.
     */
    private function buildCobsFrame(
        MtpMessageType $type,
        bool $fromNE,
        int $messageNumber,
        int $compression,
        string $payload,
    ): string {
        $command = $type->value | ($fromNE ? 0x80 : 0x00);

        $mtpHeader = pack('CCCCN', $command, 1, $compression, 4, $messageNumber)
            . "\x00\x00\x00\x00";

        $message = $mtpHeader . $payload;
        $cobsEncoded = CobsCodec::encode($message);

        $cobsLen = strlen($cobsEncoded) + MtpFrame::COBS_FRAME_END_SIZE;

        return "\x00" . chr(MtpFrame::COBS_START_OF_FRAME)
            . pack('n', $cobsLen)
            . $cobsEncoded
            . "\x00" . chr(MtpFrame::COBS_END_OF_FRAME);
    }
}

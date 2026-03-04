<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Tests\Unit\Transport\Frame;

use SkyDiablo\Keymile\Koap\Transport\Frame\RawXmlFrameParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RawXmlFrameParserTest extends TestCase
{
    private RawXmlFrameParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RawXmlFrameParser();
    }

    #[Test]
    public function parseCompleteResponse(): void
    {
        $xml = '<response version="1" seq="1" destaddr="/"><mdomain id="main"><operation seq="1" name="discover"><execution status="success"/></operation></mdomain></response>';

        $messages = $this->parser->feed($xml);

        self::assertCount(1, $messages);
        self::assertSame($xml, $messages[0]);
    }

    #[Test]
    public function parseFragmentedResponse(): void
    {
        $part1 = '<response version="1" seq="1" destaddr="/">';
        $part2 = '<mdomain id="main"><operation seq="1" name="test">';
        $part3 = '<execution status="success"/></operation></mdomain></response>';

        self::assertSame([], $this->parser->feed($part1));
        self::assertSame([], $this->parser->feed($part2));

        $messages = $this->parser->feed($part3);
        self::assertCount(1, $messages);
        self::assertStringContainsString('<response', $messages[0]);
        self::assertStringContainsString('</response>', $messages[0]);
    }

    #[Test]
    public function parseMultipleMessagesInOneChunk(): void
    {
        $msg1 = '<response version="1" seq="1" destaddr="/"><mdomain id="main"><operation seq="1" name="a"><execution status="success"/></operation></mdomain></response>';
        $msg2 = '<response version="1" seq="2" destaddr="/"><mdomain id="main"><operation seq="2" name="b"><execution status="success"/></operation></mdomain></response>';

        $messages = $this->parser->feed($msg1 . $msg2);

        self::assertCount(2, $messages);
        self::assertStringContainsString('seq="1"', $messages[0]);
        self::assertStringContainsString('seq="2"', $messages[1]);
    }

    #[Test]
    public function resetClearsBuffer(): void
    {
        $this->parser->feed('<response version="1" seq="1"');
        $this->parser->reset();

        $messages = $this->parser->feed('</response>');
        self::assertCount(1, $messages);
        self::assertSame('</response>', $messages[0]);
    }
}

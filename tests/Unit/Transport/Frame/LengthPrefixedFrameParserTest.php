<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Tests\Unit\Transport\Frame;

use SkyDiablo\Keymile\Koap\Transport\Frame\LengthPrefixedFrameParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LengthPrefixedFrameParserTest extends TestCase
{
    private LengthPrefixedFrameParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LengthPrefixedFrameParser();
    }

    #[Test]
    public function parseSingleFrame(): void
    {
        $payload = '<response/>';
        $frame = pack('N', strlen($payload)) . $payload;

        $messages = $this->parser->feed($frame);

        self::assertCount(1, $messages);
        self::assertSame('<response/>', $messages[0]);
    }

    #[Test]
    public function parseMultipleFrames(): void
    {
        $p1 = '<msg1/>';
        $p2 = '<msg2/>';
        $data = pack('N', strlen($p1)) . $p1 . pack('N', strlen($p2)) . $p2;

        $messages = $this->parser->feed($data);

        self::assertCount(2, $messages);
        self::assertSame('<msg1/>', $messages[0]);
        self::assertSame('<msg2/>', $messages[1]);
    }

    #[Test]
    public function parseFragmentedFrame(): void
    {
        $payload = '<response version="1">content</response>';
        $frame = pack('N', strlen($payload)) . $payload;

        $part1 = substr($frame, 0, 6);
        $part2 = substr($frame, 6);

        self::assertSame([], $this->parser->feed($part1));

        $messages = $this->parser->feed($part2);
        self::assertCount(1, $messages);
        self::assertSame($payload, $messages[0]);
    }

    #[Test]
    public function incompleteHeaderBuffered(): void
    {
        self::assertSame([], $this->parser->feed("\x00\x00"));
        self::assertSame([], $this->parser->feed("\x00"));

        $payload = '<r/>';
        $remaining = substr(pack('N', strlen($payload)), 3) . $payload;

        $messages = $this->parser->feed($remaining);
        self::assertCount(1, $messages);
        self::assertSame('<r/>', $messages[0]);
    }
}

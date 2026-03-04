<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Tests\Unit\Transport\Frame;

use SkyDiablo\Keymile\Koap\Transport\Frame\NullTerminatedFrameParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class NullTerminatedFrameParserTest extends TestCase
{
    private NullTerminatedFrameParser $parser;

    protected function setUp(): void
    {
        $this->parser = new NullTerminatedFrameParser();
    }

    #[Test]
    public function parseSingleMessage(): void
    {
        $messages = $this->parser->feed("<response/>\x00");

        self::assertCount(1, $messages);
        self::assertSame('<response/>', $messages[0]);
    }

    #[Test]
    public function parseMultipleMessages(): void
    {
        $messages = $this->parser->feed("<msg1/>\x00<msg2/>\x00");

        self::assertCount(2, $messages);
        self::assertSame('<msg1/>', $messages[0]);
        self::assertSame('<msg2/>', $messages[1]);
    }

    #[Test]
    public function parseFragmentedMessage(): void
    {
        self::assertSame([], $this->parser->feed('<response'));
        self::assertSame([], $this->parser->feed(' version="1">'));

        $messages = $this->parser->feed("</response>\x00");
        self::assertCount(1, $messages);
        self::assertSame('<response version="1"></response>', $messages[0]);
    }

    #[Test]
    public function skipEmptyMessages(): void
    {
        $messages = $this->parser->feed("\x00\x00<msg/>\x00\x00");

        self::assertCount(1, $messages);
        self::assertSame('<msg/>', $messages[0]);
    }
}

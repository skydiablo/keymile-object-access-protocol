<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Tests\Unit\Message;

use SkyDiablo\Keymile\Koap\Message\Operation;
use SkyDiablo\Keymile\Koap\Message\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    #[Test]
    public function constructWithDefaults(): void
    {
        $request = new Request(
            destAddr: '/unit-1',
            mDomain: 'main',
            operations: [],
        );

        self::assertSame('/unit-1', $request->destAddr);
        self::assertSame('main', $request->mDomain);
        self::assertSame(1, $request->version);
        self::assertSame(1, $request->seq);
        self::assertSame([], $request->operations);
    }

    #[Test]
    public function constructWithCustomValues(): void
    {
        $op = new Operation(name: 'getLabel', seq: 5);
        $request = new Request(
            destAddr: '/unit-11/port-3',
            mDomain: 'cfgm',
            operations: [$op],
            version: 2,
            seq: 42,
        );

        self::assertSame('/unit-11/port-3', $request->destAddr);
        self::assertSame('cfgm', $request->mDomain);
        self::assertSame(2, $request->version);
        self::assertSame(42, $request->seq);
        self::assertCount(1, $request->operations);
        self::assertSame($op, $request->operations[0]);
    }
}

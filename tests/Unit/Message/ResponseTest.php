<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Tests\Unit\Message;

use SkyDiablo\Keymile\Koap\Message\Operation;
use SkyDiablo\Keymile\Koap\Message\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    #[Test]
    public function getOperationByName(): void
    {
        $op1 = new Operation(name: 'getLabel', seq: 1, executionStatus: 'success');
        $op2 = new Operation(name: 'getAlarm', seq: 2, executionStatus: 'success');

        $response = new Response(
            destAddr: '/',
            mDomain: 'main',
            operations: [$op1, $op2],
        );

        self::assertSame($op1, $response->getOperation('getLabel'));
        self::assertSame($op2, $response->getOperation('getAlarm'));
        self::assertNull($response->getOperation('nonExistent'));
    }

    #[Test]
    public function getFirstOperation(): void
    {
        $op = new Operation(name: 'discover', seq: 1, executionStatus: 'success');

        $response = new Response(
            destAddr: '/',
            mDomain: 'main',
            operations: [$op],
        );

        self::assertSame($op, $response->getFirstOperation());
    }

    #[Test]
    public function getFirstOperationReturnsNullWhenEmpty(): void
    {
        $response = new Response(
            destAddr: '/',
            mDomain: 'main',
            operations: [],
        );

        self::assertNull($response->getFirstOperation());
    }

    #[Test]
    public function isSuccessfulWithAllSuccessOperations(): void
    {
        $response = new Response(
            destAddr: '/',
            mDomain: 'main',
            operations: [
                new Operation(name: 'op1', seq: 1, executionStatus: 'success'),
                new Operation(name: 'op2', seq: 2, executionStatus: 'success'),
            ],
        );

        self::assertTrue($response->isSuccessful());
    }

    #[Test]
    public function isSuccessfulReturnsFalseOnError(): void
    {
        $response = new Response(
            destAddr: '/',
            mDomain: 'main',
            operations: [
                new Operation(name: 'op1', seq: 1, executionStatus: 'success'),
                new Operation(name: 'op2', seq: 2, executionStatus: 'proc_error'),
            ],
        );

        self::assertFalse($response->isSuccessful());
    }

    #[Test]
    public function isSuccessfulWithNoExecutionStatus(): void
    {
        $response = new Response(
            destAddr: '/',
            mDomain: 'main',
            operations: [
                new Operation(name: 'op1', seq: 1),
            ],
        );

        self::assertTrue($response->isSuccessful());
    }
}

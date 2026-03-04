<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Tests\Unit\Message;

use SkyDiablo\Keymile\Koap\Exception\ProtocolException;
use SkyDiablo\Keymile\Koap\Message\Operation;
use SkyDiablo\Keymile\Koap\Message\Request;
use SkyDiablo\Keymile\Koap\Message\Serializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase
{
    private Serializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new Serializer();
    }

    #[Test]
    public function serializeSetLabelRequest(): void
    {
        $request = new Request(
            destAddr: '/unit-1/port-1',
            mDomain: 'main',
            operations: [
                new Operation(
                    name: 'setLabel',
                    seq: 1,
                    payload: [
                        'label' => [
                            'user' => 'User1',
                            'service' => 'Service1',
                            'description' => 'Description1',
                        ],
                    ],
                ),
            ],
        );

        $xml = $this->serializer->serializeRequest($request);

        self::assertStringContainsString('<request', $xml);
        self::assertStringContainsString('destAddr="/unit-1/port-1"', $xml);
        self::assertStringContainsString('version="1"', $xml);
        self::assertStringContainsString('<mdomain id="main">', $xml);
        self::assertStringContainsString('name="setLabel"', $xml);
        self::assertStringContainsString('<user>User1</user>', $xml);
        self::assertStringContainsString('<service>Service1</service>', $xml);
        self::assertStringContainsString('<description>Description1</description>', $xml);
    }

    #[Test]
    public function serializeEmptyPayloadRequest(): void
    {
        $request = new Request(
            destAddr: '/',
            mDomain: 'main',
            operations: [
                new Operation(name: 'discover', seq: 1),
            ],
        );

        $xml = $this->serializer->serializeRequest($request);

        self::assertStringContainsString('destAddr="/"', $xml);
        self::assertStringContainsString('name="discover"', $xml);
    }

    #[Test]
    public function serializeRequestRoundTrip(): void
    {
        $original = new Request(
            destAddr: '/unit-11',
            mDomain: 'cfgm',
            operations: [
                new Operation(
                    name: 'setTimezone',
                    seq: 3,
                    payload: ['timezone' => 'Europe/Berlin'],
                ),
            ],
            seq: 3,
        );

        $xml = $this->serializer->serializeRequest($original);
        $restored = $this->serializer->deserializeRequest($xml);

        self::assertSame($original->destAddr, $restored->destAddr);
        self::assertSame($original->mDomain, $restored->mDomain);
        self::assertSame($original->version, $restored->version);
        self::assertSame($original->seq, $restored->seq);
        self::assertCount(1, $restored->operations);
        self::assertSame('setTimezone', $restored->operations[0]->name);
        self::assertSame(3, $restored->operations[0]->seq);
        self::assertSame('Europe/Berlin', $restored->operations[0]->payload['timezone']);
    }

    #[Test]
    public function deserializeSuccessfulGetLabelResponse(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<response version="1" seq="1" destaddr="/unit-1/port-1">
  <mdomain id="main">
    <operation seq="1" name="getLabel">
      <execution status="success"/>
      <label>
        <user>User1</user>
        <service>Service1</service>
        <description>Description1</description>
      </label>
    </operation>
  </mdomain>
</response>
XML;

        $response = $this->serializer->deserializeResponse($xml);

        self::assertSame('/unit-1/port-1', $response->destAddr);
        self::assertSame('main', $response->mDomain);
        self::assertSame(1, $response->version);
        self::assertSame(1, $response->seq);
        self::assertTrue($response->isSuccessful());

        $operation = $response->getOperation('getLabel');
        self::assertNotNull($operation);
        self::assertSame('success', $operation->executionStatus);
        self::assertTrue($operation->isSuccessful());

        self::assertSame('User1', $operation->payload['label']['user']);
        self::assertSame('Service1', $operation->payload['label']['service']);
        self::assertSame('Description1', $operation->payload['label']['description']);
    }

    #[Test]
    public function deserializeErrorResponse(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<response version="1" seq="5" destaddr="/unit-99">
  <mdomain id="main">
    <operation seq="5" name="getLabel">
      <execution status="proc_error"/>
    </operation>
  </mdomain>
</response>
XML;

        $response = $this->serializer->deserializeResponse($xml);

        self::assertFalse($response->isSuccessful());

        $operation = $response->getFirstOperation();
        self::assertNotNull($operation);
        self::assertSame('proc_error', $operation->executionStatus);
        self::assertFalse($operation->isSuccessful());
    }

    #[Test]
    public function deserializeResponseWithLowercaseDestaddrAttribute(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<response id="main" version="1" seq="1" destaddr="/unit-1/port-1">
  <mdomain name="getLabel" id="main">
    <operation seq="1" name="getLabel">
      <execution status="success"/>
      <label>
        <user>x</user>
        <service>y</service>
        <description>z</description>
      </label>
    </operation>
  </mdomain>
</response>
XML;

        $response = $this->serializer->deserializeResponse($xml);

        self::assertSame('/unit-1/port-1', $response->destAddr);
        self::assertSame('main', $response->mDomain);
    }

    #[Test]
    public function deserializeInvalidXmlThrowsProtocolException(): void
    {
        $this->expectException(ProtocolException::class);
        $this->serializer->deserializeResponse('not xml');
    }

    #[Test]
    public function deserializeWrongRootElementThrowsProtocolException(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Expected root element "response"');
        $this->serializer->deserializeResponse('<notaresponse/>');
    }

    #[Test]
    public function deserializeMissingMdomainThrowsProtocolException(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Missing mdomain');
        $this->serializer->deserializeResponse('<response version="1" seq="1" destaddr="/"/>');
    }

    #[Test]
    public function serializeSpecialCharactersInPayload(): void
    {
        $request = new Request(
            destAddr: '/',
            mDomain: 'main',
            operations: [
                new Operation(
                    name: 'setLabel',
                    seq: 1,
                    payload: [
                        'label' => [
                            'user' => 'Test & <Special>',
                        ],
                    ],
                ),
            ],
        );

        $xml = $this->serializer->serializeRequest($request);

        self::assertStringContainsString('Test &amp; &lt;Special&gt;', $xml);

        $restored = $this->serializer->deserializeRequest($xml);
        self::assertSame(
            'Test & <Special>',
            $restored->operations[0]->payload['label']['user'],
        );
    }
}

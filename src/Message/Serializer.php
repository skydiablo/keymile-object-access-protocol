<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Message;

use DOMDocument;
use DOMElement;
use DOMNode;
use SkyDiablo\Keymile\Koap\Exception\ProtocolException;

class Serializer
{
    public function serializeRequest(Request $request): string
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('request');
        $root->setAttribute('version', (string) $request->version);
        $root->setAttribute('seq', (string) $request->seq);
        $root->setAttribute('destAddr', $request->destAddr);
        $doc->appendChild($root);

        $mdomain = $doc->createElement('mdomain');
        $mdomain->setAttribute('id', $request->mDomain);
        $root->appendChild($mdomain);

        foreach ($request->operations as $operation) {
            $opElement = $doc->createElement('operation');
            $opElement->setAttribute('seq', (string) $operation->seq);
            $opElement->setAttribute('name', $operation->name);
            $this->arrayToXml($doc, $opElement, $operation->payload);
            $mdomain->appendChild($opElement);
        }

        return $doc->saveXML() ?: throw new ProtocolException('Failed to serialize request to XML');
    }

    public function deserializeResponse(string $xml): Response
    {
        $doc = new DOMDocument();

        if (!@$doc->loadXML($xml)) {
            throw new ProtocolException('Failed to parse response XML');
        }

        $root = $doc->documentElement ?? throw new ProtocolException('Missing root element in response');

        if ($root->nodeName !== 'response') {
            throw new ProtocolException(sprintf(
                'Expected root element "response", got "%s"',
                $root->nodeName,
            ));
        }

        $version = (int) ($root->getAttribute('version') ?: '1');
        $seq = (int) ($root->getAttribute('seq') ?: '1');
        $destAddr = $root->getAttribute('destAddr') ?: $root->getAttribute('destaddr') ?: '';

        $mdomainElement = $this->getFirstChildElement($root, 'mdomain');
        if ($mdomainElement === null) {
            throw new ProtocolException('Missing mdomain element in response');
        }

        $mDomain = $mdomainElement->getAttribute('id');

        $operations = [];
        foreach ($mdomainElement->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'operation') {
                $operations[] = $this->parseOperation($child);
            }
        }

        return new Response(
            destAddr: $destAddr,
            mDomain: $mDomain,
            operations: $operations,
            version: $version,
            seq: $seq,
        );
    }

    /**
     * Parse a raw XML string into a Request object (useful for testing/debugging).
     */
    public function deserializeRequest(string $xml): Request
    {
        $doc = new DOMDocument();

        if (!@$doc->loadXML($xml)) {
            throw new ProtocolException('Failed to parse request XML');
        }

        $root = $doc->documentElement ?? throw new ProtocolException('Missing root element in request');

        if ($root->nodeName !== 'request') {
            throw new ProtocolException(sprintf(
                'Expected root element "request", got "%s"',
                $root->nodeName,
            ));
        }

        $version = (int) ($root->getAttribute('version') ?: '1');
        $seq = (int) ($root->getAttribute('seq') ?: '1');
        $destAddr = $root->getAttribute('destAddr') ?: $root->getAttribute('destaddr');

        $mdomainElement = $this->getFirstChildElement($root, 'mdomain');
        if ($mdomainElement === null) {
            throw new ProtocolException('Missing mdomain element in request');
        }

        $mDomain = $mdomainElement->getAttribute('id');

        $operations = [];
        foreach ($mdomainElement->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'operation') {
                $operations[] = $this->parseOperation($child);
            }
        }

        return new Request(
            destAddr: $destAddr,
            mDomain: $mDomain,
            operations: $operations,
            version: $version,
            seq: $seq,
        );
    }

    private function parseOperation(DOMElement $element): Operation
    {
        $name = $element->getAttribute('name');
        $seq = (int) ($element->getAttribute('seq') ?: '1');
        $executionStatus = null;
        /** @var array<string, mixed> $payload */
        $payload = [];

        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if ($child->nodeName === 'execution') {
                $executionStatus = $child->getAttribute('status');
                continue;
            }

            $payload[$child->nodeName] = $this->xmlToArray($child);
        }

        return new Operation(
            name: $name,
            seq: $seq,
            payload: $payload,
            executionStatus: $executionStatus,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToXml(DOMDocument $doc, DOMElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $doc->createElement($key);
                /** @var array<string, mixed> $value */
                $this->arrayToXml($doc, $child, $value);
                $parent->appendChild($child);
            } else {
                $child = $doc->createElement($key, htmlspecialchars((string) $value, ENT_XML1));
                $parent->appendChild($child);
            }
        }
    }

    /**
     * @return array<string, mixed>|string
     */
    private function xmlToArray(DOMElement $element): array|string
    {
        $hasChildElements = false;
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $hasChildElements = true;
                break;
            }
        }

        if (!$hasChildElements) {
            return $element->textContent;
        }

        $result = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $key = $child->nodeName;
                $value = $this->xmlToArray($child);

                if (!isset($result[$key])) {
                    $result[$key] = $value;
                } elseif (is_array($result[$key]) && array_is_list($result[$key])) {
                    $result[$key][] = $value;
                } else {
                    $result[$key] = [$result[$key], $value];
                }
            }
        }

        return $result;
    }

    private function getFirstChildElement(DOMNode $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === $name) {
                return $child;
            }
        }

        return null;
    }
}

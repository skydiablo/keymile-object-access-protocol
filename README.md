# Keymile KOAP Protocol Library

ReactPHP-based async client library for communicating with Keymile MileGate MSAN devices using the KOAP (Keymile Object Access Protocol) over the MTP (Message Transport Protocol) framing layer.

## Requirements

- PHP 8.4+
- ext-dom
- ext-zlib

## Installation

```bash
composer require skydiablo/koap
```

## Quick Start

```php
<?php

use SkyDiablo\Keymile\Koap\KoapClient;
use function React\Async\await;

require __DIR__ . '/vendor/autoload.php';

$client = new KoapClient('192.168.1.1', 5556, 'admin', 'secret');
$connection = await($client->connect());

// Discover the managed object tree
$root = await($connection->discover('/'));
echo sprintf("Connected to: %s (%s)\n", $root->moName, $root->moType);

// Read a property
$label = await($connection->get('/unit-1/port-1', 'main', 'Label'));
echo sprintf("Label: %s\n", $label['label']['user'] ?? '(empty)');

// Write a property
await($connection->set('/unit-1/port-1', 'main', 'Label', [
    'label' => [
        'user' => 'My Port',
        'service' => 'DSL',
        'description' => 'Customer line',
    ],
]));

$connection->close();
```

## Architecture

The library is built in four layers:

### MTP Transport Layer

The MileGate uses a proprietary binary framing protocol called MTP (Message Transport Protocol) on TCP port 5556. The `MtpTransport` handles the complete protocol stack:

**Wire Format:**

```
[2B magic: 0x0001] [2B payload length (BE)] [13B sub-header] [payload] [2B terminator: 0x0000]
```

**Connection Flow:**

1. TCP connect to port 5556
2. Init handshake (`initrequest` / `initresponse`) — negotiates MTP version
3. Login (`login_req` / `login_resp`) — authenticates with SHA-1 hashed password
4. KOAP request/response exchange
5. Keepalive every ~5 seconds (`MTP keepalive` text message)

**Message Types:**

| Type | Hex  | Description |
|------|------|-------------|
| Init | 0x01/0x81 | Version negotiation |
| Login | 0x02/0x82 | Authentication |
| KOAP | 0x04/0x84 | XML request/response |
| Keepalive | 0x10/0x90 | Connection heartbeat |

**Compression:** Server KOAP responses may be zlib-compressed (indicated by compression flag 0x02 in the sub-header). The library handles decompression transparently.

### Message Layer

Handles serialization/deserialization of KOAP XML messages:

```xml
<!-- Request -->
<request version="1" seq="3" destAddr="/">
  <mdomain id="main">
    <operation seq="1" name="getDiscover" forced="true"/>
  </mdomain>
</request>

<!-- Response -->
<response version="1" seq="3" destAddr="/">
  <mdomain id="main">
    <operation seq="1" name="getDiscover">
      <execution status="success"/>
      <info>
        <motype>shelf</motype>
        <moname>MileGate 2300</moname>
        <!-- ... -->
      </info>
    </operation>
  </mdomain>
</response>
```

### Client Layer

`KoapClient` creates connections with automatic MTP handshake, `Connection` provides the high-level API:

- `discover(string $destAddr, string $mDomain = 'main')` — Explore the managed object tree (optional domain, e.g. main, cfgm, status)
- `get(string $destAddr, string $mDomain, string $operationName)` — Read a property
- `set(string $destAddr, string $mDomain, string $operationName, array $payload)` — Write a property
- `execute(string $destAddr, string $mDomain, string $operationName, array $payload)` — Raw request/response
- `close()` — Close the connection
- `isConnected()` — Check if the connection is still active

## Custom Transport

You can inject a custom `TransportInterface` to bypass the MTP layer:

```php
use SkyDiablo\Keymile\Koap\KoapClient;

$client = new KoapClient('host', transport: $myCustomTransport);
```

The legacy `TcpTransport` with pluggable frame parsers (`RawXmlFrameParser`, `NullTerminatedFrameParser`, `LengthPrefixedFrameParser`) is still available for testing or non-standard setups.

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run static analysis (level 8)
composer analyse
```

## License

MIT

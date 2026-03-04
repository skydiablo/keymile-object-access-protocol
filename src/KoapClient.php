<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap;

use SkyDiablo\Keymile\Koap\Transport\MtpTransport;
use SkyDiablo\Keymile\Koap\Transport\TransportInterface;
use React\Promise\PromiseInterface;
use React\Socket\Connector;

/**
 * Entry point for connecting to Keymile MileGate devices via KOAP.
 *
 * Usage:
 *   $client = new KoapClient('192.168.1.1', 5556, 'admin', 'secret');
 *   $connection = await($client->connect());
 *   $result = await($connection->discover('/'));
 */
class KoapClient
{
    private readonly TransportInterface $transport;

    public function __construct(
        string $host,
        int $port = 5556,
        string $username = '',
        string $password = '',
        string $userClass = 'information',
        ?Connector $connector = null,
        ?TransportInterface $transport = null,
    ) {
        $this->transport = $transport ?? new MtpTransport(
            $host,
            $port,
            $username,
            $password,
            $userClass,
            connector: $connector,
        );
    }

    /**
     * Connect to the device, perform the MTP handshake (init + login),
     * and return a ready-to-use Connection.
     *
     * @return PromiseInterface<Connection>
     */
    public function connect(): PromiseInterface
    {
        return $this->transport->connect()->then(
            fn (TransportInterface $transport): Connection => new Connection($transport),
        );
    }
}

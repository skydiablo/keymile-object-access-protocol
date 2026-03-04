<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport;

use SkyDiablo\Keymile\Koap\Exception\ConnectionException;
use SkyDiablo\Keymile\Koap\Transport\Frame\RawXmlFrameParser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

class TcpTransport implements TransportInterface
{
    private ?ConnectionInterface $connection = null;
    private readonly FrameParserInterface $frameParser;
    private readonly Connector $connector;

    /** @var list<callable(string): void> */
    private array $messageCallbacks = [];

    /** @var list<callable(\Throwable): void> */
    private array $errorCallbacks = [];

    /** @var list<callable(): void> */
    private array $closeCallbacks = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        ?FrameParserInterface $frameParser = null,
        ?Connector $connector = null,
    ) {
        $this->frameParser = $frameParser ?? new RawXmlFrameParser();
        $this->connector = $connector ?? new Connector();
    }

    public function connect(): PromiseInterface
    {
        $uri = sprintf('tcp://%s:%d', $this->host, $this->port);

        /** @var PromiseInterface<self> */
        return $this->connector->connect($uri)->then(
            function (ConnectionInterface $connection): self {
                $this->connection = $connection;
                $this->frameParser->reset();
                $this->setupListeners($connection);

                return $this;
            },
            function (\Throwable $e): never {
                throw new ConnectionException(
                    sprintf('Failed to connect to %s:%d: %s', $this->host, $this->port, $e->getMessage()),
                    0,
                    $e,
                );
            },
        );
    }

    public function send(string $data): PromiseInterface
    {
        if ($this->connection === null || !$this->connection->isWritable()) {
            /** @var Deferred<void> $deferred */
            $deferred = new Deferred();
            $deferred->reject(new ConnectionException('Not connected'));

            return $deferred->promise();
        }

        $this->connection->write($data);

        /** @var Deferred<void> $deferred */
        $deferred = new Deferred();
        $deferred->resolve(null);

        return $deferred->promise();
    }

    public function onMessage(callable $callback): void
    {
        $this->messageCallbacks[] = $callback;
    }

    public function onError(callable $callback): void
    {
        $this->errorCallbacks[] = $callback;
    }

    public function onClose(callable $callback): void
    {
        $this->closeCallbacks[] = $callback;
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }

        $this->frameParser->reset();
    }

    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isReadable();
    }

    private function setupListeners(ConnectionInterface $connection): void
    {
        $connection->on('data', function (string $data): void {
            $messages = $this->frameParser->feed($data);

            foreach ($messages as $message) {
                foreach ($this->messageCallbacks as $callback) {
                    $callback($message);
                }
            }
        });

        $connection->on('error', function (\Throwable $e): void {
            foreach ($this->errorCallbacks as $callback) {
                $callback($e);
            }
        });

        $connection->on('close', function (): void {
            $this->connection = null;

            foreach ($this->closeCallbacks as $callback) {
                $callback();
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport;

use SkyDiablo\Keymile\Koap\Exception\ConnectionException;
use SkyDiablo\Keymile\Koap\Exception\ProtocolException;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpCodec;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpFrame;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpFrameParser;
use SkyDiablo\Keymile\Koap\Transport\Mtp\MtpMessageType;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

/**
 * MTP-aware TCP transport for MileGate devices.
 *
 * Handles the full MTP protocol stack:
 * - COBS-framed message parsing and encoding
 * - 12-byte MTP header with command, compression, version, message number
 * - Init handshake and login with SHA-1 password hashing
 * - Loopback keepalive timer
 * - Zlib decompression of server responses
 */
class MtpTransport implements TransportInterface
{
    private const float KEEPALIVE_INTERVAL = 5.0;

    private ?ConnectionInterface $connection = null;
    private readonly MtpFrameParser $frameParser;
    private readonly MtpCodec $codec;
    private readonly Connector $connector;
    private ?TimerInterface $keepaliveTimer = null;
    private ?string $sessionId = null;

    /** @var list<callable(string): void> */
    private array $messageCallbacks = [];

    /** @var list<callable(\Throwable): void> */
    private array $errorCallbacks = [];

    /** @var list<callable(): void> */
    private array $closeCallbacks = [];

    /** @var Deferred<MtpFrame>|null */
    private ?Deferred $pendingHandshake = null;

    /**
     * @param int $mtpVersion MTP protocol version to negotiate (4 = default with compression, try 1 to avoid compression)
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $userClass = 'information',
        private readonly int $mtpVersion = 4,
        ?Connector $connector = null,
    ) {
        $this->frameParser = new MtpFrameParser();
        $this->codec = new MtpCodec();
        $this->connector = $connector ?? new Connector();
    }

    public function connect(): PromiseInterface
    {
        $uri = sprintf('tcp://%s:%d', $this->host, $this->port);

        /** @var PromiseInterface<self> */
        return $this->connector->connect($uri)->then(
            function (ConnectionInterface $connection): PromiseInterface {
                $this->connection = $connection;
                $this->frameParser->reset();
                $this->codec->setMessageNumber(0);
                $this->setupListeners($connection);

                return $this->performHandshake();
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

        $frame = $this->codec->encode(MtpMessageType::Data, $data);
        $this->connection->write($frame);

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
        $this->stopKeepalive();

        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }

        $this->frameParser->reset();
        $this->sessionId = null;
    }

    public function isConnected(): bool
    {
        return $this->connection !== null
            && $this->connection->isReadable()
            && $this->sessionId !== null;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * @return PromiseInterface<self>
     */
    private function performHandshake(): PromiseInterface
    {
        return $this->sendInit()->then(
            fn () => $this->sendLogin(),
        )->then(
            function () {
                $this->startKeepalive();

                return $this;
            },
        );
    }

    /**
     * @return PromiseInterface<void>
     */
    private function sendInit(): PromiseInterface
    {
        $xml = sprintf(
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
            . "<initrequest version=\"2\">\n"
            . "<mtpversion>%d</mtpversion>\n"
            . "<extension></extension>\n"
            . "</initrequest>",
            $this->mtpVersion,
        );

        return $this->sendHandshakeFrame(MtpMessageType::Init, $xml)->then(
            function (MtpFrame $response): void {
                if ($response->type !== MtpMessageType::Init) {
                    throw new ProtocolException(sprintf(
                        'Expected init response, got 0x%02X',
                        $response->type->value,
                    ));
                }
            },
        );
    }

    /**
     * @return PromiseInterface<void>
     */
    private function sendLogin(): PromiseInterface
    {
        $passwordHash = sha1($this->password);

        $xml = sprintf(
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
            . "<login_req ver=\"2\">\n"
            . "<user class=\"%s\" pass=\"%s\" userId=\"%s\"/>\n"
            . "</login_req>",
            htmlspecialchars($this->userClass, ENT_XML1),
            $passwordHash,
            htmlspecialchars($this->username, ENT_XML1),
        );

        return $this->sendHandshakeFrame(MtpMessageType::Login, $xml)->then(
            function (MtpFrame $response): void {
                if ($response->type !== MtpMessageType::Login) {
                    throw new ProtocolException(sprintf(
                        'Expected login response, got 0x%02X',
                        $response->type->value,
                    ));
                }

                $payload = $response->payload;
                if (str_contains($payload, '<auth>success</auth>')) {
                    if (preg_match('/<sessionId>(\d+)<\/sessionId>/', $payload, $matches)) {
                        $this->sessionId = $matches[1];
                    } else {
                        $this->sessionId = 'unknown';
                    }
                } else {
                    throw new ConnectionException('Authentication failed');
                }
            },
        );
    }

    /**
     * @return PromiseInterface<MtpFrame>
     */
    private function sendHandshakeFrame(MtpMessageType $type, string $payload): PromiseInterface
    {
        if ($this->connection === null || !$this->connection->isWritable()) {
            /** @var Deferred<MtpFrame> $deferred */
            $deferred = new Deferred();
            $deferred->reject(new ConnectionException('Not connected during handshake'));

            return $deferred->promise();
        }

        $frame = $this->codec->encode($type, $payload);

        /** @var Deferred<MtpFrame> $deferred */
        $deferred = new Deferred();
        $this->pendingHandshake = $deferred;

        $this->connection->write($frame);

        return $deferred->promise();
    }

    private function sendKeepalive(): void
    {
        if ($this->connection === null || !$this->connection->isWritable()) {
            return;
        }

        $frame = $this->codec->encode(MtpMessageType::Loopback, 'MTP keepalive');
        $this->connection->write($frame);
    }

    private function startKeepalive(): void
    {
        $this->stopKeepalive();
        $this->keepaliveTimer = Loop::addPeriodicTimer(
            self::KEEPALIVE_INTERVAL,
            fn () => $this->sendKeepalive(),
        );
    }

    private function stopKeepalive(): void
    {
        if ($this->keepaliveTimer !== null) {
            Loop::cancelTimer($this->keepaliveTimer);
            $this->keepaliveTimer = null;
        }
    }

    private function setupListeners(ConnectionInterface $connection): void
    {
        $connection->on('data', function (string $data): void {
            try {
                $frames = $this->frameParser->feed($data);
            } catch (\Throwable $e) {
                foreach ($this->errorCallbacks as $callback) {
                    $callback($e);
                }
                return;
            }

            foreach ($frames as $frame) {
                $this->handleFrame($frame);
            }
        });

        $connection->on('error', function (\Throwable $e): void {
            foreach ($this->errorCallbacks as $callback) {
                $callback($e);
            }
        });

        $connection->on('close', function (): void {
            $this->stopKeepalive();
            $this->connection = null;
            $this->sessionId = null;

            if ($this->pendingHandshake !== null) {
                $this->pendingHandshake->reject(new ConnectionException('Connection closed during handshake'));
                $this->pendingHandshake = null;
            }

            foreach ($this->closeCallbacks as $callback) {
                $callback();
            }
        });
    }

    private function handleFrame(MtpFrame $frame): void
    {
        // Loopback responses are keepalive acks — ignore silently
        if ($frame->type === MtpMessageType::Loopback) {
            return;
        }

        // Handshake responses (init, login) resolve the pending promise
        if ($frame->type === MtpMessageType::Init
            || $frame->type === MtpMessageType::Login
        ) {
            if ($this->pendingHandshake !== null) {
                $pending = $this->pendingHandshake;
                $this->pendingHandshake = null;
                $pending->resolve($frame);
            }
            return;
        }

        // Data responses contain KOAP XML (possibly compressed)
        if ($frame->type === MtpMessageType::Data) {
            try {
                $xml = $this->codec->decodePayload($frame);
            } catch (\Throwable $e) {
                foreach ($this->errorCallbacks as $callback) {
                    $callback($e);
                }
                return;
            }

            foreach ($this->messageCallbacks as $callback) {
                $callback($xml);
            }
        }
    }
}

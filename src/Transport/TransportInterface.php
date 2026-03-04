<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport;

use React\Promise\PromiseInterface;

/**
 * Abstraction for the network transport to a MileGate device.
 */
interface TransportInterface
{
    /**
     * Establish the connection.
     *
     * @return PromiseInterface<self>
     */
    public function connect(): PromiseInterface;

    /**
     * Send a raw XML message.
     *
     * @return PromiseInterface<void>
     */
    public function send(string $data): PromiseInterface;

    /**
     * Register a callback for incoming messages.
     *
     * @param callable(string): void $callback
     */
    public function onMessage(callable $callback): void;

    /**
     * Register a callback for connection errors.
     *
     * @param callable(\Throwable): void $callback
     */
    public function onError(callable $callback): void;

    /**
     * Register a callback for connection close.
     *
     * @param callable(): void $callback
     */
    public function onClose(callable $callback): void;

    /**
     * Close the connection.
     */
    public function close(): void;

    /**
     * Check if the transport is currently connected.
     */
    public function isConnected(): bool;
}

<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap;

use SkyDiablo\Keymile\Koap\Exception\ConnectionException;
use SkyDiablo\Keymile\Koap\Exception\OperationException;
use SkyDiablo\Keymile\Koap\Message\Operation;
use SkyDiablo\Keymile\Koap\Message\Request;
use SkyDiablo\Keymile\Koap\Message\Response;
use SkyDiablo\Keymile\Koap\Message\Serializer;
use SkyDiablo\Keymile\Koap\Model\ManagedObject;
use SkyDiablo\Keymile\Koap\Transport\TransportInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

class Connection
{
    /**
     * Starts at 2 because the MTP handshake uses seq 1 (init) and 2 (login).
     * The first KOAP request will use seq 3.
     */
    private int $sequenceCounter = 2;

    /** @var array<int, Deferred<Response>> */
    private array $pendingRequests = [];

    private readonly Serializer $serializer;

    public function __construct(
        private readonly TransportInterface $transport,
        ?Serializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new Serializer();
        $this->setupMessageHandler();
    }

    /**
     * Discover the managed object tree starting from the given address.
     *
     * @return PromiseInterface<ManagedObject>
     */
    public function discover(string $destAddr = '/'): PromiseInterface
    {
        return $this->execute($destAddr, 'main', 'getDiscover')->then(
            function (Response $response) use ($destAddr): ManagedObject {
                $operation = $response->getFirstOperation();
                if ($operation === null) {
                    throw new ConnectionException('Empty discover response from ' . $destAddr);
                }

                if (!$operation->isSuccessful()) {
                    throw new OperationException(
                        'getDiscover',
                        $operation->executionStatus ?? 'unknown',
                        $destAddr,
                    );
                }

                $discover = $operation->payload['Discover'] ?? $operation->payload;
                /** @var array<string, mixed> $info */
                $info = $discover['info'] ?? $discover;

                return ManagedObject::fromArray($info);
            },
        );
    }

    /**
     * Get a property value from a managed object.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function get(string $destAddr, string $mDomain, string $operationName): PromiseInterface
    {
        $name = $this->normalizeGetOperationName($operationName);

        return $this->execute($destAddr, $mDomain, $name)->then(
            function (Response $response) use ($name, $destAddr): array {
                $operation = $response->getFirstOperation();
                if ($operation === null) {
                    throw new ConnectionException('Empty response for ' . $name);
                }

                if (!$operation->isSuccessful()) {
                    throw new OperationException(
                        $name,
                        $operation->executionStatus ?? 'unknown',
                        $destAddr,
                    );
                }

                return $operation->payload;
            },
        );
    }

    /**
     * Set a property value on a managed object.
     *
     * @param array<string, mixed> $payload
     * @return PromiseInterface<Response>
     */
    public function set(string $destAddr, string $mDomain, string $operationName, array $payload): PromiseInterface
    {
        $name = $this->normalizeSetOperationName($operationName);

        return $this->execute($destAddr, $mDomain, $name, $payload)->then(
            function (Response $response) use ($name, $destAddr): Response {
                $operation = $response->getFirstOperation();
                if ($operation !== null && !$operation->isSuccessful()) {
                    throw new OperationException(
                        $name,
                        $operation->executionStatus ?? 'unknown',
                        $destAddr,
                    );
                }

                return $response;
            },
        );
    }

    /**
     * Send a raw KOAP request and return the response.
     *
     * @param array<string, mixed> $payload
     * @return PromiseInterface<Response>
     */
    public function execute(
        string $destAddr,
        string $mDomain,
        string $operationName,
        array $payload = [],
    ): PromiseInterface {
        if (!$this->transport->isConnected()) {
            /** @var Deferred<Response> $deferred */
            $deferred = new Deferred();
            $deferred->reject(new ConnectionException('Not connected'));

            return $deferred->promise();
        }

        $seq = $this->nextSequence();

        $request = new Request(
            destAddr: $destAddr,
            mDomain: $mDomain,
            operations: [
                new Operation(
                    name: $operationName,
                    seq: 1,
                    payload: $payload,
                ),
            ],
            seq: $seq,
        );

        $xml = $this->serializer->serializeRequest($request);

        /** @var Deferred<Response> $deferred */
        $deferred = new Deferred();
        $this->pendingRequests[$seq] = $deferred;

        $this->transport->send($xml)->then(
            null,
            function (\Throwable $e) use ($seq): void {
                if (isset($this->pendingRequests[$seq])) {
                    $this->pendingRequests[$seq]->reject($e);
                    unset($this->pendingRequests[$seq]);
                }
            },
        );

        return $deferred->promise();
    }

    public function close(): void
    {
        $this->transport->close();
        $this->rejectAllPending(new ConnectionException('Connection closed'));
    }

    public function isConnected(): bool
    {
        return $this->transport->isConnected();
    }

    private function setupMessageHandler(): void
    {
        $this->transport->onMessage(function (string $xml): void {
            try {
                $response = $this->serializer->deserializeResponse($xml);
            } catch (\Throwable $e) {
                $this->rejectAllPending($e);
                return;
            }

            $seq = $response->seq;

            if (isset($this->pendingRequests[$seq])) {
                $this->pendingRequests[$seq]->resolve($response);
                unset($this->pendingRequests[$seq]);
            }
        });

        $this->transport->onError(function (\Throwable $e): void {
            $this->rejectAllPending(new ConnectionException($e->getMessage(), 0, $e));
        });

        $this->transport->onClose(function (): void {
            $this->rejectAllPending(new ConnectionException('Connection lost'));
        });
    }

    private function nextSequence(): int
    {
        return ++$this->sequenceCounter;
    }

    private function rejectAllPending(\Throwable $reason): void
    {
        $pending = $this->pendingRequests;
        $this->pendingRequests = [];

        foreach ($pending as $deferred) {
            $deferred->reject($reason);
        }
    }

    private function normalizeGetOperationName(string $name): string
    {
        if (!str_starts_with(strtolower($name), 'get')) {
            return 'get' . ucfirst($name);
        }

        return $name;
    }

    private function normalizeSetOperationName(string $name): string
    {
        if (!str_starts_with(strtolower($name), 'set')) {
            return 'set' . ucfirst($name);
        }

        return $name;
    }
}

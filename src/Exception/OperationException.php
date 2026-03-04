<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Exception;

class OperationException extends KoapException
{
    public function __construct(
        string $operationName,
        string $status,
        string $destAddr = '',
        ?\Throwable $previous = null,
    ) {
        $message = sprintf(
            'Operation "%s" failed with status "%s"',
            $operationName,
            $status,
        );

        if ($destAddr !== '') {
            $message .= sprintf(' at "%s"', $destAddr);
        }

        parent::__construct($message, 0, $previous);
    }
}

<?php

declare(strict_types=1);

namespace Customy\Data;

final class CustomyDataException extends \RuntimeException
{
    /** @param array<string, mixed>|null $response */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

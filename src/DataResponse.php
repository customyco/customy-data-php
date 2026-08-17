<?php

declare(strict_types=1);

namespace Customy\Data;

final readonly class DataResponse
{
    public function __construct(public int $statusCode, public string $body)
    {
    }
}

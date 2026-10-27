<?php

namespace App\Services\Waha;

use RuntimeException;

class WahaRequestException extends RuntimeException
{
    public function __construct(public readonly int $statusCode, string $message)
    {
        parent::__construct($message);
    }
}

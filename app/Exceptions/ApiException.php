<?php

namespace App\Exceptions;

use App\Support\Api\ApiResponseBuilder;
use Exception;
use Illuminate\Http\JsonResponse;

class ApiException extends Exception
{
    public function __construct(
        public readonly string $codeName,
        string $message,
        public readonly int $status = 400,
        public readonly array $errors = [],
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    public static function make(
        string $code,
        ?string $message = null,
        int $status = 400,
        array $errors = [],
        array $meta = []
    ): self {
        return new self($code, $message ?? $code, $status, $errors, $meta);
    }

    public function render(): JsonResponse
    {
        return ApiResponseBuilder::error($this->codeName, $this->getMessage(), $this->errors, $this->status, $this->meta);
    }
}


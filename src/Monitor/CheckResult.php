<?php

declare(strict_types=1);

namespace Vigil\Monitor;

final readonly class CheckResult
{
    public function __construct(
        public bool $ok,
        public ?int $responseMs = null,
        public ?int $statusCode = null,
        public ?string $error = null,
    ) {
    }

    public static function up(?int $ms = null, ?int $code = null): self
    {
        return new self(true, $ms, $code);
    }

    public static function down(string $error, ?int $ms = null, ?int $code = null): self
    {
        return new self(false, $ms, $code, $error);
    }
}

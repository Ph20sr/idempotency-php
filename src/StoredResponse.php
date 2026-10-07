<?php

declare(strict_types=1);

namespace Ph20sr\Idempotency;

final class StoredResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        /** true quando é a repetição de uma resposta já dada (avise o cliente com Idempotent-Replayed: true). */
        public readonly bool $replayed = false,
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if ($this->replayed) {
            header('Idempotent-Replayed: true');
        }
        echo $this->body;
    }
}

<?php

declare(strict_types=1);

namespace Ph20sr\Idempotency;

final class IdempotencyException extends \RuntimeException
{
    /** A mesma chave foi usada com um corpo/rota diferente (erro do cliente). */
    public const KEY_REUSED = 'key_reused';
    /** O pedido original ainda está sendo processado. */
    public const IN_PROGRESS = 'in_progress';
    /** Chave ausente ou mal formada. */
    public const INVALID_KEY = 'invalid_key';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** 400 chave inválida, 422 chave reutilizada com outro pedido, 409 enquanto processa. */
    public function httpStatus(): int
    {
        return $this->reason === self::IN_PROGRESS ? 409 : ($this->reason === self::INVALID_KEY ? 400 : 422);
    }
}

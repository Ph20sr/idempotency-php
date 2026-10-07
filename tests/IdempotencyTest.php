<?php

declare(strict_types=1);

namespace Ph20sr\Idempotency\Tests;

use PDO;
use Ph20sr\Idempotency\Idempotency;
use Ph20sr\Idempotency\IdempotencyException;
use Ph20sr\Idempotency\StoredResponse;
use PHPUnit\Framework\TestCase;

final class IdempotencyTest extends TestCase
{
    private PDO $pdo;
    private Idempotency $idem;
    private int $now = 1_800_000_000;
    private int $charges = 0;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->idem = new Idempotency($this->pdo, ttl: 86400, lockTimeout: 60, clock: fn (): int => $this->now);
        $this->idem->install();
    }

    private function charge(): StoredResponse
    {
        $this->charges++;
        return new StoredResponse(201, json_encode(['id' => "ch_{$this->charges}"]), ['Content-Type' => 'application/json']);
    }

    private function fp(string $body = '{"amount":149.9}'): string
    {
        return Idempotency::fingerprint('POST', '/v1/charges', $body);
    }

    public function testSameKeyReturnsStoredResponseWithoutRunningAgain(): void
    {
        $first = $this->idem->handle('pedido-123', $this->fp(), $this->charge(...));
        $second = $this->idem->handle('pedido-123', $this->fp(), $this->charge(...));

        $this->assertSame(1, $this->charges, 'cobrança criada uma única vez');
        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->body, $second->body);
        $this->assertSame(201, $second->status);
        $this->assertSame(['Content-Type' => 'application/json'], $second->headers);
    }

    public function testFingerprintIgnoresJsonKeyOrder(): void
    {
        $this->assertSame(
            Idempotency::fingerprint('post', '/v1/charges', '{"a":1,"b":{"y":2,"x":1}}'),
            Idempotency::fingerprint('POST', '/v1/charges', '{"b":{"x":1,"y":2},"a":1}'),
        );
        $this->assertNotSame(Idempotency::fingerprint('POST', '/a', '{}'), Idempotency::fingerprint('POST', '/b', '{}'));
    }

    public function testReusingKeyWithDifferentRequestIsRejected(): void
    {
        $this->idem->handle('k1', $this->fp(), $this->charge(...));
        try {
            $this->idem->handle('k1', $this->fp('{"amount":999}'), $this->charge(...));
            $this->fail('Deveria rejeitar');
        } catch (IdempotencyException $e) {
            $this->assertSame(IdempotencyException::KEY_REUSED, $e->reason);
            $this->assertSame(422, $e->httpStatus());
        }
        $this->assertSame(1, $this->charges);
    }

    public function testScopesIsolateClients(): void
    {
        $this->idem->handle('pedido-1', $this->fp(), $this->charge(...), scope: 'account:A');
        $this->idem->handle('pedido-1', $this->fp(), $this->charge(...), scope: 'account:B');
        $this->assertSame(2, $this->charges, 'a mesma chave em contas diferentes são pedidos diferentes');
    }

    public function testConcurrentRequestGetsInProgressThenCrashedLockIsTakenOver(): void
    {
        // Simula o pedido original travado no meio (registro "processing")
        $this->pdo->prepare("INSERT INTO idempotency_keys (k, fingerprint, status, locked_until, created_at, expires_at) VALUES (?, ?, 'processing', ?, ?, ?)")
            ->execute(['k2', $this->fp(), $this->now + 60, $this->now, $this->now + 86400]);

        try {
            $this->idem->handle('k2', $this->fp(), $this->charge(...));
            $this->fail('Deveria acusar processamento em andamento');
        } catch (IdempotencyException $e) {
            $this->assertSame(409, $e->httpStatus());
        }

        // O processo original morreu: depois do lockTimeout, outro assume
        $this->now += 61;
        $response = $this->idem->handle('k2', $this->fp(), $this->charge(...));
        $this->assertSame(201, $response->status);
        $this->assertSame(1, $this->charges);
    }

    public function testFailedOperationReleasesTheKey(): void
    {
        try {
            $this->idem->handle('k3', $this->fp(), function (): StoredResponse {
                throw new \RuntimeException('gateway fora do ar');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(201, $this->idem->handle('k3', $this->fp(), $this->charge(...))->status, 'pode tentar de novo');

        // 5xx também não é guardado
        $this->idem->handle('k4', $this->fp(), fn () => new StoredResponse(503, 'indisponível'));
        $this->assertFalse($this->idem->handle('k4', $this->fp(), $this->charge(...))->replayed);
    }

    public function testClientErrorsAreStoredAndReplayed(): void
    {
        $this->idem->handle('k5', $this->fp(), fn () => new StoredResponse(402, '{"error":"cartão recusado"}'));
        $again = $this->idem->handle('k5', $this->fp(), $this->charge(...));
        $this->assertTrue($again->replayed);
        $this->assertSame(402, $again->status, 'a recusa é a resposta definitiva para esta chave');
        $this->assertSame(0, $this->charges);
    }

    public function testExpiredKeysAreReusableAndPurged(): void
    {
        $this->idem->handle('k6', $this->fp(), $this->charge(...));
        $this->now += 86400;
        $this->assertFalse($this->idem->handle('k6', $this->fp('{"outro":1}'), $this->charge(...))->replayed, 'expirada vira nova');
        $this->now += 86400;
        $this->assertSame(1, $this->idem->purge());
    }

    public function testInvalidKey(): void
    {
        foreach (['', str_repeat('a', 201), "com espaço"] as $bad) {
            try {
                $this->idem->handle($bad, $this->fp(), $this->charge(...));
                $this->fail("Chave inválida aceita: '{$bad}'");
            } catch (IdempotencyException $e) {
                $this->assertSame(400, $e->httpStatus());
            }
        }
    }
}

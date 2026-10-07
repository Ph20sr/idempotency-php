<?php

declare(strict_types=1);

namespace Ph20sr\Idempotency;

use PDO;

/**
 *     $idem = new Idempotency($pdo);
 *     $response = $idem->handle(
 *         key: $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '',
 *         scope: "account:{$accountId}",
 *         fingerprint: Idempotency::fingerprint('POST', '/v1/charges', $rawBody),
 *         operation: fn () => criarCobranca($dados),   // retorna StoredResponse
 *     );
 *     $response->send();
 */
final class Idempotency
{
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'idempotency_keys',
        /** Por quanto tempo a resposta fica guardada (a Stripe usa 24 h). */
        private readonly int $ttl = 86400,
        /** Se o processo morrer no meio, depois deste tempo outro pode assumir a chave. */
        private readonly int $lockTimeout = 60,
        ?callable $clock = null,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Nome de tabela inválido: {$table}");
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    public function install(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
            k VARCHAR(255) NOT NULL PRIMARY KEY,
            fingerprint CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL,
            locked_until BIGINT NULL,
            response_status INT NULL,
            response_headers TEXT NULL,
            response_body MEDIUMTEXT NULL,
            created_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL
        )");
    }

    /** Impressão digital do pedido: o mesmo método, rota e corpo geram o mesmo hash. */
    public static function fingerprint(string $method, string $path, string $body = ''): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            // JSON com chaves em outra ordem continua sendo o mesmo pedido
            $body = json_encode(self::sortKeys($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return hash('sha256', strtoupper($method) . "\n" . $path . "\n" . $body);
    }

    /**
     * Executa a operação uma única vez por chave.
     *
     * - Chave nova: executa, guarda a resposta e devolve.
     * - Mesma chave e mesmo pedido: devolve a resposta guardada (replayed = true), sem executar.
     * - Mesma chave com outro pedido: IdempotencyException (422).
     * - Pedido original ainda em andamento: IdempotencyException (409).
     * - A operação lançou exceção: a chave é liberada (o cliente pode tentar de novo).
     *
     * @param callable(): StoredResponse $operation
     */
    public function handle(string $key, string $fingerprint, callable $operation, string $scope = ''): StoredResponse
    {
        if (!preg_match('/^[\x21-\x7E]{1,200}$/', $key)) {
            throw new IdempotencyException(IdempotencyException::INVALID_KEY, 'Envie o cabeçalho Idempotency-Key (1 a 200 caracteres visíveis)');
        }
        $k = $scope === '' ? $key : "{$scope}:{$key}";
        $now = ($this->clock)();
        $lockUntil = $now + $this->lockTimeout;

        // Tenta registrar a chave; se já existe, decide pelo estado salvo
        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->table} (k, fingerprint, status, locked_until, created_at, expires_at) VALUES (?, ?, 'processing', ?, ?, ?)",
        );
        try {
            $insert->execute([$k, $fingerprint, $lockUntil, $now, $now + $this->ttl]);
        } catch (\PDOException) {
            $row = $this->load($k);
            if ($row === null) {
                throw new \RuntimeException('Falha ao registrar a chave de idempotência');
            }
            if ((int) $row['expires_at'] <= $now) {
                // Expirou: apaga e trata como nova
                $this->pdo->prepare("DELETE FROM {$this->table} WHERE k = ? AND expires_at <= ?")->execute([$k, $now]);
                return $this->handle($key, $fingerprint, $operation, $scope);
            }
            if (!hash_equals((string) $row['fingerprint'], $fingerprint)) {
                throw new IdempotencyException(IdempotencyException::KEY_REUSED, 'Esta Idempotency-Key já foi usada com outro pedido');
            }
            if ($row['status'] === 'done') {
                return new StoredResponse(
                    (int) $row['response_status'],
                    (string) $row['response_body'],
                    json_decode((string) $row['response_headers'], true) ?: [],
                    true,
                );
            }
            // Em processamento: só assume se o lock venceu (processo anterior morreu)
            $takeover = $this->pdo->prepare(
                "UPDATE {$this->table} SET locked_until = ? WHERE k = ? AND status = 'processing' AND locked_until < ?",
            );
            $takeover->execute([$lockUntil, $k, $now]);
            if ($takeover->rowCount() === 0) {
                throw new IdempotencyException(IdempotencyException::IN_PROGRESS, 'O pedido original ainda está sendo processado; tente de novo em instantes');
            }
        }

        try {
            $response = $operation();
        } catch (\Throwable $e) {
            $this->pdo->prepare("DELETE FROM {$this->table} WHERE k = ? AND status = 'processing'")->execute([$k]);
            throw $e;
        }
        if (!$response instanceof StoredResponse) {
            throw new \UnexpectedValueException('A operação deve retornar StoredResponse');
        }

        // Respostas 5xx não são guardadas: o cliente deve poder tentar de novo
        if ($response->status >= 500) {
            $this->pdo->prepare("DELETE FROM {$this->table} WHERE k = ?")->execute([$k]);
            return $response;
        }
        $this->pdo->prepare(
            "UPDATE {$this->table} SET status = 'done', locked_until = NULL, response_status = ?, response_headers = ?, response_body = ? WHERE k = ?",
        )->execute([$response->status, json_encode($response->headers), $response->body, $k]);
        return $response;
    }

    /** Remove chaves expiradas (rode num cron). */
    public function purge(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at <= ?");
        $stmt->execute([($this->clock)()]);
        return $stmt->rowCount();
    }

    /** @return array<string, mixed>|null */
    private function load(string $k): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE k = ?");
        $stmt->execute([$k]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param array<mixed> $value @return array<mixed> */
    private static function sortKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(static fn ($v) => is_array($v) ? self::sortKeys($v) : $v, $value);
    }
}

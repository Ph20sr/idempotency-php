# idempotency-php

[![CI](https://github.com/Ph20sr/idempotency-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Ph20sr/idempotency-php/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4)
![zero dependencies](https://img.shields.io/badge/dependencies-0-brightgreen)

**Idempotency-Key** para APIs PHP, no mesmo padrão da Stripe. Quando o app do cliente envia `POST /cobrancas`, a internet cai antes da resposta e o app tenta de novo, **a cobrança não é criada duas vezes**: a segunda chamada recebe exatamente a mesma resposta da primeira.

Vale para cobranças, pedidos, cadastros, envio de mensagens: qualquer `POST` que não pode duplicar.

## Como funciona

O cliente gera um identificador único por operação (ex.: UUID) e envia em `Idempotency-Key`.

| situação | resposta |
| --- | --- |
| chave nova | executa a operação e guarda a resposta |
| mesma chave, mesmo pedido | devolve a resposta guardada, **sem executar de novo** (`Idempotent-Replayed: true`) |
| mesma chave, **outro** corpo | `422`: o cliente reutilizou a chave por engano |
| mesma chave enquanto a primeira ainda processa | `409`: tente em instantes |
| o processo morreu no meio | depois do `lockTimeout`, a próxima tentativa assume |
| a operação lançou exceção ou deu 5xx | a chave é liberada: o cliente pode tentar de novo |
| 4xx (ex.: cartão recusado) | guardado: é a resposta definitiva daquela chave |

O corpo é comparado por **impressão digital** (método + rota + JSON com as chaves ordenadas). `{"a":1,"b":2}` e `{"b":2,"a":1}` são o mesmo pedido.

## Uso

```php
use Ph20sr\Idempotency\{Idempotency, IdempotencyException, StoredResponse};

$idem = new Idempotency($pdo);   // ttl 24 h, lock 60 s
$idem->install();                // cria a tabela (uma vez)

$raw = file_get_contents('php://input');
try {
    $response = $idem->handle(
        key: $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '',
        fingerprint: Idempotency::fingerprint('POST', '/v1/cobrancas', $raw),
        scope: "conta:{$accountId}",   // a mesma chave em contas diferentes não colide
        operation: function () use ($raw): StoredResponse {
            $cobranca = $asaas->payments->create(json_decode($raw, true));
            return new StoredResponse(201, json_encode($cobranca), ['Content-Type' => 'application/json']);
        },
    );
    $response->send();
} catch (IdempotencyException $e) {
    http_response_code($e->httpStatus());   // 400, 409 ou 422
    echo json_encode(['error' => $e->reason, 'message' => $e->getMessage()]);
}
```

No cliente (JavaScript):

```js
const key = crypto.randomUUID();   // gere UMA vez por operação e reutilize nas retentativas
await fetch('/v1/cobrancas', { method: 'POST', headers: { 'Idempotency-Key': key }, body });
```

`$idem->purge()` num cron diário apaga as chaves expiradas.

## Testes

```bash
composer install
vendor/bin/phpunit
```

## Licença

MIT

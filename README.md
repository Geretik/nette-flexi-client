# nette-flexi-client

Nette extension and reusable client for ABRA Flexi REST API.

## Requirements

- PHP 8.2+
- Nette DI
- contributte/guzzlette
- guzzlehttp/guzzle

## Installation

```bash
composer require acme/nette-abra-flexi
```

## Nette Configuration

Register the extension in your `config.neon`:

```neon
extensions:
    guzzle: Contributte\Guzzlette\DI\GuzzleExtension
    abraFlexi: Acme\AbraFlexi\DI\FlexiExtension

abraFlexi:
    baseUrl: https://demo.flexibee.eu
    company: demo-company
    username: demo-user
    password: demo-password
    timeout: 10.0
    guzzle:
        headers:
            Accept: application/json
```

`FlexiExtension` creates its own dedicated Guzzle client through `Contributte\Guzzlette\ClientFactory`, so the transport always uses the Guzzlette-backed client registered in the container, regardless of the extension alias.

## Architecture

Library is split into focused layers:

- `Config/FlexiConfig` - validated connection configuration
- `Endpoint/EndpointBuilder` - URL/endpoint composition for company and agenda paths
- `Http/HttpTransportInterface` + `Http/GuzzleHttpTransport` - HTTP transport over a dedicated client created by Guzzlette
- `Response/ResponseParser` - JSON/XML parsing, `winstrom` root normalization and API error payload detection
- `Client/FlexiClient` - public API for `GET/POST/PUT/DELETE` using Flexi `.json` or `.xml` endpoints
- `Exception/*` - unified exception hierarchy for transport/API/parse failures
- `DI/FlexiExtension` - Nette extension wiring all services from NEON config

## Usage

Inject `Acme\AbraFlexi\Client\FlexiClient` into your service:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use Acme\AbraFlexi\Client\FlexiClient;

final readonly class InvoiceSync
{
    public function __construct(
        private FlexiClient $flexiClient,
    ) {
    }

    public function loadInvoice(string $id): array
    {
        return $this->flexiClient->get('faktura-vydana', $id);
    }
}
```

Supported methods:
- `get(string $agenda, ?string $recordId = null, array $query = [])`
- `post(string $agenda, array|string $payload, array $query = [])`
- `put(string $agenda, string $recordId, array|string $payload, array $query = [])`
- `delete(string $agenda, string $recordId, array $query = [])`

Example with query and payload:

```php
$list = $flexiClient->get('adresar', null, ['limit' => 20, 'detail' => 'full']);

$created = $flexiClient->post('adresar', [
    'kod' => 'CUST-001',
    'nazev' => 'Acme s.r.o.',
]);
```

Array payloads are automatically wrapped to the Flexi JSON document shape:

```json
{"winstrom":{"adresar":{"kod":"CUST-001","nazev":"Acme s.r.o."}}}
```

Responses are normalized to the inner document payload, so a Flexi response such as `{"winstrom":{"@version":"1.0","adresar":[...]}}` is returned as `['@version' => '1.0', 'adresar' => [...]]`.

If you need to call an XML endpoint, pass a raw XML string payload. The client will switch the request URL to `.xml` and send `Accept`/`Content-Type: application/xml`.

## Error Handling

Client uses dedicated exceptions:

- `FlexiException` - base exception
- `HttpException` - transport/status errors (`statusCode`, `responseBody`)
- `ApiErrorException` - API payload returned a business error
- `ParseException` - invalid/unexpected response format

## Logging

`GuzzleHttpTransport` supports optional `Psr\Log\LoggerInterface`.

- Request/response/error events are logged
- Sensitive values are masked before logging, including request/response payloads and sensitive headers (`auth`, `password`, `authorization`, `token`, ...)

## Testing

Run tests in Docker (PHP 8.2):

```bash
docker compose run --rm app composer test -- --no-coverage
```

Run the real API integration test only when explicitly enabled:

```bash
ABRA_FLEXI_RUN_INTEGRATION=1 composer test:integration
```

Defaults target the public demo Flexi instance:

- `ABRA_FLEXI_BASE_URL=https://demo.flexibee.eu:5434`
- `ABRA_FLEXI_COMPANY=demo`
- `ABRA_FLEXI_USERNAME=winstrom`
- `ABRA_FLEXI_PASSWORD=winstrom`
- `ABRA_FLEXI_TIMEOUT=10`

Current status:

- Unit tests for endpoint builder
- Unit tests for response parser
- Unit tests for HTTP transport (including masked logging)
- Unit tests for main client
- DI extension compile test
- Opt-in integration test covering real create/read/update/delete flow against ABRA Flexi API

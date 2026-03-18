# nette-flexi-client

Nette rozšíření a znovupoužitelný klient pro REST API ABRA Flexi.

## Požadavky

- PHP 8.2+
- Nette DI
- contributte/guzzlette
- guzzlehttp/guzzle

## Instalace

```bash
composer require acme/nette-abra-flexi
```

## Konfigurace v Nette

Zaregistrujte rozšíření do `config.neon`:

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

`FlexiExtension` si přes `Contributte\Guzzlette\ClientFactory` vytváří vlastního dedikovaného Guzzle klienta, takže transport vždy používá klienta založeného na Guzzlette, který je zaregistrovaný v DI kontejneru, bez ohledu na alias rozšíření.

Pokud potřebujete více firem, není nutné registrovat desítky extension aliasů. Stačí jedna extension a pojmenované connection profily:

```neon
extensions:
    guzzle: Contributte\Guzzlette\DI\GuzzleExtension
    abraFlexi: Acme\AbraFlexi\DI\FlexiExtension

abraFlexi:
    baseUrl: https://demo.flexibee.eu
    username: demo-user
    password: demo-password
    timeout: 10.0
    connections:
        sales: company-sales
        warehouse:
            company: company-warehouse
            timeout: 20.0
    defaultConnection: sales
```

Pak můžete:

- injektovat `Acme\AbraFlexi\Client\FlexiClient` pro `defaultConnection`
- injektovat `Acme\AbraFlexi\Client\FlexiClientFactory` a volat `$factory->createNamed('warehouse')`
- nebo vytvářet klienty dynamicky přes `$factory->create('company-code')`

Pokud vám víc vyhovuje původní model, stále můžete zaregistrovat více instancí `FlexiExtension` pod různými aliasy. Ten zůstává podporovaný.

## Architektura

Knihovna je rozdělená do samostatných vrstev:

- `Config/FlexiConfig` - validovaná konfigurace připojení
- `Endpoint/EndpointBuilder` - skládání URL/endpointů pro firemní a agendové cesty
- `Http/HttpTransportInterface` + `Http/GuzzleHttpTransport` - HTTP transport nad dedikovaným klientem vytvořeným přes Guzzlette
- `Response/ResponseParser` - parsování JSON/XML, normalizace kořene `winstrom` a detekce chybových payloadů API
- `Client/FlexiClient` - veřejné API pro `GET/POST/PUT/DELETE` nad Flexi `.json` nebo `.xml` endpointy
- `Exception/*` - sjednocená hierarchie výjimek pro chyby transportu/API/parsing
- `DI/FlexiExtension` - Nette rozšíření, které zapojuje všechny služby z NEON konfigurace

## Použití

Vstříkněte `Acme\AbraFlexi\Client\FlexiClient` do své služby:

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

Pro více firem za běhu injektujte raději továrnu:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use Acme\AbraFlexi\Client\FlexiClientFactory;

final readonly class CompanySync
{
    public function __construct(
        private FlexiClientFactory $flexiClientFactory,
    ) {
    }

    public function loadInvoice(string $company, string $id): array
    {
        return $this->flexiClientFactory
            ->create($company)
            ->get('faktura-vydana', $id);
    }
}
```

Podporované metody:
- `get(string $agenda, ?string $recordId = null, array $query = [])`
- `post(string $agenda, array|string $payload, array $query = [])`
- `put(string $agenda, string $recordId, array|string $payload, array $query = [])`
- `delete(string $agenda, string $recordId, array $query = [])`

Příklad s parametry dotazu a payloadem:

```php
$list = $flexiClient->get('adresar', null, ['limit' => 20, 'detail' => 'full']);

$created = $flexiClient->post('adresar', [
    'kod' => 'CUST-001',
    'nazev' => 'Acme s.r.o.',
]);
```

Pole se automaticky zabalí do tvaru Flexi JSON dokumentu:

```json
{"winstrom":{"adresar":{"kod":"CUST-001","nazev":"Acme s.r.o."}}}
```

Odpovědi se normalizují na vnitřní payload dokumentu, takže Flexi odpověď jako `{"winstrom":{"@version":"1.0","adresar":[...]}}` se vrátí jako `['@version' => '1.0', 'adresar' => [...]]`.

Pokud potřebujete volat XML endpoint, předejte surový XML řetězec. Klient přepne URL požadavku na `.xml` a odešle `Accept`/`Content-Type: application/xml`.

## Zpracování chyb

Klient používá vlastní výjimky:

- `FlexiException` - základní výjimka
- `HttpException` - chyby transportu/stavového kódu (`statusCode`, `responseBody`)
- `ApiErrorException` - aplikační chyba vrácená v payloadu API
- `ParseException` - neplatný nebo neočekávaný formát odpovědi

## Logování

`GuzzleHttpTransport` podporuje volitelný `Psr\Log\LoggerInterface`.

- Logují se události požadavků, odpovědí i chyb
- Citlivé hodnoty se před zalogováním maskují, včetně payloadů požadavků/odpovědí a citlivých hlaviček (`auth`, `password`, `authorization`, `token`, ...)

## Testování

Spuštění testů v Dockeru (PHP 8.2):

```bash
docker compose run --rm app composer test -- --no-coverage
```

Reálný integrační test proti API spouštějte jen při explicitním povolení:

```bash
ABRA_FLEXI_RUN_INTEGRATION=1 composer test:integration
```

Výchozí hodnoty míří na veřejnou demo instanci Flexi:

- `ABRA_FLEXI_BASE_URL=https://demo.flexibee.eu:5434`
- `ABRA_FLEXI_COMPANY=demo`
- `ABRA_FLEXI_USERNAME=winstrom`
- `ABRA_FLEXI_PASSWORD=winstrom`
- `ABRA_FLEXI_TIMEOUT=10`

Aktuální stav:

- Jednotkové testy pro builder endpointů
- Jednotkové testy pro parser odpovědí
- Jednotkové testy pro HTTP transport (včetně maskovaného logování)
- Jednotkové testy pro hlavního klienta
- Kompilační test DI rozšíření
- Volitelný integrační test pokrývající reálný scénář create/read/update/delete proti ABRA Flexi API

# nette-flexi-client

Nette rozšíření a znovupoužitelný klient pro REST API ABRA Flexi.
HTTP komunikace běží přes [`contributte/guzzlette`](https://github.com/contributte/guzzlette),
konfigurace přes NEON, integrace přes vlastní DI extension.

## Obsah

- [Požadavky](#požadavky)
- [Instalace](#instalace)
- [Architektura](#architektura)
- [Konfigurace](#konfigurace)
  - [Jednoduchá – jedna firma](#jednoduchá--jedna-firma)
  - [Multi-tenant – pojmenovaná připojení](#multi-tenant--pojmenovaná-připojení)
  - [Více aliasů extension](#více-aliasů-extension)
- [Použití](#použití)
  - [Základní CRUD](#základní-crud)
  - [Injekce do Nette presenteru](#injekce-do-nette-presenteru)
  - [Práce s fakturami](#práce-s-fakturami)
  - [Filtrování přes Flexi query language](#filtrování-přes-flexi-query-language)
  - [Stránkování velkých seznamů](#stránkování-velkých-seznamů)
  - [Reference na záznamy (`code:`, `ext:`, `id:`)](#reference-na-záznamy-code-ext-id)
  - [Hromadný import více záznamů](#hromadný-import-více-záznamů)
  - [Validace bez zápisu (dry-run, code-only)](#validace-bez-zápisu-dry-run-code-only)
  - [Stažení PDF / dalších formátů](#stažení-pdf--dalších-formátů)
  - [XML payload](#xml-payload)
  - [Multi-tenant runtime](#multi-tenant-runtime)
- [Zpracování chyb](#zpracování-chyb)
- [Logování](#logování)
- [Rozšíření](#rozšíření)
- [Testování](#testování)
- [Odkazy na oficiální dokumentaci ABRA Flexi](#odkazy-na-oficiální-dokumentaci-abra-flexi)

## Požadavky

- PHP 8.2+
- Nette DI 3.2+
- contributte/guzzlette 3.3+
- guzzlehttp/guzzle 7.9+
- volitelně PSR logger (`psr/log`)

## Instalace

```bash
composer require acme/nette-abra-flexi
```

## Architektura

Knihovna je rozdělená do oddělených vrstev s jednosměrnou závislostí
**DI → Client → (Endpoint + Transport + Parser) → Config**:

```
┌────────────────────────────────────────────────────────────────┐
│ DI/FlexiExtension                                              │
│   - načte NEON konfiguraci, registruje služby                  │
│   - dohledá ClientFactory z Guzzlette                          │
└──────────────┬────────────────────────┬────────────────────────┘
               │                        │
   ┌───────────▼────────────┐ ┌─────────▼─────────────────────┐
   │ Client/FlexiClient     │ │ Client/FlexiClientFactory     │
   │ - veřejné API GET/...  │ │ - dynamická tvorba klientů    │
   │ - obal payloadu winstrom│ │ - pojmenovaná připojení       │
   └────┬────────────┬──────┘ └─────────────┬─────────────────┘
        │            │                      │
┌───────▼─────┐ ┌────▼────────────────┐ ┌───▼──────────────────┐
│ Endpoint    │ │ Http/HttpTransport  │ │ Response/Parser      │
│ Builder     │ │ Interface           │ │ - JSON/XML           │
│             │ │   ↑ implementace    │ │ - unwrap winstrom    │
│             │ │ GuzzleHttpTransport │ │ - detekce API errors │
└──────┬──────┘ └────┬────────────────┘ └──────────────────────┘
       │             │
       └─────┬───────┘
             ▼
       ┌────────────┐
       │ FlexiConfig│  baseUrl, company, username, password, timeout
       └────────────┘
```

**Klíčové třídy** (namespace `Acme\AbraFlexi\…`):

| Třída | Účel |
|---|---|
| [`Config\FlexiConfig`](src/Config/FlexiConfig.php) | Validovaná immutable konfigurace připojení |
| [`Endpoint\EndpointBuilder`](src/Endpoint/EndpointBuilder.php) | Sestavuje URL `/c/{company}/{agenda}[/{id}].{json,xml}` |
| [`Http\HttpTransportInterface`](src/Http/HttpTransportInterface.php) | Kontrakt pro transport (lze nahradit) |
| [`Http\GuzzleHttpTransport`](src/Http/GuzzleHttpTransport.php) | Výchozí Guzzle implementace s logováním a maskováním |
| [`Response\ResponseParser`](src/Response/ResponseParser.php) | Parsuje JSON/XML a unifikuje API chyby |
| [`Client\FlexiClient`](src/Client/FlexiClient.php) | Veřejné API – GET/POST/PUT/DELETE |
| [`Client\FlexiClientFactory`](src/Client/FlexiClientFactory.php) | Tovární třída pro multi-company / multi-server |
| [`DI\FlexiExtension`](src/DI/FlexiExtension.php) | Nette extension – registrace služeb z NEON |
| [`Exception\*`](src/Exception/) | `FlexiException`, `HttpException`, `TransportException`, `ApiErrorException`, `ParseException` |

## Konfigurace

### Jednoduchá – jedna firma

```neon
extensions:
    guzzle: Contributte\Guzzlette\DI\GuzzleExtension
    abraFlexi: Acme\AbraFlexi\DI\FlexiExtension

abraFlexi:
    baseUrl: https://demo.flexibee.eu:5434
    company: demo
    username: winstrom
    password: winstrom
    timeout: 10.0
    guzzle:
        headers:
            Accept: application/json
            User-Agent: my-app/1.0
```

`abraFlexi.guzzle` jsou volby předané přímo do
[`Contributte\Guzzlette\ClientFactory::createClient()`](https://github.com/contributte/guzzlette).
Knihovna si tak interně vytvoří dedikovaného Guzzle klienta nezávisle
na ostatních klientech registrovaných přes Guzzlette.

Po kompilaci je v DI kontejneru dostupný:

- `Acme\AbraFlexi\Client\FlexiClient` (autowire)
- `Acme\AbraFlexi\Client\FlexiClientFactory` (autowire)

### Multi-tenant – pojmenovaná připojení

Pro více firem nebo více Flexi serverů stačí jedna instance extension
a pojmenované connection profily:

```neon
extensions:
    guzzle: Contributte\Guzzlette\DI\GuzzleExtension
    abraFlexi: Acme\AbraFlexi\DI\FlexiExtension

abraFlexi:
    baseUrl: https://erp.example.com
    username: api-user
    password: api-secret
    timeout: 10.0

    connections:
        # zkrácený zápis: stačí název firmy, ostatní se zdědí z výchozí konfigurace
        sales: company-sales

        # plný zápis: lze přepsat libovolnou hodnotu i Guzzle volby
        warehouse:
            company: company-warehouse
            timeout: 20.0
            guzzle:
                headers:
                    X-Tenant: warehouse

        backup:
            baseUrl: https://erp-backup.example.com
            company: company-sales
            username: backup-user
            password: backup-secret

    defaultConnection: sales
```

Pak můžeš:

| Použití | Výsledek |
|---|---|
| `inject FlexiClient` | klient výchozího připojení (`sales`) |
| `$factory->createNamed('warehouse')` | klient s přepsanou konfigurací |
| `$factory->create('runtime-company')` | klient se zděděnou base config + zadanou company |
| `$factory->names()` / `hasNamed($name)` | introspekce dostupných profilů |

Pravidla:

- Pokud `defaultConnection` není uveden a `connections` má **jeden** profil,
  použije se automaticky.
- Pokud `defaultConnection` neexistuje v `connections`, kompilace skončí
  `LogicException`.
- Pokud `connections` neuvedeš, použije se klasická konfigurace s `company`.

### Více aliasů extension

Pokud preferuješ klasický model, lze stále registrovat více extension aliasů
vedle sebe:

```neon
extensions:
    guzzle: Contributte\Guzzlette\DI\GuzzleExtension
    abraSales: Acme\AbraFlexi\DI\FlexiExtension
    abraWarehouse: Acme\AbraFlexi\DI\FlexiExtension

abraSales:
    baseUrl: https://erp.example.com
    company: sales
    username: api-user
    password: api-secret

abraWarehouse:
    baseUrl: https://erp.example.com
    company: warehouse
    username: api-user
    password: api-secret
```

Služby pak rozlišíš jménem (`@abraSales.client`, `@abraWarehouse.client`).
Pro nový kód je ale doporučená cesta `connections` – zachovává jeden alias
a autowiring funguje díky `defaultConnection`.

## Použití

### Základní CRUD

```php
<?php

declare(strict_types=1);

namespace App\Model;

use Acme\AbraFlexi\Client\FlexiClient;

final readonly class AddressBook
{
    public function __construct(
        private FlexiClient $flexiClient,
    ) {
    }

    /** @return array<mixed> */
    public function list(): array
    {
        return $this->flexiClient->get('adresar', null, [
            'limit' => 50,
            'detail' => 'full',
        ]);
    }

    /** @return array<mixed> */
    public function detail(string $id): array
    {
        return $this->flexiClient->get('adresar', $id);
    }

    /** @param array<string, mixed> $payload @return array<mixed> */
    public function create(array $payload): array
    {
        return $this->flexiClient->post('adresar', $payload);
    }

    /** @param array<string, mixed> $payload @return array<mixed> */
    public function update(string $id, array $payload): array
    {
        return $this->flexiClient->put('adresar', $id, $payload);
    }

    public function remove(string $id): void
    {
        $this->flexiClient->delete('adresar', $id);
    }
}
```

Pole se automaticky zabalí do tvaru Flexi JSON dokumentu. Tedy
`->post('adresar', ['kod' => 'CUST-001', 'nazev' => 'Acme s.r.o.'])`
odešle:

```json
{"winstrom":{"adresar":{"kod":"CUST-001","nazev":"Acme s.r.o."}}}
```

Odpovědi se naopak rozbalí, takže `{"winstrom":{"@version":"1.0","adresar":[…]}}`
se vrátí už bez kořenového uzlu jako `['@version' => '1.0', 'adresar' => […]]`.

Podporované metody a signatury klienta:

| Metoda | Endpoint | Popis |
|---|---|---|
| `get(string $agenda, ?string $recordId = null, array $query = [])` | `GET /c/{company}/{agenda}[/{id}].json` | Detail nebo seznam |
| `post(string $agenda, array\|string $payload, array $query = [])` | `POST /c/{company}/{agenda}.json` | Vytvoření / hromadný import |
| `put(string $agenda, string $recordId, array\|string $payload, array $query = [])` | `PUT /c/{company}/{agenda}/{id}.json` | Update konkrétního záznamu |
| `delete(string $agenda, string $recordId, array $query = [])` | `DELETE /c/{company}/{agenda}/{id}.json` | Smazání záznamu |

Pole `$query` může obsahovat libovolné Flexi parametry
(`limit`, `start`, `detail`, `code-only`, `dry-run`, …).

### Injekce do Nette presenteru

Klient se chová jako obyčejná služba – v presenteru ho stačí dostat
konstruktorem (případně `inject` metodou):

```php
<?php

declare(strict_types=1);

namespace App\Presenters;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\Exception\ApiErrorException;
use Nette\Application\UI\Presenter;

final class InvoicePresenter extends Presenter
{
    public function __construct(
        private readonly FlexiClient $flexiClient,
    ) {
        parent::__construct();
    }

    public function renderDetail(string $id): void
    {
        try {
            $this->template->invoice = $this->flexiClient->get('faktura-vydana', $id);
        } catch (ApiErrorException $e) {
            $this->flashMessage($e->getMessage(), 'error');
            $this->redirect('default');
        }
    }
}
```

### Práce s fakturami

Klient nemá speciální metody pro faktury – používá stejné CRUD nad jejich
agendou:

- vydaná faktura: `faktura-vydana`
- přijatá faktura: `faktura-prijata`

```php
$created = $flexiClient->post('faktura-vydana', [
    'kod' => 'FV-2026-0001',
    // další pole faktury podle schématu Flexi
]);
$createdId = $created['results'][0]['id'] ?? null;

$invoice = $flexiClient->get('faktura-vydana', '123');

$list = $flexiClient->get('faktura-vydana', null, [
    'detail' => 'full',
    'limit' => 100,
]);
$invoices = $list['faktura-vydana'] ?? [];
```

### Filtrování přes Flexi query language

ABRA Flexi podporuje vlastní filtrovací syntaxi předávanou jako součást
endpointu v závorkách. Stačí ji zapsat do názvu agendy – `EndpointBuilder`
ji ponechá tak, jak je:

```php
// Vsechny adresy s konkretnim kodem
$result = $flexiClient->get("adresar/(kod eq 'CUST-001')");

// Faktury vystavene v dubnu 2026, nezaplacene
$result = $flexiClient->get(
    "faktura-vydana/(datVyst gte '2026-04-01' and datVyst lt '2026-05-01' and stavUhrK ne 'stavUhr.uhrazeno')",
    null,
    ['detail' => 'summary', 'limit' => 200],
);

$invoices = $result['faktura-vydana'] ?? [];
```

> Operátory: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `like`, `begins`, `ends`,
> spojovat lze přes `and` / `or`. Hodnoty se uvozují apostrofem.

### Stránkování velkých seznamů

Pro průchod celou agendou kombinuj `limit` + `start`. Flexi vrací pole
v klíči podle názvu agendy (zde `adresar`):

```php
$pageSize = 100;
$start = 0;

do {
    $page = $flexiClient->get('adresar', null, [
        'detail' => 'summary',
        'limit' => $pageSize,
        'start' => $start,
        'order' => 'id@A',
    ]);

    $rows = $page['adresar'] ?? [];
    foreach ($rows as $row) {
        // zpracuj radek
    }

    $start += $pageSize;
} while (count($rows) === $pageSize);
```

### Reference na záznamy (`code:`, `ext:`, `id:`)

Flexi umožňuje odkazovat na cizí záznamy přes string prefix místo numerického
ID. Klient nemá speciální podporu, prostě tu hodnotu pošli:

```php
$flexiClient->post('faktura-vydana', [
    'kod' => 'FV-2026-0042',
    // odkaz na adresar.kod = 'CUST-001'
    'firma' => 'code:CUST-001',
    // odkaz na typ dokladu pres externi ID
    'typDokl' => 'ext:erp-mapping:invoice-out',
    // explicitne pres numericke ID
    'mena' => 'id:1',
]);
```

### Hromadný import více záznamů

`POST` na agendu přijme i pole více záznamů – stačí předat seznam místo
asociativního pole. Klient ho zabalí stejně jako jeden záznam:

```php
$result = $flexiClient->post('adresar', [
    ['kod' => 'CUST-001', 'nazev' => 'Acme s.r.o.'],
    ['kod' => 'CUST-002', 'nazev' => 'Beta a.s.'],
    ['kod' => 'CUST-003', 'nazev' => 'Ceta s.r.o.'],
]);

foreach ($result['results'] ?? [] as $i => $row) {
    echo "{$i}: id={$row['id']}, ref={$row['ref']}\n";
}
```

Atomicita: pokud kterýkoli záznam neprojde validací, Flexi celou dávku
zamítne a klient vyhodí `ApiErrorException` s detaily v `getDetails()`.

### Validace bez zápisu (dry-run, code-only)

Za běhu lze vyzkoušet, jestli by zápis prošel, aniž by se opravdu uložil:

```php
$preview = $flexiClient->post('faktura-vydana', $payload, [
    // nezapisovat, jen vratit vysledek validace
    'dry-run' => 'true',
    // v odpovedich preferovat zkratky misto numerickych ID
    'code-only' => 'true',
]);
```

### Stažení PDF / dalších formátů

Standardní `get()` parsuje JSON nebo XML – PDF binárka by selhala v parseru.
Pro netextové formáty si vezmi spodní transport (`HttpTransportInterface`)
a zavolej požadovanou URL ručně:

```php
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Http\HttpTransportInterface;

final readonly class InvoicePdfDownloader
{
    public function __construct(
        private EndpointBuilder $endpointBuilder,
        private HttpTransportInterface $httpTransport,
    ) {
    }

    public function download(string $invoiceId): string
    {
        // Flexi pouziva priponu primo v cest\u011b: /c/{company}/faktura-vydana/{id}.pdf
        $url = $this->endpointBuilder->forCompany([
            'faktura-vydana',
            $invoiceId . '.pdf',
        ]);

        $response = $this->httpTransport->request('GET', $url, [
            'headers' => ['Accept' => 'application/pdf'],
        ]);

        if ($response->statusCode >= 400) {
            throw new \RuntimeException("PDF download failed: {$response->statusCode}");
        }

        return $response->body;
    }
}
```

Obdobně lze stahovat `.isdoc`, `.csv` či `.xlsx` – stačí změnit příponu
v segmentu a hlavičku `Accept`.

### XML payload

Pokud je potřeba volat XML endpoint (např. proto, že posíláš ručně sestavený
Flexi dokument), předej raw XML string. Klient sám:

- přepne URL na `.xml`,
- nastaví `Accept`/`Content-Type: application/xml`,
- payload nezabaluje (musí být kompletní `<winstrom>…</winstrom>`).

```php
$result = $flexiClient->post('adresar', <<<XML
<winstrom>
    <adresar>
        <kod>XML-001</kod>
        <nazev>Acme s.r.o.</nazev>
    </adresar>
</winstrom>
XML);
```

### Multi-tenant runtime

Pokud potřebuješ za běhu rozhodovat, do jaké firmy se request pošle, injektuj
factory:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use Acme\AbraFlexi\Client\FlexiClientFactory;

final readonly class CompanySync
{
    public function __construct(
        private FlexiClientFactory $factory,
    ) {
    }

    /** @return array<mixed> */
    public function loadInvoice(string $companyOrConnectionName, string $id): array
    {
        $client = $this->factory->hasNamed($companyOrConnectionName)
            ? $this->factory->createNamed($companyOrConnectionName)
            : $this->factory->create($companyOrConnectionName);

        return $client->get('faktura-vydana', $id);
    }
}
```

## Zpracování chyb

Knihovna vyhazuje pouze vlastní výjimky, všechny dědí z
`Acme\AbraFlexi\Exception\FlexiException`:

| Výjimka | Kdy nastane | Užitečné metody |
|---|---|---|
| `TransportException` | Síťová chyba – DNS, TLS, timeout, odmítnuté spojení (server nevrátil HTTP odpověď). | – |
| `HttpException` | Server vrátil HTTP status ≥ 400 bez parsovatelného Flexi error payloadu. | `getStatusCode()`, `getResponseBody()` |
| `ApiErrorException` | Flexi vrátilo chybový payload (pole `errors`, `error`, `success: false`, atd.). Funguje stejně pro 200 i 4xx odpovědi. | `getErrorCode()`, `getDetails()` |
| `ParseException` | Tělo odpovědi není validní JSON ani XML, případně payload pro odeslání nelze serializovat. | – |

`TransportException` dědí z `HttpException` (status code = 0), takže existující
catch bloky pro `HttpException` zůstávají kompatibilní.

Doporučený pattern:

```php
use Acme\AbraFlexi\Exception\ApiErrorException;
use Acme\AbraFlexi\Exception\HttpException;
use Acme\AbraFlexi\Exception\ParseException;
use Acme\AbraFlexi\Exception\TransportException;

try {
    $invoice = $flexiClient->get('faktura-vydana', $id);
} catch (ApiErrorException $e) {
    // Flexi vratilo strukturovanou chybu - mam kod a detaily.
    $logger->warning('Flexi business error', [
        'code' => $e->getErrorCode(),
        'details' => $e->getDetails(),
    ]);
    throw new InvoiceLoadFailed($e->getMessage(), previous: $e);
} catch (TransportException $e) {
    // Sit/timeout - vetsinou retryovatelne.
    throw new TemporaryFailure(previous: $e);
} catch (HttpException $e) {
    // Jine HTTP chyby (4xx/5xx bez parsovatelneho tela) - obvykle ne-retry.
    $logger->error('Flexi HTTP error', [
        'status' => $e->getStatusCode(),
        'body' => $e->getResponseBody(),
    ]);
    throw $e;
} catch (ParseException $e) {
    // Flexi vratilo neceho, co neumime parsovat - vada na strane API.
    throw $e;
}
```

`FlexiClient::request()` se navíc snaží automaticky převést `HttpException`
na `ApiErrorException`, pokud je v jeho těle parsovatelný Flexi error payload.
Aplikace tak většinou vidí jednu sjednocenou chybu bez ohledu na to, zda
Flexi vrátilo 200 nebo 400.

## Logování

`GuzzleHttpTransport` přijímá volitelný `Psr\Log\LoggerInterface`. Pokud je
v DI kontejneru zaregistrovaný PSR logger, autowiring ho přidá automaticky;
jinak se logování vypne (žádné no-op pole, žádný NullLogger boilerplate).

Logované události:

| Úroveň | Událost | Klíče v `context` |
|---|---|---|
| `debug` | Odeslání requestu | `method`, `url`, `options` |
| `debug` | Přijetí response | `method`, `url`, `statusCode`, `headers` |
| `warning` | HTTP status ≥ 400 | `method`, `url`, `statusCode`, `responseBody` |
| `error` | Transportní chyba | `method`, `url`, `exceptionClass`, `exceptionMessage` |
| `warning` | Business chyba API (z `FlexiClient`) | `method`, `agenda`, `recordId`, `query`, `errorCode`, `details` |

**Maskování citlivých dat** probíhá automaticky:

- HTTP Basic auth (`auth` Guzzle option, `user:pass` v URL),
- query parametry s názvem obsahujícím `password`, `token`, `secret`,
  `authorization`, `cookie`, …,
- hlavičky `Authorization`, `Set-Cookie`, …,
- klíče v JSON payloadu (rekurzivně),
- elementy a atributy v XML payloadu (rekurzivně).

Hesla z konfigurace **nikdy** nejsou v logu (ani v Guzzle `auth` poli, kde
se nahradí druhý prvek `***`).

Tip: pro lokální vývoj v Nette stačí mít zaregistrovaného Tracy loggera nebo
Monolog přes `contributte/monolog`. Knihovna sama PSR logger nezavádí.

## Rozšíření

### Vlastní HTTP transport

Stačí implementovat `Acme\AbraFlexi\Http\HttpTransportInterface` a
zaregistrovat ho v DI kontejneru. Hlavní klient si vezme tvoji implementaci
přes autowiring.

```php
final class CachingTransport implements HttpTransportInterface
{
    public function __construct(
        private HttpTransportInterface $inner,
        private CacheInterface $cache,
    ) {
    }

    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        if ($method !== 'GET') {
            return $this->inner->request($method, $url, $options);
        }

        $key = sha1($url);
        $cached = $this->cache->load($key);
        if ($cached instanceof HttpResponse) {
            return $cached;
        }

        $response = $this->inner->request($method, $url, $options);
        $this->cache->save($key, $response, 60);

        return $response;
    }
}
```

```neon
services:
    abraFlexi.cachingTransport:
        type: Acme\AbraFlexi\Http\HttpTransportInterface
        factory: App\Flexi\CachingTransport(
            inner: @abraFlexi.httpTransport,
            cache: @cache.storage,
        )
        autowired: self
```

### Programové vytvoření klienta (mimo DI)

```php
use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Http\GuzzleHttpTransport;
use Acme\AbraFlexi\Response\ResponseParser;

$config = new FlexiConfig(
    baseUrl: 'https://demo.flexibee.eu:5434',
    company: 'demo',
    username: 'winstrom',
    password: 'winstrom',
    timeout: 10.0,
);

$client = new FlexiClient(
    endpointBuilder: new EndpointBuilder($config),
    httpTransport: new GuzzleHttpTransport(new GuzzleHttp\Client(), $config),
    responseParser: new ResponseParser(),
);
```

## Testování

Pouštění testů v Dockeru (PHP 8.2):

```bash
docker compose run --rm app composer test -- --no-coverage
```

Reálný integrační test proti API spouštěj jen explicitně:

```bash
ABRA_FLEXI_RUN_INTEGRATION=1 composer test:integration
```

Výchozí hodnoty pro integrační test míří na veřejnou demo instanci:

- `ABRA_FLEXI_BASE_URL=https://demo.flexibee.eu:5434`
- `ABRA_FLEXI_COMPANY=demo`
- `ABRA_FLEXI_USERNAME=winstrom`
- `ABRA_FLEXI_PASSWORD=winstrom`
- `ABRA_FLEXI_TIMEOUT=10`

Pokrytí:

- jednotkové testy pro builder endpointů,
- jednotkové testy pro parser odpovědí (JSON i XML, error payloads),
- jednotkové testy pro HTTP transport včetně maskování v logu,
- jednotkové testy pro hlavního klienta (CRUD, normalizace payloadu, error mapping),
- jednotkové testy pro `FlexiClientFactory` (named + runtime),
- kompilační test DI rozšíření (jeden alias, více aliasů, named connections),
- volitelný integrační test pokrývající create/read/update/delete proti reálné Flexi.

## Odkazy na oficiální dokumentaci ABRA Flexi

Kompletní specifikaci REST API udržuje vendor – knihovna pouze řeší HTTP
transport, autentizaci, sestavování URL a unifikaci chyb. Sémantika polí
v jednotlivých agendách (např. povinné atributy faktury, stavy dokladů,
číselníky) zůstává na konzultaci s následujícími zdroji:

| Téma | Kde to řeší knihovna | Oficiální dokumentace |
|---|---|---|
| Vstupní bod dokumentace | – | [Dokumentace REST API](https://podpora.flexibee.eu/cs/collections/2677220-dokumentace-rest-api) |
| Licencování přístupu k API | – | [Licencování přístupu k API](https://podpora.flexibee.eu/cs/articles/10097467-licencovani-pristupu-k-api) |
| Začátečnický přehled | – | [Jak používat API?](https://podpora.flexibee.eu/cs/articles/12495537-jak-pouzivat-api) |
| Autentizace (HTTP Basic, session) | `FlexiConfig` + `GuzzleHttpTransport` (`auth` option) | [Autentizace](https://podpora.flexibee.eu/cs/articles/4713880-autentizace) |
| Sestavování URL `/c/{company}/{agenda}` | `EndpointBuilder` | [Sestavování URL](https://podpora.flexibee.eu/cs/articles/4713911-sestavovani-url) |
| Filtrace `(kod eq 'X')`, operátory | předáváš jako součást `agenda` parametru | [Sestavování URL](https://podpora.flexibee.eu/cs/articles/4713911-sestavovani-url) (sekce *Filtrování*) |
| Podporované formáty (JSON, XML, …) | `PayloadEncoder` + `ResponseParser` | [Podporované formáty](https://podpora.flexibee.eu/cs/articles/4719998-podporovane-formaty) |
| Export PDF / XLS / ISDOC | low-level `HttpTransportInterface` (viz [příklad výše](#stažení-pdf--dalších-formátů)) | [Export tiskových sestav](https://podpora.flexibee.eu/cs/articles/4720042-export-tiskovych-sestav-pdf-xls) |
| Hromadné importy a `results` v odpovědi | `FlexiClient::post()` s polem záznamů | [Sestavování URL](https://podpora.flexibee.eu/cs/articles/4713911-sestavovani-url) |
| Reference `code:`, `ext:`, `id:` | předáváš v payloadu (viz [příklad výše](#reference-na-záznamy-code-ext-id)) | [Sestavování URL](https://podpora.flexibee.eu/cs/articles/4713911-sestavovani-url) |
| Získání položek dokladů | předáš `detail=full` nebo zanořený dotaz | [Získání položek dokladů](https://podpora.flexibee.eu/cs/articles/4713930-ziskani-polozek-dokladu) |
| Navázané doklady přes API | běžné CRUD na propojovacích agendách | [Navázané doklady přes API](https://podpora.flexibee.eu/cs/articles/4858948-navazane-doklady-pres-api) |
| Mazání pomocí `action="delete"` v JSON | součást payloadu | [Použití `action="delete"`](https://podpora.flexibee.eu/cs/articles/3852838-vymazani-zanorene-evidence-prostrednictvim-json-formatu) |

> Tip: většina článků má pod českou verzí i anglický ekvivalent.
> Pokud něco v REST API chybí (např. specifická vlastnost agendy),
> ověř ji nejdřív v UI Flexi v sekci **Nástroje → REST API** – tam je
> dostupný interaktivní průzkumník endpointů přímo proti tvojí instanci.

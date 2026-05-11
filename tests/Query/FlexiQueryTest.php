<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Query;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Exception\ApiErrorException;
use Acme\AbraFlexi\Http\HttpResponse;
use Acme\AbraFlexi\Http\HttpTransportInterface;
use Acme\AbraFlexi\Query\FlexiQuery;
use Acme\AbraFlexi\Response\ResponseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlexiQuery::class)]
final class FlexiQueryTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Sestaveni query parametru
    // -----------------------------------------------------------------------

    public function testWhereSetsSingleFilter(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')
            ->where("(stav='aktivni')")
            ->get();

        self::assertStringContainsString(
            'filter=%28stav%3D%27aktivni%27%29',
            $transport->lastUrl,
        );
    }

    public function testMultipleWhereCallsJoinWithAnd(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')
            ->where("(stav='aktivni')")
            ->where("(stat='CZ')")
            ->get();

        self::assertStringContainsString('filter=', $transport->lastUrl);
        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame("(stav='aktivni') and (stat='CZ')", $parsed['filter']);
    }

    public function testLimitSetsLimitParam(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')->limit(25)->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame('25', $parsed['limit']);
    }

    public function testOffsetSetsStartParam(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')->offset(50)->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame('50', $parsed['start']);
    }

    public function testOrderByAscUsesAtASuffix(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')->orderByAsc('nazev')->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame('nazev@A', $parsed['order']);
    }

    public function testOrderByDescUsesAtDSuffix(): void
    {
        $transport = $this->transport('{"winstrom":{"faktura-vydana":[]}}');
        $this->createQuery($transport, 'faktura-vydana')->orderByDesc('datVyst')->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame('datVyst@D', $parsed['order']);
    }

    public function testOrderByDefaultIsAscending(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')->orderBy('kod')->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame('kod@A', $parsed['order']);
    }

    public function testMultipleOrdersJoinWithComma(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')
            ->orderByDesc('datVyst')
            ->orderByAsc('kod')
            ->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame('datVyst@D,kod@A', $parsed['order']);
    }

    public function testDetailSetsDetailParam(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')->detail('full')->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame('full', $parsed['detail']);
    }

    public function testIncludesSetsIncludesParam(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')
            ->includes('adresar.kontakty', 'adresar.bankovniUcty')
            ->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame('adresar.kontakty,adresar.bankovniUcty', $parsed['includes']);
    }

    public function testWithAddsArbitraryParam(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')->with('detail', 'summary')->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame('summary', $parsed['detail']);
    }

    // -----------------------------------------------------------------------
    // Immutabilita
    // -----------------------------------------------------------------------

    public function testBuilderMethodsReturnNewInstance(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $original = $this->createQuery($transport, 'adresar');
        $modified = $original->limit(10);

        self::assertNotSame($original, $modified);
    }

    public function testOriginalBuilderIsNotModifiedAfterChaining(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $base = $this->createQuery($transport, 'adresar')->limit(50);

        // odvozeny builder prida filter
        $base->where("(stav='aktivni')")->get();

        // zakladni builder nema filter
        $base->get();
        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertArrayNotHasKey('filter', $parsed);
    }

    // -----------------------------------------------------------------------
    // HTTP metody
    // -----------------------------------------------------------------------

    public function testGetSendsGetRequest(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[{"kod":"ABC"}]}}');
        $this->createQuery($transport, 'adresar')->get();

        self::assertSame('GET', $transport->lastMethod);
    }

    public function testGetWithRecordIdIncludesIdInUrl(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[{"kod":"ABC"}]}}');
        $this->createQuery($transport, 'adresar')->get('42');

        self::assertSame('GET', $transport->lastMethod);
        self::assertStringContainsString('/adresar/42.json', $transport->lastUrl);
    }

    public function testPostSendsPostRequest(): void
    {
        $transport = $this->transport('{"winstrom":{"success":"true","results":[{"id":"1"}]}}');
        $this->createQuery($transport, 'adresar')->post(['kod' => 'ABC']);

        self::assertSame('POST', $transport->lastMethod);
    }

    public function testPutSendsPutRequest(): void
    {
        $transport = $this->transport('{"winstrom":{"success":"true"}}');
        $this->createQuery($transport, 'adresar')->put('42', ['nazev' => 'Updated']);

        self::assertSame('PUT', $transport->lastMethod);
        self::assertStringContainsString('/adresar/42.json', $transport->lastUrl);
    }

    public function testDeleteSendsDeleteRequest(): void
    {
        $transport = $this->transport('');
        $this->createQuery($transport, 'adresar')->delete('42');

        self::assertSame('DELETE', $transport->lastMethod);
        self::assertStringContainsString('/adresar/42.json', $transport->lastUrl);
    }

    // -----------------------------------------------------------------------
    // Kombinace parametru
    // -----------------------------------------------------------------------

    public function testCombinedChainBuildsCorrectQueryString(): void
    {
        $transport = $this->transport('{"winstrom":{"faktura-vydana":[]}}');
        $this->createQuery($transport, 'faktura-vydana')
            ->where("(stav='uhrazena')")
            ->limit(10)
            ->offset(20)
            ->orderByDesc('datVyst')
            ->detail('full')
            ->get();

        $parsed = $this->parseQueryFromUrl($transport->lastUrl);
        self::assertSame("(stav='uhrazena')", $parsed['filter']);
        self::assertSame('10', $parsed['limit']);
        self::assertSame('20', $parsed['start']);
        self::assertSame('datVyst@D', $parsed['order']);
        self::assertSame('full', $parsed['detail']);
    }

    public function testNoParamsProducesEmptyQueryString(): void
    {
        $transport = $this->transport('{"winstrom":{"adresar":[]}}');
        $this->createQuery($transport, 'adresar')->get();

        self::assertStringEndsNotWith('?', $transport->lastUrl);
        self::assertStringNotContainsString('?', $transport->lastUrl);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createQuery(SpyTransport $transport, string $agenda): FlexiQuery
    {
        $config = new FlexiConfig(
            baseUrl: 'https://demo.flexibee.eu',
            company: 'demo-company',
            username: 'demo-user',
            password: 'demo-password',
            timeout: 10.0,
        );

        $client = new FlexiClient(
            endpointBuilder: new EndpointBuilder($config),
            httpTransport: $transport,
            responseParser: new ResponseParser(),
        );

        return $client->query($agenda);
    }

    private function transport(string $body): SpyTransport
    {
        return new SpyTransport(new HttpResponse(200, ['Content-Type' => ['application/json']], $body));
    }

    /** @return array<string, string> */
    private function parseQueryFromUrl(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str((string) $query, $params);

        return array_map('strval', $params);
    }
}

final class SpyTransport implements HttpTransportInterface
{
    public string $lastMethod = '';
    public string $lastUrl = '';

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function __construct(
        private readonly HttpResponse $response,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $this->lastMethod = $method;
        $this->lastUrl = $url;
        $this->lastOptions = $options;

        return $this->response;
    }
}

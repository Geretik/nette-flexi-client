<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Client;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Http\HttpResponse;
use Acme\AbraFlexi\Http\HttpTransportInterface;
use Acme\AbraFlexi\Response\ResponseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlexiClient::class)]
final class FlexiClientPaginateTest extends TestCase
{
    public function testPaginateYieldsNothingWhenFirstPageIsEmpty(): void
    {
        $transport = new MultiPageTransport([
            '{"winstrom":{"adresar":[]}}',
        ]);
        $client = $this->createClient($transport);

        $records = iterator_to_array($client->paginate('adresar', [], 3));

        self::assertSame([], $records);
        self::assertSame(1, $transport->callCount);
    }

    public function testPaginateYieldsAllRecordsWhenSinglePartialPage(): void
    {
        $transport = new MultiPageTransport([
            '{"winstrom":{"@version":"1.0","adresar":[{"kod":"A"},{"kod":"B"}]}}',
        ]);
        $client = $this->createClient($transport);

        $records = iterator_to_array($client->paginate('adresar', [], 100));

        self::assertCount(2, $records);
        self::assertSame('A', $records[0]['kod']);
        self::assertSame('B', $records[1]['kod']);
        self::assertSame(1, $transport->callCount);
    }

    public function testPaginateStopsAfterFullPageFollowedByEmptyPage(): void
    {
        $row = static fn(int $i) => '{"kod":"R' . $i . '"}';
        $page1 = '[' . implode(',', array_map($row, range(1, 3))) . ']';

        $transport = new MultiPageTransport([
            '{"winstrom":{"adresar":' . $page1 . '}}',
            '{"winstrom":{"adresar":[]}}',
        ]);
        $client = $this->createClient($transport);

        $records = iterator_to_array($client->paginate('adresar', [], 3));

        self::assertCount(3, $records);
        self::assertSame(2, $transport->callCount);
    }

    public function testPaginateFetchesMultiplePagesUntilPartialPage(): void
    {
        $row = static fn(int $i) => '{"kod":"R' . $i . '"}';
        $page1 = '[' . implode(',', array_map($row, range(1, 2))) . ']';
        $page2 = '[' . implode(',', array_map($row, range(3, 4))) . ']';
        $page3 = '[' . $row(5) . ']';

        $transport = new MultiPageTransport([
            '{"winstrom":{"adresar":' . $page1 . '}}',
            '{"winstrom":{"adresar":' . $page2 . '}}',
            '{"winstrom":{"adresar":' . $page3 . '}}',
        ]);
        $client = $this->createClient($transport);

        $records = iterator_to_array($client->paginate('adresar', [], 2));

        self::assertCount(5, $records);
        self::assertSame('R1', $records[0]['kod']);
        self::assertSame('R5', $records[4]['kod']);
        self::assertSame(3, $transport->callCount);
    }

    public function testPaginateSendsCorrectLimitAndStartParams(): void
    {
        $transport = new MultiPageTransport([
            '{"winstrom":{"adresar":[{"kod":"A"},{"kod":"B"}]}}',
            '{"winstrom":{"adresar":[]}}',
        ]);
        $client = $this->createClient($transport);

        iterator_to_array($client->paginate('adresar', [], 2));

        self::assertSame(
            'https://demo.flexibee.eu/c/demo-company/adresar.json?limit=2&start=0',
            $transport->recordedUrls[0],
        );
        self::assertSame(
            'https://demo.flexibee.eu/c/demo-company/adresar.json?limit=2&start=2',
            $transport->recordedUrls[1],
        );
    }

    public function testPaginateMergesExtraQueryParams(): void
    {
        $transport = new MultiPageTransport([
            '{"winstrom":{"adresar":[]}}',
        ]);
        $client = $this->createClient($transport);

        iterator_to_array($client->paginate('adresar', ['detail' => 'full'], 10));

        self::assertStringContainsString('detail=full', $transport->recordedUrls[0]);
        self::assertStringContainsString('limit=10', $transport->recordedUrls[0]);
    }

    public function testPaginateViaQueryBuilderAppliesFilters(): void
    {
        $transport = new MultiPageTransport([
            '{"winstrom":{"adresar":[]}}',
        ]);
        $client = $this->createClient($transport);

        iterator_to_array(
            $client->query('adresar')
                ->where("(stav='aktivni')")
                ->paginate(10),
        );

        self::assertStringContainsString('filter=', $transport->recordedUrls[0]);
    }

    private function createClient(HttpTransportInterface $transport): FlexiClient
    {
        $config = new FlexiConfig(
            baseUrl: 'https://demo.flexibee.eu',
            company: 'demo-company',
            username: 'demo-user',
            password: 'demo-password',
            timeout: 10.0,
        );

        return new FlexiClient(
            endpointBuilder: new EndpointBuilder($config),
            httpTransport: $transport,
            responseParser: new ResponseParser(),
        );
    }
}

/**
 * Transport vracejici pripravene odpovedi po rade; po vycerpani vsech
 * odpovedi vraci posledni z nich.
 */
final class MultiPageTransport implements HttpTransportInterface
{
    public int $callCount = 0;

    /** @var list<string> */
    public array $recordedUrls = [];

    /** @param list<string> $bodies */
    public function __construct(
        private readonly array $bodies,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $this->recordedUrls[] = $url;
        $body = $this->bodies[$this->callCount] ?? end($this->bodies);
        $this->callCount++;

        return new HttpResponse(200, ['Content-Type' => ['application/json']], (string) $body);
    }
}

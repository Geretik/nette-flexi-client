<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Payload;

use Acme\AbraFlexi\Exception\ParseException;
use Acme\AbraFlexi\Payload\PayloadEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadEncoder::class)]
final class PayloadEncoderTest extends TestCase
{
    public function testEncodesArrayAsJsonAndWrapsInWinstromRoot(): void
    {
        $encoded = (new PayloadEncoder())->encode('adresar', ['kod' => 'ABC']);

        self::assertSame(PayloadEncoder::FORMAT_JSON, $encoded['format']);
        self::assertSame('application/json', $encoded['contentType']);
        self::assertSame('{"winstrom":{"adresar":{"kod":"ABC"}}}', $encoded['body']);
    }

    public function testKeepsExistingWinstromRoot(): void
    {
        $encoded = (new PayloadEncoder())->encode('adresar', [
            'winstrom' => [
                'adresar' => ['kod' => 'ABC'],
                '@version' => '1.0',
            ],
        ]);

        self::assertSame(
            '{"winstrom":{"adresar":{"kod":"ABC"},"@version":"1.0"}}',
            $encoded['body'],
        );
    }

    public function testUsesLastSegmentOfAgendaPathAsRootNode(): void
    {
        $encoded = (new PayloadEncoder())->encode('faktura-vydana/firma', ['kod' => 'X']);

        self::assertSame('{"winstrom":{"firma":{"kod":"X"}}}', $encoded['body']);
    }

    public function testRawJsonStringIsSentAsJson(): void
    {
        $encoded = (new PayloadEncoder())->encode('adresar', '{"winstrom":{"adresar":{"kod":"ABC"}}}');

        self::assertSame(PayloadEncoder::FORMAT_JSON, $encoded['format']);
        self::assertSame('application/json', $encoded['contentType']);
        self::assertSame('{"winstrom":{"adresar":{"kod":"ABC"}}}', $encoded['body']);
    }

    public function testRawXmlStringIsDetectedAsXml(): void
    {
        $xml = "  <winstrom><adresar><kod>X</kod></adresar></winstrom>";

        $encoded = (new PayloadEncoder())->encode('adresar', $xml);

        self::assertSame(PayloadEncoder::FORMAT_XML, $encoded['format']);
        self::assertSame('application/xml', $encoded['contentType']);
        self::assertSame($xml, $encoded['body']);
    }

    public function testThrowsParseExceptionWhenAgendaIsEmptyForArrayPayload(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Agenda must not be empty');

        (new PayloadEncoder())->encode('', ['kod' => 'X']);
    }

    public function testThrowsParseExceptionForNonEncodableValues(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('could not be encoded to JSON');

        (new PayloadEncoder())->encode('adresar', ['bad' => INF]);
    }

    public function testContentTypeForFormatFallsBackToJsonForUnknownFormat(): void
    {
        $encoder = new PayloadEncoder();

        self::assertSame('application/xml', $encoder->contentTypeForFormat(PayloadEncoder::FORMAT_XML));
        self::assertSame('application/json', $encoder->contentTypeForFormat(PayloadEncoder::FORMAT_JSON));
        self::assertSame('application/json', $encoder->contentTypeForFormat('unknown'));
    }
}

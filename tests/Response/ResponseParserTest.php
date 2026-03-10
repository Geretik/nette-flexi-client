<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Response;

use Acme\AbraFlexi\Exception\ApiErrorException;
use Acme\AbraFlexi\Exception\ParseException;
use Acme\AbraFlexi\Response\ResponseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseParser::class)]
final class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ResponseParser();
    }

    public function testParsesJsonPayload(): void
    {
        $result = $this->parser->parse('{"winstrom":{"@version":"1.0","adresar":[{"id":"1"}]}}', 'application/json');

        self::assertSame(['@version' => '1.0', 'adresar' => [['id' => '1']]], $result);
    }

    public function testParsesXmlPayload(): void
    {
        $result = $this->parser->parse('<response><ok>true</ok><id>42</id></response>', 'application/xml');

        self::assertSame(['ok' => 'true', 'id' => '42'], $result);
    }

    public function testThrowsParseExceptionOnInvalidJson(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->parse('{"ok":', 'application/json');
    }

    public function testThrowsApiErrorExceptionForErrorsArray(): void
    {
        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('Validation failed');

        $this->parser->parse(
            '{"errors":[{"message":"Validation failed","code":"E_VALIDATION"}]}',
            'application/json',
        );
    }

    public function testThrowsApiErrorExceptionForErrorField(): void
    {
        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('Unauthorized');

        $this->parser->parse('{"error":"Unauthorized","code":"AUTH_401"}', 'application/json');
    }

    public function testThrowsApiErrorExceptionForWrappedWinstromFailure(): void
    {
        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('Request failed');

        $this->parser->parse(
            '{"winstrom":{"success":"false","message":"Request failed","message@messageCode":"ERR"}}',
            'application/json',
        );
    }

    public function testThrowsApiErrorExceptionForNestedResultValidationErrors(): void
    {
        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('Pole je neplatne.');

        $this->parser->parse(
            '{"winstrom":{"success":"false","results":[{"errors":[{"message":"Pole je neplatne.","code":"INVALID","messageCode":"validace"}]}]}}',
            'application/json',
        );
    }

    public function testThrowsParseExceptionForHtmlResponse(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->parse('<html><body>Login</body></html>', 'text/html');
    }
}

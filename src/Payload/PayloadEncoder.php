<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Payload;

use Acme\AbraFlexi\Exception\ParseException;
use JsonException;

/**
 * Zabaluje data odeslana do Abra Flexi API do spravneho tvaru HTTP body.
 *
 * Flexi ocekava bud JSON dokument s korenem `{"winstrom": {"<agenda>": ...}}`,
 * nebo kompletni XML dokument `<winstrom>...</winstrom>`. Tato trida resi
 * tri scenare:
 *
 * 1. Pole bez kliсе `winstrom` -> obali se a serializuje do JSON.
 * 2. Pole s klicem `winstrom` -> serializuje se beze zmeny (volajici uz vi).
 * 3. String -> bere se jako predpripraveny payload, format se odhadne podle
 *    prvniho znaku (`<` => XML, jinak JSON).
 *
 * Pro PUT/POST tedy aplikace nemusi znat strukturu Flexi dokumentu;
 * stejne tak ale muze poslat surovy XML, kdyz potrebuje plnou kontrolu.
 */
final readonly class PayloadEncoder
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_XML = 'xml';

    /**
     * Vysledek kodovani: pripraveny `body`, jeho `Content-Type` a format
     * endpointu (`json`/`xml`), ktery klient pouzije pri sestaveni URL.
     *
     * @param array<mixed>|string $payload
     * @return array{
     *     format: string,
     *     contentType: string,
     *     body: string
     * }
     * @throws ParseException Pokud nelze payload zakodovat (napr. NaN/INF v JSON,
     *                        prazdna agenda).
     */
    public function encode(string $agenda, array|string $payload): array
    {
        if (is_string($payload)) {
            $format = $this->detectStringFormat($payload);

            return [
                'format' => $format,
                'contentType' => $this->contentTypeForFormat($format),
                'body' => $payload,
            ];
        }

        try {
            $encoded = json_encode($this->wrapForFlexi($agenda, $payload), JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParseException('Request payload could not be encoded to JSON.', $exception);
        }

        return [
            'format' => self::FORMAT_JSON,
            'contentType' => 'application/json',
            'body' => $encoded,
        ];
    }

    /**
     * Vrati Content-Type hlavicku pro dany format.
     */
    public function contentTypeForFormat(string $format): string
    {
        return match ($format) {
            self::FORMAT_XML => 'application/xml',
            default => 'application/json',
        };
    }

    /**
     * Doplni payload do JSON struktury ocekavane Flexi API.
     *
     * Pokud je v payloadu uz koren `winstrom`, vrati se beze zmeny.
     *
     * @param array<mixed> $payload
     * @return array<mixed>
     * @throws ParseException Pokud nelze z agendy odvodit nazev korenoveho uzlu.
     */
    private function wrapForFlexi(string $agenda, array $payload): array
    {
        if (isset($payload['winstrom']) && is_array($payload['winstrom'])) {
            return $payload;
        }

        $segments = explode('/', trim($agenda, '/'));
        $rootNode = end($segments);
        if ($rootNode === '') {
            throw new ParseException('Agenda must not be empty when preparing request payload.');
        }

        return [
            'winstrom' => [
                $rootNode => $payload,
            ],
        ];
    }

    /**
     * Rozpozna format podle prvniho neprazdneho znaku payloadu.
     */
    private function detectStringFormat(string $payload): string
    {
        return str_starts_with(ltrim($payload), '<') ? self::FORMAT_XML : self::FORMAT_JSON;
    }
}

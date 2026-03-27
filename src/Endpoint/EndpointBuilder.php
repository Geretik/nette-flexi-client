<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Endpoint;

use Acme\AbraFlexi\Config\FlexiConfig;
use InvalidArgumentException;

final readonly class EndpointBuilder
{
    private const FORMAT_JSON = 'json';
    private const FORMAT_XML = 'xml';

    /**
     * Vytvoří builder endpointů pro Flexi API.
     *
     * @param FlexiConfig $config Konfigurace připojení.
     */
    public function __construct(
        private FlexiConfig $config,
    ) {
    }

    /**
     * Vytvoří URL adresu pro endpoint v kontextu zadané firmy.
     *
     * @param array<int, string> $segments Jednotlivé části endpointu.
     * @param array<string, scalar|null> $query Volitelné query parametry do URL.
     * @param string|null $format Volitelný formát výstupu.
     * @return string Výsledná URL adresa.
     */
    public function forCompany(array $segments = [], array $query = [], ?string $format = null): string
    {
        $path = implode('/', [
            'c',
            $this->encodeSegment($this->config->company),
            ...$this->encodeSegments($segments),
        ]);

        return $this->buildUrl($path, $query, $format);
    }

    /**
     * Vytvoří URL adresu pro konkrétní agendu Flexi API.
     *
     * @param string $agenda Název agendy / endpointu.
     * @param string|null $recordId Volitelné ID konkrétního záznamu.
     * @param array<string, scalar|null> $query Volitelné query parametry do URL.
     * @param string|null $format Volitelný formát výstupu.
     * @return string Výsledná URL adresa.
     * @throws InvalidArgumentException Pokud je agenda prázdná.
     */
    public function agenda(string $agenda, ?string $recordId = null, array $query = [], ?string $format = null): string
    {
        $normalizedAgenda = trim($agenda, '/');
        if ($normalizedAgenda === '') {
            throw new InvalidArgumentException('Agenda must not be empty.');
        }

        $segments = explode('/', $normalizedAgenda);
        if ($recordId !== null) {
            $segments[] = $recordId;
        }

        return $this->forCompany($segments, $query, $format);
    }

    /**
     * Zakóduje jednotlivé segmenty endpointu pro použití v URL.
     *
     * @param array<int, string> $segments Jednotlivé části endpointu.
     * @return array<int, string> Zakódované segmenty.
     * @throws InvalidArgumentException Pokud některý segment je prázdný.
     */
    private function encodeSegments(array $segments): array
    {
        $encoded = [];
        foreach ($segments as $segment) {
            $normalized = trim($segment);
            if ($normalized === '') {
                throw new InvalidArgumentException('Endpoint segment must not be empty.');
            }

            $encoded[] = $this->encodeSegment($normalized);
        }

        return $encoded;
    }

    /**
     * Zakóduje jeden segment endpointu pro použití v URL.
     *
     * @param string $segment Jedna část endpointu.
     * @return string Zakódovaný segment.
     */
    private function encodeSegment(string $segment): string
    {
        return rawurlencode(trim($segment));
    }

    /**
     * Sestaví finální URL adresu pro volání Flexi API.
     *
     * @param string $path Připravená cesta endpointu.
     * @param array<string, scalar|null> $query Volitelné query parametry do URL.
     * @param string|null $format Volitelný formát endpointu.
     * @return string Výsledná URL adresa.
     * @throws InvalidArgumentException Pokud je zadán nepodporovaný formát.
     */
    private function buildUrl(string $path, array $query, ?string $format): string
    {
        $baseUrl = rtrim($this->config->baseUrl, '/');
        $normalizedPath = $path;
        if ($format !== null) {
            $normalizedFormat = strtolower(trim($format));
            if (!in_array($normalizedFormat, [self::FORMAT_JSON, self::FORMAT_XML], true)) {
                throw new InvalidArgumentException(sprintf('Unsupported endpoint format "%s".', $format));
            }

            $normalizedPath .= '.' . $normalizedFormat;
        }

        if ($query === []) {
            return $baseUrl . '/' . $normalizedPath;
        }

        return $baseUrl . '/' . $normalizedPath . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}

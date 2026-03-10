<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Endpoint;

use Acme\AbraFlexi\Config\FlexiConfig;
use InvalidArgumentException;

final readonly class EndpointBuilder
{
    private const FORMAT_JSON = 'json';
    private const FORMAT_XML = 'xml';

    public function __construct(
        private FlexiConfig $config,
    ) {
    }

    /**
     * @param array<int, string> $segments
     * @param array<string, scalar|null> $query
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
     * @param array<string, scalar|null> $query
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
     * @param array<int, string> $segments
     * @return array<int, string>
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

    private function encodeSegment(string $segment): string
    {
        return rawurlencode(trim($segment));
    }

    /**
     * @param array<string, scalar|null> $query
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

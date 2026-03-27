<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Response;

use Acme\AbraFlexi\Exception\ApiErrorException;
use Acme\AbraFlexi\Exception\ParseException;
use JsonException;

/**
 * Parsuje HTTP odpovedi z Abra Flexi API do sjednoceneho pole.
 */
final class ResponseParser
{
    /**
     * Parsuje telo odpovedi (JSON/XML), odhali API chyby a vrati payload.
     *
     * @param string $body Telo HTTP odpovedi.
     * @param string|null $contentType Hodnota Content-Type hlavicky, pokud je k dispozici.
     * @return array<mixed>
     * @throws ParseException
     * @throws ApiErrorException
     */
    public function parse(string $body, ?string $contentType = null): array
    {
        $trimmedBody = trim($body);
        if ($trimmedBody === '') {
            return [];
        }

        $payload = match (true) {
            $this->isJson($trimmedBody, $contentType) => $this->parseJson($trimmedBody),
            $this->isXml($trimmedBody, $contentType) => $this->parseXml($trimmedBody),
            default => throw new ParseException('Unsupported response format. Expected JSON or XML.'),
        };

        $documentPayload = $this->unwrapDocumentPayload($payload);
        $this->assertNoApiError($documentPayload);

        return $documentPayload;
    }

    /**
     * Urci, zda je odpoved pravdepodobne JSON.
     *
     * @param string $body Telo odpovedi.
     * @param string|null $contentType Hodnota Content-Type hlavicky, pokud je k dispozici.
     * @return bool `true`, pokud odpoved vypada jako JSON.
     */
    private function isJson(string $body, ?string $contentType): bool
    {
        if ($contentType !== null && str_contains(strtolower($contentType), 'json')) {
            return true;
        }

        return str_starts_with($body, '{') || str_starts_with($body, '[');
    }

    /**
     * Urci, zda je odpoved pravdepodobne XML.
     *
     * @param string $body Telo odpovedi.
     * @param string|null $contentType Hodnota Content-Type hlavicky, pokud je k dispozici.
     * @return bool `true`, pokud odpoved vypada jako XML.
     */
    private function isXml(string $body, ?string $contentType): bool
    {
        if ($contentType !== null) {
            return str_contains(strtolower($contentType), 'xml');
        }

        return str_starts_with($body, '<');
    }

    /**
     * Parsuje JSON odpoved a normalizuje ji na pole.
     *
     * @return array<mixed>
     * @throws ParseException
     */
    private function parseJson(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParseException('Invalid JSON response.', $exception);
        }

        return $this->normalizeToArray($decoded);
    }

    /**
     * Parsuje XML odpoved a normalizuje ji na pole.
     *
     * @return array<mixed>
     * @throws ParseException
     */
    private function parseXml(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, options: LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $error = $errors[0]->message ?? 'Unknown XML parsing error.';
            throw new ParseException(sprintf('Invalid XML response: %s', trim($error)));
        }

        try {
            $encoded = json_encode($xml, JSON_THROW_ON_ERROR);
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParseException('XML response could not be normalized.', $exception);
        }

        return $this->normalizeToArray($decoded);
    }

    /**
     * Normalizuje parsovanou hodnotu na pole.
     *
     * @param mixed $decoded
     * @return array<mixed>
     */
    private function normalizeToArray(mixed $decoded): array
    {
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['value' => $decoded];
    }

    /**
     * Rozbali korenovy uzel `winstrom`, pokud je pritomen.
     *
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private function unwrapDocumentPayload(array $payload): array
    {
        if (isset($payload['winstrom']) && is_array($payload['winstrom'])) {
            return $payload['winstrom'];
        }

        return $payload;
    }

    /**
     * Overi, ze payload neobsahuje API chybu.
     *
     * @param array<mixed> $payload
     * @throws ApiErrorException
     */
    private function assertNoApiError(array $payload): void
    {
        if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            $firstError = $payload['errors'][array_key_first($payload['errors'])];
            throw new ApiErrorException(
                message: $this->extractMessage($firstError) ?? 'API returned one or more errors.',
                errorCode: $this->extractCode($firstError),
                details: ['errors' => $payload['errors']],
            );
        }

        if (isset($payload['error'])) {
            $error = $payload['error'];
            if (is_string($error)) {
                throw new ApiErrorException(
                    message: $error,
                    errorCode: is_scalar($payload['code'] ?? null) ? (string) $payload['code'] : null,
                    details: $this->normalizeAssoc($payload),
                );
            }

            if (is_array($error)) {
                throw new ApiErrorException(
                    message: $this->extractMessage($error) ?? 'API returned an error.',
                    errorCode: $this->extractCode($error),
                    details: $this->normalizeAssoc($payload),
                );
            }
        }

        $nestedError = $this->findNestedResultError($payload);
        if ($nestedError !== null) {
            throw new ApiErrorException(
                message: $this->extractMessage($nestedError) ?? 'API returned an error.',
                errorCode: $this->extractCode($nestedError),
                details: $this->normalizeAssoc($payload),
            );
        }

        if ($this->isErrorFlag($payload['success'] ?? null)) {
            throw new ApiErrorException(
                message: $this->extractMessage($payload) ?? 'API request was not successful.',
                errorCode: $this->extractCode($payload),
                details: $this->normalizeAssoc($payload),
            );
        }
    }

    /**
     * Vyhodnoti ruzne reprezentace neuspechu (`false`, `0`, `no`).
     *
     * @param mixed $value Hodnota priznaku uspechu/neuspechu.
     * @return bool `true`, pokud hodnota reprezentuje neuspech.
     */
    private function isErrorFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value === false;
        }

        if (is_scalar($value)) {
            return in_array(strtolower((string) $value), ['0', 'false', 'no'], true);
        }

        return false;
    }

    /**
     * Hleda chybu vnorene uvnitr pole `results`.
     *
     * @param array<mixed> $payload
     * @return array<mixed>|null
     */
    private function findNestedResultError(array $payload): ?array
    {
        if (!isset($payload['results']) || !is_array($payload['results'])) {
            return null;
        }

        foreach ($payload['results'] as $result) {
            if (!is_array($result)) {
                continue;
            }

            if (isset($result['errors']) && is_array($result['errors']) && $result['errors'] !== []) {
                $firstError = $result['errors'][array_key_first($result['errors'])];
                if (is_array($firstError)) {
                    return $firstError;
                }
            }
        }

        return null;
    }

    /**
     * Pokusi se z chybove struktury vycist text chyby.
     *
     * @param mixed $error
     */
    private function extractMessage(mixed $error): ?string
    {
        if (is_string($error)) {
            return $error;
        }

        if (!is_array($error)) {
            return null;
        }

        foreach (['message', 'error', 'detail', 'description'] as $key) {
            if (is_string($error[$key] ?? null) && $error[$key] !== '') {
                return $error[$key];
            }
        }

        if (is_string($error[0] ?? null) && $error[0] !== '') {
            return $error[0];
        }

        if (isset($error['@attributes']) && is_array($error['@attributes'])) {
            foreach (['message', 'detail', 'description'] as $key) {
                if (is_string($error['@attributes'][$key] ?? null) && $error['@attributes'][$key] !== '') {
                    return $error['@attributes'][$key];
                }
            }
        }

        return null;
    }

    /**
     * Pokusi se z chybove struktury vycist kod chyby.
     *
     * @param mixed $error
     */
    private function extractCode(mixed $error): ?string
    {
        if (!is_array($error)) {
            return null;
        }

        foreach (['code', 'errorCode', 'type', 'messageCode'] as $key) {
            if (is_scalar($error[$key] ?? null)) {
                return (string) $error[$key];
            }
        }

        if (is_scalar($error['message@messageCode'] ?? null)) {
            return (string) $error['message@messageCode'];
        }

        if (isset($error['@attributes']) && is_array($error['@attributes'])) {
            foreach (['code', 'errorCode', 'type', 'messageCode'] as $key) {
                if (is_scalar($error['@attributes'][$key] ?? null)) {
                    return (string) $error['@attributes'][$key];
                }
            }
        }

        return null;
    }

    /**
     * Z payloadu ponecha pouze polozky s textovym klicem.
     *
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeAssoc(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}

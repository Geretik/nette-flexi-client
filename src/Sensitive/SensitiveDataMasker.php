<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Sensitive;

/**
 * Detekuje a maskuje citlive hodnoty v datech urcenych pro logovani.
 *
 * Trida resi pouze rozhodnuti "tento klic vypada citlive" a rekurzivni
 * pruchod polem - zameruje se na strukturovane kontexty (logger context,
 * Guzzle options, dekodovany JSON payload). Maskovani volnych formatu
 * (URL, surovy JSON/XML string) si resi vrstvy, kde k tomu maji kontext.
 */
final class SensitiveDataMasker
{
    public const MASK = '***';

    /** @var list<string> */
    private const EXACT_KEYS = [
        'password',
        'passwd',
        'authorization',
        'proxy-authorization',
        'token',
        'api_key',
        'apikey',
        'api-key',
        'secret',
        'cookie',
        'set-cookie',
    ];

    /** @var list<string> */
    private const SUBSTRINGS = [
        'token',
        'secret',
        'authorization',
        'cookie',
    ];

    /**
     * Urci, zda nazev klice (case-insensitive) odpovida citlivemu udaji.
     *
     * Kontroluje se shoda na presne nazvy ze seznamu i vyskyt podretezce
     * (napr. `access_token`, `client_secret`, `Set-Cookie`).
     */
    public function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        if (in_array($normalized, self::EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::SUBSTRINGS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Urci citlivost klice slozeneho z vice segmentu (`auth[password]`,
     * `data.user.token`, `payload[0]`). Citlivy je, pokud je citlivy
     * libovolny segment - nelogovat tedy nic v podstrome citlive cesty.
     */
    public function isSensitivePath(string $key): bool
    {
        $segments = preg_split('/[\[\].]+/', strtolower($key), -1, PREG_SPLIT_NO_EMPTY);
        if ($segments === false || $segments === []) {
            return $this->isSensitiveKey($key);
        }

        foreach ($segments as $segment) {
            if ($this->isSensitiveKey($segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rekurzivne zamaskuje hodnoty, jejichz klic je povazovan za citlivy.
     *
     * - skalarni hodnota se zamaskuje pouze pokud byl predan citlivy `$key`,
     * - pole se prochazi rekurzivne; klic se posune do dalsiho zanoreni,
     * - ne-stringove klice (cisla v listu) citlivost neaktivuji.
     */
    public function mask(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::MASK;
        }

        if (!is_array($value)) {
            return $value;
        }

        $masked = [];
        foreach ($value as $nestedKey => $nestedValue) {
            $masked[$nestedKey] = $this->mask(
                $nestedValue,
                is_string($nestedKey) ? $nestedKey : null,
            );
        }

        return $masked;
    }
}

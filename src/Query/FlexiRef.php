<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Query;

/**
 * Pomocna trida pro sestaveni referencnich identifikatoru Abra Flexi API.
 *
 * Flexi podporuje tri typy referenci na zaznamy:
 * - `code:<hodnota>` – odkaz pres uzivatelsky kod zaznamu,
 * - `ext:<ns>:<id>`  – odkaz pres externe pridelene ID,
 * - `id:<cislo>`     – odkaz pres interni databazove ID.
 *
 * @example
 *   $client->get('faktura-vydana', FlexiRef::code('FAK-2024-001'));
 *   $client->get('adresar', FlexiRef::ext('myapp', '42'));
 *   $client->get('adresar', FlexiRef::id('123'));
 */
final class FlexiRef
{
    private function __construct()
    {
    }

    /**
     * Vytvori referenci na zaznam podle uzivatelskeho kodu.
     *
     * @param string $code Uzivatelsky kod zaznamu (pole `kod`).
     * @return string Referencni identifikator ve tvaru `code:<code>`.
     */
    public static function code(string $code): string
    {
        return 'code:' . $code;
    }

    /**
     * Vytvori referenci na zaznam podle externiho ID.
     *
     * @param string $namespace Jmenny prostor externiho systemu.
     * @param string $id        Identifikator zaznamu v externim systemu.
     * @return string Referencni identifikator ve tvaru `ext:<namespace>:<id>`.
     */
    public static function ext(string $namespace, string $id): string
    {
        return 'ext:' . $namespace . ':' . $id;
    }

    /**
     * Vytvori referenci na zaznam podle interniho databazoveho ID.
     *
     * @param string $id Interni ciselne ID zaznamu.
     * @return string Referencni identifikator ve tvaru `id:<id>`.
     */
    public static function id(string $id): string
    {
        return 'id:' . $id;
    }
}

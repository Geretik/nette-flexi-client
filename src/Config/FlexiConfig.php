<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Config;

use InvalidArgumentException;

final readonly class FlexiConfig
{
    /**
     * Vytvoří novou instanci konfigurace pro připojení k Flexi API.
     *
     * Konstruktor nastaví všechny potřebné údaje pro připojení:
     * - základní URL adresu API,
     * - název firmy,
     * - přihlašovací jméno,
     * - heslo,
     * - timeout pro HTTP komunikaci.
     *
     * Po vytvoření instance se hned zavolá validace,
     * aby bylo jisté, že konfigurace obsahuje použitelné hodnoty.
     *
     * @param string $baseUrl Základní URL adresa Flexi API.
     * @param string $company Název firmy, proti které se bude API volat.
     * @param string $username Uživatelské jméno pro přihlášení do API.
     * @param string $password Heslo pro přihlášení do API.
     * @param float $timeout Časový limit pro HTTP požadavky v sekundách.
     */
    public function __construct(
        public string $baseUrl,
        public string $company,
        public string $username,
        public string $password,
        public float  $timeout = 10.0,
    )
    {
        $this->assertValid();
    }

    /**
     * Vytvoří instanci FlexiConfig z konfiguračního pole.
     *
     * Metoda slouží pro pohodlné vytvoření konfigurace například
     * z NEON, PHP pole nebo jiné externí konfigurace.
     *
     * Pokud položka 'timeout' není zadána,
     * použije se výchozí hodnota 10.0 sekundy.
     *
     * @param array{
     *     baseUrl: string,
     *     company: string,
     *     username: string,
     *     password: string,
     *     timeout?: float|int
     * } $data Konfigurační data pro vytvoření instance.
     * @return self Nová instance konfigurace.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            baseUrl: $data['baseUrl'],
            company: $data['company'],
            username: $data['username'],
            password: $data['password'],
            timeout: isset($data['timeout']) ? (float)$data['timeout'] : 10.0,
        );
    }

    /**
     * Overi pouze ty parametry, ktere jsou spolecne pro vsechna pripojeni
     * (baseUrl, username, password, timeout) - tedy bez vazby na konkretni firmu.
     *
     * Tato metoda existuje primarne kvuli scenari, kdy mame v NEONu jednu
     * spolecnou "base" konfiguraci a vedle ni vice pojmenovanych pripojeni.
     * Kazde pripojeni si pak doplni vlastni `company`, ale spolecne vychozi
     * hodnoty se daji validovat uz pri kompilaci DI kontejneru.
     *
     * @param array{
     *     baseUrl: string,
     *     username: string,
     *     password: string,
     *     timeout?: float|int
     * } $data
     * @throws InvalidArgumentException Pokud nektera hodnota neni platna.
     */
    public static function assertConnectionDefaults(array $data): void
    {
        self::assertBaseUrl($data['baseUrl']);

        if ($data['username'] === '') {
            throw new InvalidArgumentException('Username must not be empty.');
        }

        if ($data['password'] === '') {
            throw new InvalidArgumentException('Password must not be empty.');
        }

        $timeout = isset($data['timeout']) ? (float) $data['timeout'] : 10.0;
        if ($timeout <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than zero.');
        }
    }

    /**
     * Vrátí konfiguraci jako pole se zamaskovaným heslem.
     *
     * @return array{
     *     baseUrl: string,
     *     company: string,
     *     username: string,
     *     password: string,
     *     timeout: float
     * } Konfigurační data vhodná například pro logování nebo debug.
     */
    public function withPasswordMasked(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'company' => $this->company,
            'username' => $this->username,
            'password' => '***',
            'timeout' => $this->timeout,
        ];
    }

    /**
     * Ověří platnost konfiguračních hodnot.
     *
     * @throws InvalidArgumentException Pokud některá konfigurační hodnota není platná.
     */
    private function assertValid(): void
    {
        self::assertBaseUrl($this->baseUrl);

        if ($this->company === '') {
            throw new InvalidArgumentException('Company must not be empty.');
        }

        if ($this->username === '') {
            throw new InvalidArgumentException('Username must not be empty.');
        }

        if ($this->password === '') {
            throw new InvalidArgumentException('Password must not be empty.');
        }

        if ($this->timeout <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than zero.');
        }
    }

    /**
     * Overi, ze baseUrl je absolutni URL s povolenym schematem (http/https).
     *
     * @throws InvalidArgumentException Pokud URL chybi, je nevalidni nebo pouziva
     *                                  jine schema nez http(s).
     */
    private static function assertBaseUrl(string $baseUrl): void
    {
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Invalid baseUrl. A valid absolute URL is required.');
        }

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid baseUrl scheme "%s". Only http and https are supported.',
                $scheme,
            ));
        }
    }
}

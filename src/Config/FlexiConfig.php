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
        if ($this->baseUrl === '' || filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Invalid baseUrl. A valid absolute URL is required.');
        }

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
}

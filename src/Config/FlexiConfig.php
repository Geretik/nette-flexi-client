<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Config;

use InvalidArgumentException;

final readonly class FlexiConfig
{
    public function __construct(
        public string $baseUrl,
        public string $company,
        public string $username,
        public string $password,
        public float $timeout = 10.0,
    ) {
        $this->assertValid();
    }

    /**
     * @param array{
     *     baseUrl: string,
     *     company: string,
     *     username: string,
     *     password: string,
     *     timeout?: float|int
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            baseUrl: $data['baseUrl'],
            company: $data['company'],
            username: $data['username'],
            password: $data['password'],
            timeout: isset($data['timeout']) ? (float) $data['timeout'] : 10.0,
        );
    }

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

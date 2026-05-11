<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Query;

use Acme\AbraFlexi\Client\FlexiClient;
use Generator;

/**
 * Fluent builder pro sestaveni GET/POST/PUT/DELETE pozadavku na Flexi API.
 *
 * Kazde volani modifikujici stav vraci novou (klonovano) instanci,
 * takze builder je bezpecne sdilet a znovu pouzit.
 *
 * @example
 *   $invoices = $client->query('faktura-vydana')
 *       ->where("(stav='uhrazena')")
 *       ->orderByDesc('datVyst')
 *       ->limit(50)
 *       ->detail('full')
 *       ->get();
 */
final class FlexiQuery
{
    /** @var list<string> */
    private array $filterParts = [];

    private ?int $limit = null;

    private ?int $start = null;

    /** @var list<string> */
    private array $orderParts = [];

    private ?string $detail = null;

    /** @var list<string> */
    private array $includes = [];

    /** @var array<string, scalar|null> */
    private array $extra = [];

    public function __construct(
        private readonly FlexiClient $client,
        private readonly string $agenda,
    ) {
    }

    /**
     * Prida podminku filtru ve Flexi QL.
     *
     * Vice volani se spoji operatorem `and`.
     *
     * @param string $expression Vyraz ve Flexi QL (napr. `(stav='uhrazena')`).
     */
    public function where(string $expression): static
    {
        $clone = clone $this;
        $clone->filterParts[] = $expression;

        return $clone;
    }

    /**
     * Omezi pocet vracenych zaznamu.
     */
    public function limit(int $limit): static
    {
        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    /**
     * Nastavi offset (parametr `start`) pro strankovani.
     */
    public function offset(int $start): static
    {
        $clone = clone $this;
        $clone->start = $start;

        return $clone;
    }

    /**
     * Prida razeni podle zadaneho pole.
     *
     * @param string $field     Nazev pole (napr. `datVyst`).
     * @param string $direction `asc` nebo `desc` (case-insensitive).
     */
    public function orderBy(string $field, string $direction = 'asc'): static
    {
        $clone = clone $this;
        $clone->orderParts[] = $field . (strtolower($direction) === 'desc' ? '@D' : '@A');

        return $clone;
    }

    /**
     * Prida razeni vzestupne.
     */
    public function orderByAsc(string $field): static
    {
        return $this->orderBy($field, 'asc');
    }

    /**
     * Prida razeni sestupne.
     */
    public function orderByDesc(string $field): static
    {
        return $this->orderBy($field, 'desc');
    }

    /**
     * Nastavi detail odpovedi (`full`, `summary`, `id`, …).
     */
    public function detail(string $detail): static
    {
        $clone = clone $this;
        $clone->detail = $detail;

        return $clone;
    }

    /**
     * Prida pole do `includes` (soucasty odpovedi).
     */
    public function includes(string ...$fields): static
    {
        $clone = clone $this;
        array_push($clone->includes, ...$fields);

        return $clone;
    }

    /**
     * Prida libovolny vlastni query parametr.
     */
    public function with(string $key, int|float|string|bool|null $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;

        return $clone;
    }

    /**
     * Provede GET dotaz.
     *
     * @param string|null $recordId Volitelne ID zaznamu.
     * @return array<mixed>
     */
    public function get(?string $recordId = null): array
    {
        return $this->client->get($this->agenda, $recordId, $this->buildQuery());
    }

    /**
     * Provede POST dotaz.
     *
     * @param array<mixed>|string $payload
     * @return array<mixed>
     */
    public function post(array|string $payload): array
    {
        return $this->client->post($this->agenda, $payload, $this->buildQuery());
    }

    /**
     * Provede PUT dotaz.
     *
     * @param array<mixed>|string $payload
     * @return array<mixed>
     */
    public function put(string $recordId, array|string $payload): array
    {
        return $this->client->put($this->agenda, $recordId, $payload, $this->buildQuery());
    }

    /**
     * Provede DELETE dotaz.
     *
     * @return array<mixed>
     */
    public function delete(string $recordId): array
    {
        return $this->client->delete($this->agenda, $recordId, $this->buildQuery());
    }

    /**
     * Postupne prochazi vsechny stranky vysledku a yield-uje jednotlive zaznamy.
     *
     * Strankovani se zastavi, jakmile stranka obsahuje mene zaznamu nez $pageSize.
     *
     * @return Generator<int, array<mixed>>
     */
    public function paginate(int $pageSize = 100): Generator
    {
        return $this->client->paginate($this->agenda, $this->buildQuery(), $pageSize);
    }

    /**
     * Sestavi pole query parametru pro FlexiClient.
     *
     * @return array<string, scalar|null>
     */
    private function buildQuery(): array
    {
        $query = $this->extra;

        if ($this->filterParts !== []) {
            $query['filter'] = implode(' and ', $this->filterParts);
        }

        if ($this->limit !== null) {
            $query['limit'] = $this->limit;
        }

        if ($this->start !== null) {
            $query['start'] = $this->start;
        }

        if ($this->orderParts !== []) {
            $query['order'] = implode(',', $this->orderParts);
        }

        if ($this->detail !== null) {
            $query['detail'] = $this->detail;
        }

        if ($this->includes !== []) {
            $query['includes'] = implode(',', $this->includes);
        }

        return $query;
    }
}

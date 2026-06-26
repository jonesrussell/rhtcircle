<?php

declare(strict_types=1);

namespace App\Tests\Support\Lexicon;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;

/**
 * An in-memory {@see HttpClientInterface} for testing the LexiconClient without
 * a network. It returns a canned response (or throws a canned error to simulate
 * Minoo being unreachable) and counts calls so a test can assert the cache
 * short-circuited a second lookup.
 */
final class FakeHttpClient implements HttpClientInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $urls = [];

    public function __construct(
        private readonly ?HttpResponse $response = null,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        ++$this->calls;
        $this->urls[] = $url;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->response ?? new HttpResponse(200, '{}');
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }
}

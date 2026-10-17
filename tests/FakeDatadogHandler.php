<?php

declare(strict_types=1);

namespace MonologDatadog\Tests;

use MonologDatadog\Handler\DatadogHandler;
use Throwable;

/**
 * DatadogHandler with the HTTP transport replaced, to inspect the requests it would send.
 */
final class FakeDatadogHandler extends DatadogHandler
{
    /** @var list<array{url: string, headers: list<string>, body: string}> */
    public array $requests = [];

    /** @var array{0: int, 1: string, 2: string|null}|Throwable */
    public array|Throwable $response = [202, '{}', null];

    /**
     * Entries sent in the last request.
     *
     * @return list<array<string, mixed>>
     */
    public function lastEntries(): array
    {
        $request = end($this->requests);

        return json_decode($request['body'], true);
    }

    /**
     * Calls the real cURL transport.
     *
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string, 2: string|null}
     */
    public function realPost(string $url, array $headers, string $body): array
    {
        return parent::post($url, $headers, $body);
    }

    protected function post(string $url, array $headers, string $body): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response;
    }
}

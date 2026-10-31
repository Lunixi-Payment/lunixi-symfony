<?php
declare(strict_types=1);
namespace Lunixi\Symfony\Tests;

use Lunixi\Sdk\Http\HttpClientInterface;
use Lunixi\Sdk\Http\HttpResponse;

final class FixtureHttpClient implements HttpClientInterface
{
    /** @var list<HttpResponse|\Throwable> */
    private array $responses = [];
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    public function respond(array $body): void { $this->responses[] = new HttpResponse(200, [], json_encode($body, JSON_THROW_ON_ERROR)); }
    public function fail(): void { $this->responses[] = new \RuntimeException('Synthetic lost network response'); }
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) { throw $next; }
        if (!$next instanceof HttpResponse) { throw new \RuntimeException('Unexpected gateway request in offline integration test.'); }
        return $next;
    }
}

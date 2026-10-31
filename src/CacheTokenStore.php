<?php
declare(strict_types=1);
namespace Lunixi\Symfony;

use Lunixi\Sdk\Auth\TokenStoreInterface;
use Psr\Cache\CacheItemPoolInterface;

/** Use a private server-side cache pool; never a public HTTP cache. */
final class CacheTokenStore implements TokenStoreInterface
{
    public function __construct(private readonly CacheItemPoolInterface $pool) {}
    public function get(string $key): ?string
    {
        $item = $this->pool->getItem('lunixi.' . hash('sha256', $key));
        return $item->isHit() && is_string($item->get()) ? $item->get() : null;
    }
    public function set(string $key, string $token, int $ttlSeconds): void
    {
        if ($token === '' || $ttlSeconds < 1) { return; }
        $item = $this->pool->getItem('lunixi.' . hash('sha256', $key));
        $item->set($token)->expiresAfter($ttlSeconds);
        if (!$this->pool->save($item)) { throw new \RuntimeException('Lunixi token cache write failed.'); }
    }
    public function delete(string $key): void { $this->pool->deleteItem('lunixi.' . hash('sha256', $key)); }
}

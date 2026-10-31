<?php
declare(strict_types=1);
namespace Lunixi\Symfony;

use Lunixi\Sdk\Auth\TokenStoreInterface;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Http\HttpClientInterface;

final class ClientFactory
{
    /** @param array<string,mixed> $config */
    public static function create(array $config, TokenStoreInterface $tokens, ?HttpClientInterface $http = null): LunixiClient
    {
        $origin = $config['base_url'];
        $url = parse_url($origin);
        if (!in_array($origin, $config['allowed_origins'], true) || ($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || (isset($url['path']) && $url['path'] !== '' && $url['path'] !== '/')) {
            throw new \InvalidArgumentException('Lunixi base_url must be an operator-approved HTTPS origin.');
        }
        return LunixiClient::create(['baseUrl' => $origin, 'keyId' => $config['key_id'], 'privateKey' => $config['private_key'], 'timeout' => $config['timeout']], $http, $tokens);
    }
}

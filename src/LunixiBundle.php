<?php
declare(strict_types=1);
namespace Lunixi\Symfony;

use Lunixi\Sdk\Auth\TokenStoreInterface;
use Lunixi\Sdk\Commerce\CheckoutService;
use Lunixi\Sdk\Commerce\LedgerStore;
use Lunixi\Sdk\Commerce\SecretBox;
use Lunixi\Sdk\LunixiClient;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class LunixiBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('base_url')->defaultValue('https://api-gateway.lunixi.com')->end()
            ->arrayNode('allowed_origins')->scalarPrototype()->end()->defaultValue(['https://api-gateway.lunixi.com'])->end()
            ->scalarNode('key_id')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('private_key')->isRequired()->cannotBeEmpty()->end()
            ->integerNode('timeout')->min(1)->max(120)->defaultValue(30)->end()
            ->scalarNode('cache_pool')->defaultValue('cache.app')->end()
            ->scalarNode('http_client_service')->defaultNull()->end()
            ->booleanNode('commerce_enabled')->defaultFalse()->end()
            ->scalarNode('connection_service')->defaultValue('doctrine.dbal.default_connection')->end()
            ->scalarNode('table_prefix')->defaultValue('lunixi_')->end()
            ->scalarNode('merchant_id')->defaultValue('')->end()
            ->scalarNode('webhook_secret')->defaultValue('')->end()
            ->scalarNode('storage_key')->defaultValue('')->end()
        ->end();
    }
    /** @param array<string,mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->register(CacheTokenStore::class)->setArguments([new Reference($config['cache_pool'])]);
        $builder->setAlias(TokenStoreInterface::class, CacheTokenStore::class);
        $clientConfig = array_intersect_key($config, array_flip(['base_url', 'allowed_origins', 'key_id', 'private_key', 'timeout']));
        $http = $config['http_client_service'] === null ? null : new Reference($config['http_client_service']);
        $builder->register(LunixiClient::class)->setFactory([ClientFactory::class, 'create'])->setArguments([$clientConfig, new Reference(TokenStoreInterface::class), $http]);
        $builder->setAlias('lunixi.client', LunixiClient::class);
        if ($config['commerce_enabled']) {
            $builder->register(DoctrineLedgerStore::class)->setArguments([new Reference($config['connection_service']), $config['table_prefix']]);
            $builder->setAlias(LedgerStore::class, DoctrineLedgerStore::class);
            $builder->register(SecretBox::class)->setArguments([$config['storage_key']]);
            $builder->register(CheckoutService::class)->setArguments([new Reference(LunixiClient::class), new Reference(LedgerStore::class), new Reference(SecretBox::class)]);
            $builder->register(MerchantContext::class)->setArguments([$config['merchant_id'], $config['webhook_secret']]);
            $builder->register(InstallLedgerCommand::class)->setArguments([new Reference(DoctrineLedgerStore::class)])->addTag('console.command', ['command' => 'lunixi:ledger:install']);
        }
    }
}

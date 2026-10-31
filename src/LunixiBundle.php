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
    private static function arrayRoot(object $node): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        if (!$node instanceof \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition) { throw new \LogicException('Lunixi configuration requires an array root.'); }
        return $node;
    }
    public function configure(DefinitionConfigurator $definition): void
    {
        $root = self::arrayRoot($definition->rootNode());
        $children = $root->children();
        $children->scalarNode('base_url')->defaultValue('https://api-gateway.lunixi.com');
        $origins = $children->arrayNode('allowed_origins');
        $origins->scalarPrototype();
        $origins->defaultValue(['https://api-gateway.lunixi.com']);
        $children->scalarNode('key_id')->isRequired()->cannotBeEmpty();
        $children->scalarNode('private_key')->isRequired()->cannotBeEmpty();
        $children->integerNode('timeout')->min(1)->max(120)->defaultValue(30);
        $children->scalarNode('cache_pool')->defaultValue('cache.app');
        $children->scalarNode('http_client_service')->defaultNull();
        $children->booleanNode('commerce_enabled')->defaultFalse();
        $children->scalarNode('connection_service')->defaultValue('doctrine.dbal.default_connection');
        $children->scalarNode('table_prefix')->defaultValue('lunixi_');
        $children->scalarNode('merchant_id')->defaultValue('');
        $children->scalarNode('webhook_secret')->defaultValue('');
        $children->scalarNode('storage_key')->defaultValue('');
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

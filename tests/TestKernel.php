<?php
declare(strict_types=1);
namespace Lunixi\Symfony\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\TokenStoreInterface;
use Lunixi\Sdk\Commerce\CheckoutService;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Symfony\DoctrineLedgerStore;
use Lunixi\Symfony\LunixiBundle;
use Lunixi\Symfony\MerchantContext;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;
    private string $directory;
    public function __construct()
    {
        parent::__construct('test', true);
        $this->directory = sys_get_temp_dir() . '/lunixi-symfony-' . bin2hex(random_bytes(8));
    }
    public function getCacheDir(): string { return $this->directory . '/cache'; }
    public function getLogDir(): string { return $this->directory . '/logs'; }
    public function registerBundles(): iterable { yield new FrameworkBundle(); yield new LunixiBundle(); }
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'synthetic-nonproduction-framework-secret', 'test' => true, 'http_method_override' => false]);
        $keys = Ed25519Signer::generateKeyPair();
        $container->extension('lunixi', ['base_url' => 'https://gateway.example', 'allowed_origins' => ['https://gateway.example'], 'key_id' => 'synthetic-key', 'private_key' => $keys['privateKey'], 'http_client_service' => 'test.http', 'commerce_enabled' => true, 'connection_service' => 'test.connection', 'merchant_id' => 'merchant-1', 'webhook_secret' => 'synthetic-webhook-secret', 'storage_key' => str_repeat('x', 32)]);
        $services = $container->services();
        $services->set('test.connection', Connection::class)->factory([DriverManager::class, 'getConnection'])->args([['driver' => 'pdo_sqlite', 'memory' => true]])->public();
        $services->set('test.http', FixtureHttpClient::class)->public();
        foreach (['test.checkout' => CheckoutService::class, 'test.client' => LunixiClient::class, 'test.merchant' => MerchantContext::class, 'test.ledger' => DoctrineLedgerStore::class, 'test.tokens' => TokenStoreInterface::class] as $id => $class) { $services->alias($id, $class)->public(); }
    }
    protected function configureRoutes(RoutingConfigurator $routes): void {}
    public function testDirectory(): string { return $this->directory; }
}

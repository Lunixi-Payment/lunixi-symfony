<?php
declare(strict_types=1);
namespace Lunixi\Symfony\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Lunixi\Sdk\Auth\InMemoryTokenStore;
use Lunixi\Sdk\Commerce\CheckoutService;
use Lunixi\Sdk\Commerce\OrderSnapshot;
use Lunixi\Sdk\Exception\WebhookVerificationException;
use Lunixi\Symfony\CacheTokenStore;
use Lunixi\Symfony\ClientFactory;
use Lunixi\Symfony\DoctrineLedgerStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

final class BundleTest extends TestCase
{
    private ?TestKernel $kernel = null;
    protected function tearDown(): void
    {
        if ($this->kernel !== null) { $this->kernel->shutdown(); (new Filesystem())->remove($this->kernel->testDirectory()); }
    }
    private function boot(): array
    {
        $this->kernel = new TestKernel(); $this->kernel->boot();
        $c = $this->kernel->getContainer();
        $c->get('test.ledger')->install();
        $client = $c->get('test.client');
        $c->get('test.tokens')->set($client->config()->tokenCacheKey(), 'synthetic-bearer', 3600);
        return [$c->get('test.checkout'), $c->get('test.http'), $c->get('test.connection')];
    }
    public function testNativeKernelAutowiresSharedSdkAndDurableCheckout(): void
    {
        [$checkout, $http, $db] = $this->boot();
        self::assertInstanceOf(CheckoutService::class, $checkout);
        self::assertInstanceOf(Connection::class, $db);
        $http->respond(['data' => ['paymentId' => 'pi-1', 'token' => 'synthetic-token']]);
        $order = new OrderSnapshot('merchant-1', 'order-1', 1000, 'TRY');
        $one = $checkout->start('order-1', $order, 'https://shop.example/return', true);
        self::assertSame('https://pay.lunixi.com/payment/synthetic-token', $one->hostedUrl());
        self::assertSame('pi-1', $checkout->start('order-1', $order, 'https://shop.example/return', true)->paymentId);
        self::assertCount(1, $http->calls);
        self::assertStringNotContainsString('synthetic-token', $db->fetchOne('SELECT payload FROM lunixi_attempts'));
        $body = json_decode($http->calls[0]['body'], true);
        self::assertSame('https://pay.lunixi.com', $body['metadata']['allowedOrigin']);
    }
    public function testDbalTransactionRollsBackNativeOrderAndInboxTogether(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $store = new DoctrineLedgerStore($db); $store->install();
        $db->executeStatement('CREATE TABLE native_order (id INTEGER PRIMARY KEY, paid INTEGER NOT NULL)');
        $db->executeStatement('INSERT INTO native_order VALUES (1,0)');
        try {
            $store->transaction('1', function () use ($store, $db): void {
                $store->save('1', ['state' => 'paid']);
                $store->claimEvent('merchant-1', 'evt-1', hash('sha256', 'body'));
                $db->beginTransaction(); // Mirrors an ORM flush nested inside the adapter transaction.
                $db->executeStatement('UPDATE native_order SET paid = 1 WHERE id = 1'); $db->commit();
                throw new \RuntimeException('Synthetic native transition failure');
            });
            self::fail('Expected native write rollback.');
        } catch (\RuntimeException $expected) {}
        self::assertSame(0, (int) $db->fetchOne('SELECT paid FROM native_order WHERE id = 1'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM lunixi_events'));
        self::assertTrue($store->transaction('1', fn () => $store->claimEvent('merchant-1', 'evt-1', hash('sha256', 'body'))));
        self::assertFalse($store->transaction('1', fn () => $store->claimEvent('merchant-1', 'evt-1', hash('sha256', 'body'))));
        $this->expectException(\RuntimeException::class);
        $store->transaction('1', fn () => $store->claimEvent('merchant-1', 'evt-1', hash('sha256', 'changed')));
    }
    public function testSymfonyRequestRawBytesReachVerifierAndTamperingIsRejected(): void
    {
        [$checkout] = $this->boot();
        $merchant = $this->kernel->getContainer()->get('test.merchant');
        $body = '{"id":"evt-1","type":"payment.captured","data":{"paymentId":"pi-1"}}';
        $ts = gmdate('c');
        $server = ['HTTP_X_LUNIXI_EVENT_ID' => 'evt-1', 'HTTP_X_LUNIXI_SIGNATURE_TIMESTAMP' => $ts, 'HTTP_X_LUNIXI_SIGNATURE' => 'v2=' . hash_hmac('sha256', 'evt-1.' . $ts . '.' . $body, 'synthetic-webhook-secret')];
        $request = Request::create('/webhook', 'POST', [], [], [], $server, $body);
        self::assertSame('evt-1', $merchant->verifyWebhook($request, $checkout)->id());
        $bad = Request::create('/webhook', 'POST', [], [], [], $server, str_replace('pi-1', 'pi-other', $body));
        $this->expectException(WebhookVerificationException::class); $merchant->verifyWebhook($bad, $checkout);
    }
    public function testTokenPoolSurvivesServiceRecreationAndSeparatesMerchants(): void
    {
        $directory = sys_get_temp_dir() . '/lunixi-cache-' . bin2hex(random_bytes(8));
        try {
            $one = new CacheTokenStore(new FilesystemAdapter('lunixi', 0, $directory));
            $one->set('merchant-a', 'synthetic-bearer', 60);
            $two = new CacheTokenStore(new FilesystemAdapter('lunixi', 0, $directory));
            self::assertSame('synthetic-bearer', $two->get('merchant-a')); self::assertNull($two->get('merchant-b'));
            $two->delete('merchant-a'); self::assertNull($one->get('merchant-a'));
        } finally { (new Filesystem())->remove($directory); }
    }
    public function testUnapprovedCredentialDestinationIsRejectedBeforeTransport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientFactory::create(['base_url' => 'https://unapproved.example', 'allowed_origins' => ['https://api-gateway.lunixi.com']], new InMemoryTokenStore());
    }
    public function testNestedOuterTransactionCannotHideAnUncommittedAttempt(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $store = new DoctrineLedgerStore($db); $store->install(); $db->beginTransaction();
        try { $this->expectException(\RuntimeException::class); $store->transaction('1', static fn () => null); }
        finally { $db->rollBack(); }
    }
}

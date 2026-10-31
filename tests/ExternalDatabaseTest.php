<?php
declare(strict_types=1);
namespace Lunixi\Symfony\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Lunixi\Symfony\DoctrineLedgerStore;
use PHPUnit\Framework\TestCase;

/** Explicit disposable database only; no production application configuration is read. */
final class ExternalDatabaseTest extends TestCase
{
    private Connection $db;
    private string $prefix;
    protected function setUp(): void
    {
        $file = getenv('LUNIXI_TEST_DB_FILE');
        if (!$file || !is_file($file)) { self::markTestSkipped('Set LUNIXI_TEST_DB_FILE to a disposable integration database JSON file.'); }
        $config = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        $this->db = DriverManager::getConnection(self::parameters($config));
        $this->db->executeStatement('SET SESSION innodb_lock_wait_timeout = 10');
        $this->prefix = 'lx_test_' . bin2hex(random_bytes(4)) . '_';
        (new DoctrineLedgerStore($this->db, $this->prefix))->install();
        $this->db->executeStatement('CREATE TABLE ' . $this->prefix . 'native_order (id INTEGER PRIMARY KEY, applied INTEGER NOT NULL) ENGINE=InnoDB');
        $this->db->executeStatement('INSERT INTO ' . $this->prefix . 'native_order VALUES (1,0)');
    }
    protected function tearDown(): void
    {
        if (isset($this->db, $this->prefix)) {
            foreach (['events', 'attempts', 'native_order'] as $name) { $this->db->executeStatement('DROP TABLE IF EXISTS ' . $this->prefix . $name); }
            $this->db->close();
        }
    }
    /** @return array<string,mixed> */
    public static function parameters(array $config): array
    {
        $name = $config['database'];
        if (!is_string($name) || !str_starts_with($name, 'lunixi_')) { throw new \RuntimeException('An explicitly named disposable lunixi_ test database is required.'); }
        return ['driver' => 'pdo_mysql', 'host' => $config['host'], 'port' => $config['port'], 'user' => $config['username'], 'password' => $config['password'], 'dbname' => $name, 'charset' => 'utf8mb4'];
    }
    public function testMysqlNativeWritesAndWebhookClaimRollbackAcrossDbalNesting(): void
    {
        $store = new DoctrineLedgerStore($this->db, $this->prefix);
        try {
            $store->transaction('order-1', function () use ($store): void {
                $store->save('order-1', ['state' => 'paid']);
                $store->claimEvent('merchant', 'event', hash('sha256', 'body'));
                $this->db->beginTransaction();
                $this->db->executeStatement('UPDATE ' . $this->prefix . 'native_order SET applied = 1 WHERE id = 1');
                $this->db->commit();
                throw new \RuntimeException('Synthetic native persistence failure');
            });
            self::fail('Expected rollback.');
        } catch (\RuntimeException $expected) {}
        self::assertSame(0, (int) $this->db->fetchOne('SELECT applied FROM ' . $this->prefix . 'native_order WHERE id = 1'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->prefix . 'events'));
        self::assertTrue($store->transaction('order-1', fn () => $store->claimEvent('merchant', 'event', hash('sha256', 'body'))));
        self::assertFalse($store->transaction('order-1', fn () => $store->claimEvent('merchant', 'event', hash('sha256', 'body'))));
    }
    public function testMysqlOrderKeysAreCaseSensitiveAndSqlParametersRemainData(): void
    {
        $store = new DoctrineLedgerStore($this->db, $this->prefix);
        foreach (['Order-A', 'order-a', "quoted-' OR 1=1 --"] as $key) { $store->transaction($key, fn () => $store->save($key, ['key' => $key])); }
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->prefix . 'attempts'));
        self::assertSame('Order-A', $store->transaction('Order-A', fn () => $store->load('Order-A'))['key']);
        self::assertSame('order-a', $store->transaction('order-a', fn () => $store->load('order-a'))['key']);
    }
    public function testMysqlConcurrentProcessesApplyTheNativeOrderExactlyOnce(): void
    {
        $processes = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/mysql-worker.php', (string) getenv('LUNIXI_TEST_DB_FILE'), $this->prefix], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                stream_set_timeout($pipes[1], 15); stream_set_timeout($pipes[2], 15);
                $results[] = trim(stream_get_contents($pipes[1]));
                self::assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
            }
            sort($results); self::assertSame(['duplicate', 'processed'], $results);
            self::assertSame(1, (int) $this->db->fetchOne('SELECT applied FROM ' . $this->prefix . 'native_order WHERE id = 1'));
            self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->prefix . 'events'));
        } finally {
            foreach ($processes as [$process, $pipes]) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
        }
    }
}

<?php
declare(strict_types=1);
namespace Lunixi\Symfony;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Lunixi\Sdk\Commerce\LedgerStore;

/** Keeps DBAL/ORM transaction nesting aware of the shared order/inbox transaction. */
final class DoctrineLedgerStore implements LedgerStore
{
    private bool $active = false;
    private string $activeKey = '';
    private string $dialect;

    public function __construct(private readonly Connection $db, private readonly string $prefix = 'lunixi_')
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,45}$/D', $prefix)) { throw new \InvalidArgumentException('Invalid ledger table prefix.'); }
        $platform = $db->getDatabasePlatform();
        $this->dialect = $platform instanceof AbstractMySQLPlatform ? 'mysql' : ($platform instanceof PostgreSQLPlatform ? 'pgsql' : ($platform instanceof SQLitePlatform ? 'sqlite' : ''));
        if ($this->dialect === '') { throw new \InvalidArgumentException('Use MySQL/InnoDB, PostgreSQL or SQLite for the commerce ledger.'); }
        if ($this->dialect === 'sqlite') { $db->executeStatement('PRAGMA busy_timeout = 10000'); }
    }
    public function install(): void
    {
        if ($this->db->getTransactionNestingLevel() !== 0) { throw new \RuntimeException('Install the ledger outside a transaction.'); }
        $suffix = $this->dialect === 'mysql' ? ' ENGINE=InnoDB' : '';
        $key = $this->dialect === 'mysql' ? 'VARBINARY(191)' : 'VARCHAR(191)';
        $text = $this->dialect === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'attempts (order_key ' . $key . ' PRIMARY KEY, payload ' . $text . ' NULL)' . $suffix);
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'events (event_hash CHAR(64) PRIMARY KEY, payload_hash CHAR(64) NOT NULL, received_at BIGINT NOT NULL)' . $suffix);
    }
    public function transaction(string $orderKey, callable $operation)
    {
        if ($this->active || $this->db->getTransactionNestingLevel() !== 0 || $orderKey === '' || strlen($orderKey) > 191) { throw new \RuntimeException('Checkout must start outside an existing transaction with a valid order key.'); }
        $this->active = true; $this->activeKey = $orderKey;
        try {
            $this->db->beginTransaction();
            if ($this->dialect === 'mysql') {
                $this->db->executeStatement('INSERT INTO ' . $this->prefix . 'attempts (order_key,payload) VALUES (?,NULL) ON DUPLICATE KEY UPDATE order_key = VALUES(order_key)', [$orderKey]);
            } else { $this->insert('attempts', '(order_key,payload) VALUES (?,NULL)', [$orderKey], '(order_key)'); }
            if ($this->dialect !== 'sqlite') { $this->db->fetchOne('SELECT order_key FROM ' . $this->prefix . 'attempts WHERE order_key = ? FOR UPDATE', [$orderKey]); }
            $result = $operation(); $this->db->commit(); return $result;
        } catch (\Throwable $error) {
            try { if ($this->db->getTransactionNestingLevel() > 0) { $this->db->rollBack(); } } catch (\Throwable $ignored) { /* Preserve the original operation failure. */ }
            throw $error;
        } finally { $this->active = false; $this->activeKey = ''; }
    }
    public function load(string $orderKey): ?array
    {
        $this->assertOrder($orderKey);
        $payload = $this->db->fetchOne('SELECT payload FROM ' . $this->prefix . 'attempts WHERE order_key = ?', [$orderKey]);
        return is_string($payload) ? json_decode($payload, true, 64, JSON_THROW_ON_ERROR) : null;
    }
    public function save(string $orderKey, array $record): void
    {
        $this->assertOrder($orderKey);
        $this->db->executeStatement('UPDATE ' . $this->prefix . 'attempts SET payload = ? WHERE order_key = ?', [json_encode($record, JSON_THROW_ON_ERROR), $orderKey]);
    }
    public function claimEvent(string $merchantId, string $eventId, string $payloadHash): bool
    {
        if (!$this->active || $merchantId === '' || $eventId === '' || !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) { throw new \RuntimeException('A transaction and authenticated event identity are required.'); }
        $event = hash('sha256', $merchantId . "\0" . $eventId);
        if ($this->insert('events', '(event_hash,payload_hash,received_at) VALUES (?,?,?)', [$event, $payloadHash, time()], '(event_hash)') === 1) { return true; }
        $previous = $this->db->fetchOne('SELECT payload_hash FROM ' . $this->prefix . 'events WHERE event_hash = ?', [$event]);
        if (!is_string($previous) || !hash_equals($previous, $payloadHash)) { throw new \RuntimeException('Authenticated event content changed for the same event ID.'); }
        return false;
    }
    /** @param list<mixed> $params */
    private function insert(string $table, string $values, array $params, string $conflict): int
    {
        $start = $this->dialect === 'mysql' ? 'INSERT IGNORE INTO ' : ($this->dialect === 'sqlite' ? 'INSERT OR IGNORE INTO ' : 'INSERT INTO ');
        $end = $this->dialect === 'pgsql' ? ' ON CONFLICT ' . $conflict . ' DO NOTHING' : '';
        return (int) $this->db->executeStatement($start . $this->prefix . $table . ' ' . $values . $end, $params);
    }
    private function assertOrder(string $key): void
    {
        if (!$this->active || $this->activeKey !== $key) { throw new \RuntimeException('Ledger access is outside the order lock.'); }
    }
}

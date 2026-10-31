<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Lunixi\Symfony\DoctrineLedgerStore;

try {
    $config = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $config['host'], 'port' => $config['port'], 'user' => $config['username'], 'password' => $config['password'], 'dbname' => $config['database'], 'charset' => 'utf8mb4']);
    $db->executeStatement('SET SESSION innodb_lock_wait_timeout = 10');
    $prefix = $argv[2];
    if (!preg_match('/^lx_test_[a-f0-9]{8}_$/D', $prefix)) { throw new RuntimeException('Invalid test prefix.'); }
    $store = new DoctrineLedgerStore($db, $prefix);
    $applied = $store->transaction('order-1', function () use ($db, $store, $prefix): bool {
        if (!$store->claimEvent('merchant', 'concurrent-event', hash('sha256', 'same-body'))) { return false; }
        $db->executeStatement('UPDATE ' . $prefix . 'native_order SET applied = applied + 1 WHERE id = 1');
        usleep(150000); // Keep the transaction open long enough for the second process to contend.
        return true;
    });
    echo $applied ? 'processed' : 'duplicate';
} catch (Throwable $error) {
    fwrite(STDERR, 'Disposable database worker failed.');
    exit(1);
}

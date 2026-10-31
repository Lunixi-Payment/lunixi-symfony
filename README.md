<p align="center"><a href="https://lunixi.com"><img src="https://raw.githubusercontent.com/Lunixi-Payment/lunixi-symfony/main/.github/assets/lunixi-logo.svg" alt="Lunixi" height="64"></a></p>

# Lunixi Symfony Bundle

[![CI](https://github.com/Lunixi-Payment/lunixi-symfony/actions/workflows/ci.yml/badge.svg)](https://github.com/Lunixi-Payment/lunixi-symfony/actions/workflows/ci.yml) · [Türkçe](README.tr.md) · [Documentation](https://docs.lunixi.com/sdks/symfony) · [MIT](LICENSE)

Inject the existing Lunixi PHP SDK into Symfony, or enable its durable checkout workflow on the same Doctrine connection as your orders. The bundle registers services and a schema command; your application owns routes, authentication, order permissions and fulfillment. No public payment endpoint is silently installed.

Requires PHP 8.1+, a supported Symfony 6.4/7.4/8.1 release and PHP SDK 0.29.5+. Commerce needs a transactional Doctrine DBAL connection: MySQL/InnoDB, PostgreSQL or SQLite. The application must use the same connection for its order changes. Native local verification used Symfony 8.1.10, DBAL 4.5.0, PHP 8.4, SQLite and MariaDB; see [verification](docs/VERIFICATION.md).

## Install

**0.1.2 is prepared source**, requiring PHP SDK 0.29.5+ for backend-provided payment page URLs. The latest verified [Packagist](https://packagist.org/packages/lunixi/symfony-bundle) release is **0.1.1**. After 0.1.2 and its SDK dependency are published, install the updated integration with:

```sh
composer require lunixi/symfony-bundle:^0.1.2
```

Until publication, use the prepared source and an explicitly configured local SDK source for development. Check [GitHub releases](https://github.com/Lunixi-Payment/lunixi-symfony/releases) and Packagist before deploying 0.1.2; normal installation of a published release does not need a VCS override.

Register the bundle in `config/bundles.php`:

```php
return [Lunixi\Symfony\LunixiBundle::class => ['all' => true]];
```

Configure `config/packages/lunixi.yaml`. Keep credentials in Symfony Secrets or your server secret manager, outside the web root; never commit them:

```yaml
lunixi:
  key_id: '%env(LUNIXI_KEY_ID)%'
  private_key: '%env(LUNIXI_PRIVATE_KEY_PEM)%'
  # The key determines TEST/LIVE. The default gateway is HTTPS.
  base_url: 'https://api-gateway.lunixi.com'
  cache_pool: 'cache.app'
  commerce_enabled: true
  connection_service: 'doctrine.dbal.default_connection'
  merchant_id: '%env(LUNIXI_MERCHANT_ID)%'
  webhook_secret: '%env(LUNIXI_WEBHOOK_SECRET)%'
  storage_key: '%env(base64:LUNIXI_STORAGE_KEY)%'
```

`LUNIXI_STORAGE_KEY` is a separate base64-encoded random 32-byte key for stored checkout tokens. Back it up and plan rotation; it is not a webhook secret. A private cache pool stores short-lived bearer tokens. `allowed_origins` defaults to the production origin; explicitly configure an operator-approved HTTPS origin if a different gateway is required. It is not a customer-supplied URL. A custom SDK `HttpClientInterface` service can be selected with `http_client_service`.

If DoctrineBundle is already installed, its connection service can be reused. Otherwise register your own shared DBAL connection. For example, a private SQLite application connection:

```yaml
services:
  app.payment_connection:
    class: Doctrine\DBAL\Connection
    factory: ['Doctrine\DBAL\DriverManager', 'getConnection']
    arguments:
      - { driver: pdo_sqlite, path: '%kernel.project_dir%/var/payments.sqlite' }
```

Set `connection_service: app.payment_connection` and use that same service for native order writes. Do not wrap checkout creation in another transaction: the attempt reservation must commit before the gateway request.

Run the explicit migration command during deployment:

```sh
php bin/console lunixi:ledger:install
```

It creates the attempt and event tables without deleting existing financial history. Never run schema DDL from a webhook. Uninstalling the bundle must not delete the application's ledger or pending payments.

## Services and checkout

| Service | Purpose |
| --- | --- |
| `Lunixi\Sdk\LunixiClient` / `lunixi.client` | Complete PHP SDK service clients, including payments, fraud and other documented products |
| `CheckoutService` | Durable checkout attempts, authenticated reconciliation, capture/refund/void and operation status |
| `MerchantContext` | Configured merchant identity and raw Symfony Request webhook verification |
| `DoctrineLedgerStore` / `LedgerStore` | Transaction-aware per-order lock, encrypted-token record and atomic event claim |
| `CacheTokenStore` | Private PSR-6 token persistence between requests |

Inject `CheckoutService` and `MerchantContext` in an authorized application service. Read and lock your own server-side order; do not accept its amount, currency or ownership from browser input:

```php
use Lunixi\Sdk\Commerce\{CheckoutService, Money, OrderSnapshot};
use Lunixi\Symfony\MerchantContext;

$order = new OrderSnapshot($merchant->merchantId(), 'order-1001', Money::minor('100.50'), 'TRY');
$session = $checkout->start('order-1001', $order, 'https://shop.example/payment/return');
// Render browserConfig() with the official checkout bundle on the owned merchant page.
```

Embedded checkout is the default: the Lunixi form opens on your own website. Load the fixed `https://cdn.lunixi.com/lunixi-checkout.min.js`, provide `#lunixi-checkout-form`, pass the script-safe JSON from `$session->browserConfig()` to `Lunixi.checkout.create`, then call `checkout.mount('#lunixi-checkout-form')`. Preserve your application's CSP nonce and send `Cache-Control: private, no-store`. Do not render gateway HTML or collect PAN/CVV. For an optional hosted flow, pass `true` as the fourth argument before creating the attempt and redirect to `$session->hostedUrl()`: this is the exact validated backend `paymentPageUrl`, encrypted with the attempt. Never construct it from the embedded token, which may have another origin binding. Missing legacy URLs require reconciliation; never rotate a key to get around them.

Payment methods, installments and eligible split flows come from the merchant's Lunixi form configuration. They are not duplicated as Symfony switches. The fifth `start` argument enables preauthorization; `AUTHORIZED` holds an order and never fulfills it. `Money::exponent` and exact decimal conversion reject unsupported/native precision mismatches. Split functionality also requires gateway rollout and merchant eligibility.

## Webhooks and fulfillment

Create one dedicated POST route that reads the raw Symfony Request before JSON middleware. Exempt only that signed provider route from browser CSRF; customer checkout/admin routes still require native authentication, authorization and CSRF.

```php
$event = $merchant->verifyWebhook($request, $checkout);
// Signed data.orderId may locate a candidate owned order; it is not payment proof.
$decision = $checkout->reconcile($orderKey, $applyNativeOrder, $event);
```

The shared SDK verifies freshness and v2 HMAC, matches the persisted payment against authenticated GET, checks merchant/order/currency/amount/settlement counters and requires a complete split-parent aggregate. A browser return never marks paid. A lost create response can bind only its exact opaque reservation after authenticated recovery. The application callback runs inside the DBAL transaction; lock and compare the current native order against its original snapshot, retain fraud/manual holds, and make invoice/stock transitions idempotent by payment ID. Queue notifications in a transactional outbox; send them after commit. Return 2xx only after commit. Database, signature and uncertain-provider failures must not be acknowledged as paid.

Repeated events skip application; changed content under the same event ID is rejected. Failed native writes roll the receipt back, so redelivery can succeed. Schedule authenticated reconciliation for pending/review attempts. Avoid logging credentials, tokens, headers, raw webhooks or buyer data.

## Capture, refund and void

Protect native admin actions with permission and CSRF checks, and persist a stable native operation ID before calling:

```php
$checkout->mutate($orderKey, 'refund:1001:1', 'refund', 5000, $applyNativeOrder);
$status = $checkout->operationStatus($orderKey, 'refund:1001:1');
```

Only `state === 'confirmed'` is a completed operation. An asynchronous 2xx can remain `pending`; a timeout is `uncertain`. Retry the same ID/body; fresh operation IDs are blocked while an operation is unresolved. Use `capture` with a minor-unit amount or `void` with `null`. Partial capture stays on hold; refunds are limited to captured funds. Native credit notes, tax adjustments, restocking and notifications remain your domain policy. See the SDK's [commerce contract](https://github.com/Lunixi-Payment/lunixi-php/blob/main/COMMERCE.md).

## Development and checks

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
composer audit --locked
```

Database integration tests require an explicitly disposable `lunixi_` database and `LUNIXI_TEST_DB_FILE`; see [verification](docs/VERIFICATION.md). They create/drop only randomized test-prefix tables. No real merchant request or funds movement is performed. The native kernel and real DB tests exercise autowiring, private cache persistence, encrypted token storage, webhook tampering, DBAL nesting, rollback, order-key isolation and two-process exactly-once application.

Financial outcomes that remain uncertain are held for review. `FAILED` alone does not authorize a new attempt: the public payment read does not expose definitive provider outcome certainty. Unresolved financial retries stop after 23 hours; authenticated GET reconciliation remains available. A timeout, token expiry or browser callback never generates a fresh financial key.

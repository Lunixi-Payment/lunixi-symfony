# Changelog

## 1.0.0 — 2026-11-01

- Breaking: monetary minor units are canonical integer strings in requests, responses and platform settlement records. Numeric money is rejected; counts, rates and quantities retain their existing types.
- Convert persisted decimal text with the currency exponent; retain exact values above the JavaScript safe-integer limit up to signed64 for fiat. Capture/refund omission retains its existing meaning; explicit zero is invalid.
- Align with PHP SDK 1.0.0 and preserve raw webhook signatures, merchant identity checks, operation keys and order-state ownership. See [MIGRATION-1.0.md](MIGRATION-1.0.md) before upgrading.

## 0.1.2 — 2026-10-31

- Require PHP SDK0.29.5: embedded checkout is the default; optional redirects use the backend paymentPageUrl with encrypted durable persistence and no token-to-URL reconstruction.

## 0.1.1 — 2026-10-31

- Validate the configuration array root across Symfony 6.4/7.4/8.1 and detect SQLite through standard DBAL drivers across DBAL 3/4. Preserve original exceptions when a native callback already rolled back.

## 0.1.0

- Symfony service configuration and autowiring for the complete Lunixi PHP SDK.
- Private PSR-6 bearer-token cache and operator-approved HTTPS origins.
- Shared commerce orchestration, protected merchant webhook context and explicit ledger installation command.
- Same-connection Doctrine DBAL ledger for MySQL/InnoDB, PostgreSQL and SQLite, with atomic native writes, collision-safe inbox and per-order locks.
- Native Symfony kernel, SQLite and real MariaDB concurrency/rollback tests; English and Turkish setup guides.

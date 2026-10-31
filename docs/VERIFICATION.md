# Verification — 0.1.0

Local verification on 2026-10-31 used PHP8.4.1, Symfony8.1.10 and Doctrine DBAL4.5.0. No real gateway credentials or funds were used.

- 9 tests / 35 assertions passed with the native Symfony kernel, SQLite and an isolated real MariaDB database. No tests were skipped in that run.
- PHPStan level8: no errors in package source.
- Composer audit: no reported advisories in the resolved development dependencies.
- Actual native cases: service registration, private filesystem cache persistence, encrypted checkout token, hosted origin, raw Request signature/tamper handling, DBAL nested transaction rollback, same-event collision handling, case-sensitive order keys, SQL parameter isolation and two-process exactly-once native order application.
- PostgreSQL SQL support is implemented through DBAL; a local PostgreSQL run was not performed. Symfony6.4/7.4 and DBAL3 compatibility belongs to the configured CI matrix until verified there.

Run all local database cases with `LUNIXI_TEST_DB_FILE=/absolute/path/to/disposable-db.json vendor/bin/phpunit`. The JSON keys are `host`, `port`, `database`, `username`, `password`; the database name must start with `lunixi_`. Use a least-privileged account for that disposable database, keep the file outside source with mode0600, and never point it at production. Tests create/drop only randomized `lx_test_` tables.

The bundle cannot supply an application's customer authorization, tax/invoice policy, native order model or fulfillment behavior. The shared SDK's commerce tests validate financial evidence separately; these tests validate the Symfony/DBAL adapter boundary.

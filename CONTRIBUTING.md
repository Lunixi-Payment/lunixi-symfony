# Contributing

Run `composer install`, `vendor/bin/phpunit`, `vendor/bin/phpstan analyse --no-progress` and `composer audit --locked`. Use the explicit disposable-database integration configuration in docs/VERIFICATION.md for native transaction/concurrency tests. Never run them against production or real merchant funds.

Keep financial logic in the shared Lunixi PHP SDK. This bundle owns Symfony configuration, private cache integration, raw Request handling and native DBAL transaction behavior. Preserve scope and avoid logging secrets or personal data. Include regression tests for any changed authorization, replay, retry or order-state behavior.

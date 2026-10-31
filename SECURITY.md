# Security

Report suspected vulnerabilities privately through GitHub Security Advisories for this repository. Do not put private keys, tokens, buyer data, webhook bodies or database credentials in issues.

Keep API keys and storage keys in private server secret storage. Allow only operator-approved HTTPS gateway origins, private token cache pools and transactional order databases. Verify raw-body v2 signatures and authenticated settlement before fulfillment. Browser/customer routes need native authorization and CSRF; only the dedicated provider webhook is exempt from browser CSRF. Persist financial operation IDs, reuse them after uncertain results, and use a transactional notification outbox.

Only the 0.1.x line is maintained. Keep Symfony and its dependencies on supported security patch releases. Automated checks are not a guarantee of production acceptance or a complete penetration assessment.

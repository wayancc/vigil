# Security policy

## Supported versions

Security fixes go into the latest release. Please update to it before reporting.

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

**Please don't report security problems in public issues, discussions or pull requests.**

Report them privately through GitHub instead:

1. Go to the [Security tab](https://github.com/wayancc/vigil/security) of this repository.
2. Click **Report a vulnerability**.
3. Describe the problem, the version you tested, and the steps to reproduce it. A proof of concept helps.

Only the maintainers can see the report.

## What to expect

- We'll confirm we received your report within 3 working days.
- We'll tell you whether we can reproduce it, and how we plan to fix it.
- Once a fix is released, we'll publish a security advisory. We'll credit you in it unless you'd rather stay anonymous.

Please give us reasonable time to release a fix before you disclose the issue publicly.

## Scope

In scope: anything in this repository. For example, authentication and session handling, CSRF protection, access to `storage/`, the heartbeat and web-cron tokens, the public API and badges, and how alert channel secrets are stored and sent.

Out of scope:
- Problems that need an attacker who already has admin access to the dashboard or shell access to the server.
- A misconfigured server, such as a document root that points at the project folder instead of `public/`.
- Vulnerabilities in PHP, the web server or other software that Vigil doesn't ship.

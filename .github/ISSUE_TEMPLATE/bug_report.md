---
name: Bug report
about: Something in Vigil doesn't work as expected
title: ''
labels: bug
assignees: ''
---

<!-- Found a security problem? Don't post it here. See SECURITY.md. -->

## What happened

<!-- What you did, what you expected, and what happened instead. Error messages and screenshots help. -->

## Steps to reproduce

1.
2.
3.

## Environment

- **Vigil version:** <!-- e.g. 0.1.0, or the commit hash -->
- **PHP version:** <!-- output of `php -v` -->
- **Hosting:** <!-- Docker / shared hosting (cPanel, Plesk…) / VPS -->
- **How checks run:** <!-- cron with `bin/vigil run` / `bin/vigil work` worker / web-cron URL / Docker container -->
- **Operating system:** <!-- e.g. Ubuntu 24.04, Debian 12, Windows 11 -->
- **Web server:** <!-- Apache, nginx, PHP built-in server -->

## Monitor status

Output of `php bin/vigil status`. On Docker, run `docker compose exec vigil su -s /bin/sh www-data -c "php bin/vigil status"`.

<!-- Remove anything private, such as internal hostnames, before you post. -->

```
paste here
```

## Logs

<!-- Anything relevant from the PHP error log, the web server log, `docker compose logs vigil`, or the worker's output. -->

```
paste here
```

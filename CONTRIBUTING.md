# Contributing to Vigil

Thanks for helping. Bug reports, fixes and small features are all welcome. For anything large, open an issue first so we can agree on the approach before you write the code.

## Setup

You need PHP 8.2 or newer with `pdo_sqlite`, `curl`, `openssl` and `mbstring`. There is nothing to install: no Composer packages, no Node.

```bash
git clone https://github.com/wayancc/vigil && cd vigil
php bin/vigil serve      # http://127.0.0.1:8000/admin
php bin/vigil work       # in a second terminal, runs the checks
```

## Tests

```bash
php tests/run.php
```

The suite has no dependencies and needs no internet. It uses a throwaway SQLite file, a local PHP web server and local sockets, and runs on Linux, macOS and Windows. CI runs it on PHP 8.2, 8.3 and 8.4, and also builds the Docker image.

Add a `check()` line for every bug you fix and every behaviour you add, in the section of `tests/run.php` it belongs to. A pull request with failing tests won't be merged.

Before you push, check the syntax the way CI does:

```bash
find src templates public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null && php -l bin/vigil
```

## Code style

- **No dependencies.** Vigil has to run on shared hosting with only PHP. Don't add Composer packages, build steps or CDN assets. Fonts and icons are bundled.
- **PHP:** `declare(strict_types=1);` in every file, classes `final` unless they're meant to be extended, `readonly` properties and constructor promotion where they fit. Follow PSR-12 layout.
- **Formatting** is set in `.editorconfig`: UTF-8, LF line endings, 4 spaces for PHP, 2 for CSS, JS, Markdown and YAML.
- **SQL:** always use bound parameters through `Database::query()`, `one()`, `all()` or `value()`. Never put user input into a query string.
- **Templates:** escape all output with `<?= $v->e($value) ?>`. Every admin form includes `<?= $v->csrf() ?>`.
- **Schema changes:** add a new numbered migration in `Database::migrate()`. Never edit one that has already been released.
- **Comments** explain *why*, not *what*. Keep them short.
- **User-facing text** is plain and specific: "Enter a port between 1 and 65535", not "Invalid input".

## The `wyn-` prefix

Everything Vigil adds to the page or the database is prefixed, so it can't collide with a host's own CSS, scripts or tables:

| What | Prefix | Example |
|---|---|---|
| CSS classes | `wyn-` | `wyn-copied` |
| CSS custom properties | `--wyn-` | `--wyn-ink` |
| Data attributes | `data-wyn-` | `data-wyn-confirm` |
| Asset files | `wyn-` | `public/assets/wyn-vigil.css` |
| Database tables | `wyn_` | `wyn_monitors` |
| HTTP headers Vigil sends | `X-Wyn-` | `X-Wyn-Signature` |

New names must follow the same rule. Colors, fonts and border widths belong in `public/assets/wyn-tokens.css` as tokens, not hard-coded in other files.

## Pull requests

1. Branch from `main`.
2. Keep each pull request to one change, with tests.
3. Update `README.md` if you change behaviour, commands or configuration.
4. Describe what changed and why, and how you tested it.

By contributing, you agree that your work is licensed under the [AGPL-3.0](LICENSE), like the rest of Vigil.

## Security issues

Don't open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).

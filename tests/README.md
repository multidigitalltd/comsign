# ComSign tests

Integration tests that boot a **real WordPress** (backed by SQLite) with the
plugin active, then exercise the service/repository layer. No PHPUnit or other
framework — just a tiny assertion helper (`tests/lib/assert.php`) and a runner.

## Layout

| Path | Purpose |
|------|---------|
| `setup-wp.sh` | Provision a throwaway WordPress + SQLite drop-in with this plugin symlinked in, then install + activate it. Prints the path to `wp-load.php`. |
| `install.php` | Install the site and activate the plugin (used by `setup-wp.sh`; safe to re-run). |
| `bootstrap.php` | Load WordPress normally (so the plugin autoloads), set the admin user, stub email, expose `reset_tables()`. |
| `run.php` | Load `bootstrap.php`, include every `cases/*.php`, run and report. |
| `cases/` | The test cases. |
| `lib/assert.php` | `Test::add/ok/equals/throws/run`. |

## Running

```bash
# One-time: provision a WordPress under /tmp/comsign-wp and capture wp-load.php
WP_LOAD=$(tests/setup-wp.sh /tmp/comsign-wp | tail -1)

# Run the suite
COMSIGN_WP_LOAD="$WP_LOAD" php tests/run.php
```

If you already have a SQLite-backed WordPress with the plugin symlinked in,
point `COMSIGN_WP_LOAD` straight at its `wp-load.php` and run `tests/run.php`.

`composer test` runs the suite (assuming `COMSIGN_WP_LOAD` is set or the default
`/tmp/wp/WordPress-master/wp-load.php` exists); `composer lint` syntax-checks
every PHP file outside `vendor/`.

## CI

`.github/workflows/ci.yml` runs two jobs on every push/PR:

- **lint** — `php -l` across the codebase on PHP 7.4 / 8.1 / 8.3.
- **test** — provisions WordPress via `setup-wp.sh` and runs the suite on
  PHP 8.1 / 8.3.

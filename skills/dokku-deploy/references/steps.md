# fsn1 Dokku deployment steps

Use `<app>` and `<domain>` as resolved values. Commands shown with SSH mutate
production only when explicitly executed.

## 1. Repository and database preflight

```bash
git status --short --branch
git remote -v
git branch --show-current
composer show survos/deployment-bundle
php bin/console about
php bin/console debug:config doctrine dbal
```

Inspect the effective `DATABASE_URL` without printing credentials. The driver
must be PostgreSQL (`pdo_pgsql` / `postgresql`), not SQLite. If the app defaults
to SQLite, change its committed safe default or local override to PostgreSQL,
create the local database using the project's normal Docker/local convention,
and migrate it before continuing.

```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console doctrine:schema:validate
```

The last command is a hard push gate. Do not substitute `--skip-sync`.

Also run application-appropriate tests, Twig/container lint, and AssetMapper
audit.

## 2. Dokku app and remote

Read first:

```bash
ssh dokku@fsn1 apps:exists <app>
git remote get-url dokku
```

Create only if absent, then add or correct the local remote:

```bash
ssh dokku@fsn1 apps:create <app>
git remote add dokku dokku@fsn1:<app>
# Existing stale remote:
git remote set-url dokku dokku@fsn1:<app>
```

`php bin/console dokku:init --app=<app> --host=fsn1 --no-interaction` is useful
as a read-only checklist. Do not run it with `--force --no-interaction`: it can
provision storage and immediately deploy before this skill's PostgreSQL gate.

## 3. Tracked deploy files

The default for standard/demo Symfony apps is the Heroku PHP buildpack, without
a Dockerfile. Required files:

- `Procfile`: `web: vendor/bin/heroku-php-nginx -C nginx.conf public/`. The
  explicit `public/` web root and Symfony fallback config are mandatory. Add
  workers only when the app genuinely uses them.
- `nginx.conf`: copy the skill's `assets/nginx.conf` baseline, then preserve any
  justified app-specific directives. It routes non-files through
  `try_files $uri /index.php$is_args$args`; without this, nginx requests
  `/health` as a static file and Dokku rejects an otherwise healthy release.
- `app.json`: metadata, buildpack declaration, `APP_ENV=prod`, `APP_DEBUG=0`, a
  fixed `WEB_CONCURRENCY` value (never `generator`), AssetMapper compilation and
  Doctrine migrations in `predeploy`, an inexpensive `postdeploy`, and a real
  healthcheck route when available.

The PHP buildpack installs native extensions from Composer requirements. Declare
runtime extensions such as `ext-intl` and `ext-pdo_pgsql` explicitly. Ensure the
Dokku `DATABASE_URL` includes `?serverVersion=18&charset=utf8` (or appends those
parameters with `&` when a query already exists). Keep the version in the DSN,
not in Doctrine's separate `server_version` configuration key, so every runtime
consumer sees the same database contract during build and release.

A typical predeploy is:

```text
php bin/console importmap:install && php bin/console asset-map:compile && php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
```

Migrations run on every push. Corpus loading does not: provision the first
release, then invoke the bounded `app:load` explicitly. Do not declare service
addons as documentation; provision them live through SSH.

Only use Docker/FrankenPHP when the user explicitly selects the high-traffic
path (for example ZM). It is optional and is not part of this standard flow.

Before the first push, verify all three parts of the healthcheck chain:

```bash
grep -F -- '-C nginx.conf public/' Procfile
grep -F 'try_files $uri /index.php$is_args$args;' nginx.conf
php bin/console debug:router | grep -F '/health'
```

The route may come from `survos/tabler-bundle`; an app-local health controller
is unnecessary when Tabler already registers it. The nginx fallback is still
required because the buildpack's default nginx configuration otherwise treats
`/health` as a static path and never boots Symfony.

## 4. PostgreSQL through SSH

Inspect without exposing credentials:

```bash
ssh dokku@fsn1 postgres:exists <app>-db
ssh dokku@fsn1 postgres:linked <app>-db <app>
```

Do not run `postgres:info` in visible output: it prints the complete DSN,
including the password. When version or status is needed, filter on the remote
host before output and explicitly exclude `Dsn`; prefer targeted safe commands.

If absent, create and link the dedicated service:

```bash
ssh dokku@fsn1 postgres:create <app>-db >/dev/null
ssh dokku@fsn1 postgres:link <app>-db <app> >/dev/null
```

Confirm only presence, never value:

```bash
ssh dokku@fsn1 config:get <app> DATABASE_URL
```

Capture/suppress that output in agent work. Confirm the scheme is PostgreSQL
without reproducing the URL. Dokku's create, link, config-set, and postgres-info
commands can print credentials even on success, so suppress their stdout or use
a credential-safe targeted check. Set other required config using `config:set`;
use a securely generated `APP_SECRET` and do not include it in logs or reports.

After linking, normalize the hidden value so it ends with the DBAL contract
`serverVersion=18&charset=utf8`; do this through a shell variable with all
output suppressed. Never paste the expanded URL into a command, log, or report.

## 5. Push and verify

Immediately before pushing, rerun:

```bash
php bin/console doctrine:schema:validate
git status --short --branch
git rev-parse HEAD
git remote get-url dokku
```

Then deploy:

```bash
git push dokku main
```

Verify live state:

```bash
ssh dokku@fsn1 ps:report <app>
ssh dokku@fsn1 logs <app> --num 100
ssh dokku@fsn1 run <app> php bin/console doctrine:schema:validate
ssh dokku@fsn1 run <app> php bin/console doctrine:migrations:status
ssh dokku@fsn1 domains:report <app>
```

Run `ssh dokku@fsn1 run <app> php bin/console app:load` when the application
uses that standard bounded loader and its source inputs are available inside
the release or otherwise staged. Confirm its scope first. Then verify a page whose
response depends on loaded PostgreSQL data. Check one compiled `/assets/...`
URL as well as the homepage.

## 6. Public domain and TLS

Resolve the proposed public hostname and test it before mutation. Verify fsn1's
current public address from the host rather than copying an old value. A
Cloudflare-proxied wildcard returns Cloudflare edge addresses rather than the
origin. Inspect the exact hostname record and the zone SSL mode before deciding
whether the wildcard is suitable.

When the zone is in Flexible SSL mode and Dokku enforces HTTPS after certificate
installation, a proxied hostname loops between Cloudflare HTTP-to-origin and
Dokku's HTTPS redirect. Keep the fix scoped: create an explicit DNS-only `A`
record for the app pointing at fsn1. Do not change a whole zone to Full or Full
(strict) without separately verifying every affected origin and obtaining
authorization for that zone-wide change.

After the app is healthy, replace the generated non-public app hostname with
the public hostname. Do not leave `<app>.fsn1-survos` attached when requesting
a certificate: Let's Encrypt rejects the entire order because that hostname has
no public suffix.

```bash
ssh dokku@fsn1 domains:add <app> <domain>
ssh dokku@fsn1 domains:remove <app> <app>.fsn1-survos
ssh dokku@fsn1 domains:report <app>
ssh dokku@fsn1 letsencrypt:set <app> email tacman@gmail.com
ssh dokku@fsn1 letsencrypt:enable <app>
```

The standard Survos Let's Encrypt contact is always `tacman@gmail.com`. Inspect
current certificate state before and verify it afterward:

```bash
ssh dokku@fsn1 letsencrypt:list
ssh dokku@fsn1 letsencrypt:report <app>
curl -fsSI https://<domain>/health
```

If public DNS is still cached, verify the new origin immediately without
weakening TLS:

```bash
curl --resolve <domain>:443:<fsn1-ip> -fsSI https://<domain>/health
```

Do not print DNS-provider tokens or other certificate-plugin credentials while
inspecting another app. Ordinary public app hostnames should use HTTP-01; a
Cloudflare DNS provider is only needed for DNS-01 requirements such as wildcard
certificates.

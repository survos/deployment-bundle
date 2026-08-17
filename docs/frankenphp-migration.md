# Migrating a Survos app from the PHP buildpack to FrankenPHP

The reference for moving a dokku-deployed Symfony app off `heroku-buildpack-php`
(herokuish + nginx + php-fpm inside the container) onto a FrankenPHP Dockerfile.

Done so far: **packages** (2026-08-12, first), **zm** (2026-08-17). `packages` is the
smallest and is the one to copy from; this doc is the "why" behind each file, plus the
failures that only show up on a real deploy.

## Why bother

The buildpack image runs nginx in front of php-fpm, and php-fpm boots a fresh PHP process
per request: autoloader, container, Doctrine metadata, Twig cache lookups, all of it, every
time. For an app whose hot path is "read one row from a local SQLite file and render a
template" that boot cost dominates the actual work by an order of magnitude — which is how
a site ends up blocking crawlers it should have been able to serve trivially.

FrankenPHP embeds PHP inside Caddy: one long-lived process, warm opcache, no per-request
fork. It also collapses three moving parts (nginx config, fpm pool config, the buildpack's
own `heroku-php-nginx` wrapper) into two files you own.

This migration does **not** turn on FrankenPHP's *worker mode* — the app still runs a normal
request lifecycle per request, just without the process boot. Worker mode is a separate,
larger step with real state-leak requirements; see "Worker mode" at the bottom.

## What changes

| Buildpack | FrankenPHP |
|---|---|
| `app.json` → `"image": "gliderlabs/herokuish"` + `buildpacks` | neither; dokku picks the `dockerfile` builder because a `Dockerfile` exists |
| `nginx.conf` | `Caddyfile` |
| `fpm_custom.conf` | `docker/php.ini` |
| `Procfile` → `web: vendor/bin/heroku-php-nginx …` | `web: frankenphp run --config /etc/caddy/Caddyfile` |
| assets built in `app.json` predeploy | built in the Dockerfile, at image build time |
| `WEB_CONCURRENCY` | meaningless — remove it |

Everything else — dokku config vars, storage mounts, addons, healthchecks, the `dokku` git
remote, `git push dokku main` — is builder-agnostic and carries over untouched. In
particular **storage mounts keep working**: `/mnt/volume-1/platform-data:/platform` is a
docker bind-mount either way.

## The five files

### 1. `Dockerfile`

Three stages: `base` (OS + extensions), `build` (composer + assets), `prod` (base + the
built app). Copy zm's or packages' verbatim and change the extension list.

**Extensions.** Start from what the image already ships, don't guess:

```bash
docker run --rm dunglas/frankenphp:1-php8.5 php -m
```

That already includes `ctype iconv mbstring sodium sqlite3 pdo_sqlite opcache`. Then find
what the app's dependency tree actually asks for, rather than reading composer.json's own
`require` (which is always an undercount — it names the app's direct needs, not its
bundles'):

```bash
php -r '$d=json_decode(file_get_contents("composer.lock"),true);
foreach($d["packages"] as $p) foreach(($p["require"]??[]) as $k=>$v)
  if(str_starts_with($k,"ext-")) $e[$k][]=$p["name"];
ksort($e); foreach($e as $k=>$v) echo "$k <- ".implode(", ",array_slice($v,0,4))."\n";'
```

Add `pdo_pgsql` (or `pdo_mysql`) for the prod database — nothing declares it, because the
DSN is runtime config — and `apcu`, which pays off more here than under fpm.

**Build-time memory.** Run the console commands with `php -d memory_limit=-1`, not at the
runtime limit:

```dockerfile
RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && php -d memory_limit=-1 bin/console cache:clear --env=prod --no-debug \
    && php -d memory_limit=-1 bin/console assets:install public --env=prod \
    && php -d memory_limit=-1 bin/console importmap:install --env=prod \
    && php -d memory_limit=-1 bin/console asset-map:compile --env=prod
```

Warming a big app's container is the memory-hungriest thing in the whole deploy and it is a
one-shot CLI process. Capping it at the per-request web limit is what turns "the deploy
OOMs" into a mystery — and Flex recipes have a habit of re-adding a plain `cache:clear` to
`composer.json`'s auto-scripts, which is the same trap by another route.

**Order matters twice:**

- `cache:clear` (which warms up) **must** precede `asset-map:compile`.
  `survos/js-twig-bundle`'s `FosRoutingCacheWarmer` is what writes
  `var/js_twig_bundle/generated/fos_routes.js`, and `asset-map:compile` fails without it
  already on disk. This gap recurs on every upgrade.
- `assets:install public` is **not optional**, and is the single most likely thing to be
  left out. Composer's auto-scripts normally run it, but the Dockerfile uses
  `composer install --no-scripts`, and `public/bundles/` is gitignored so it is absent from
  the tree dokku builds from. Leave it out and the image ships no `public/bundles/` at all:
  every bundle-provided asset 404s (EasyAdmin's, api-platform's, tabler's) while
  AssetMapper's own output under `public/assets/` serves fine — which is exactly what makes
  it look like a server problem instead of a missing build step.

**`COMPOSER_ALLOW_SUPERUSER=1` in the build stage.** Everything runs as root. Composer
detects that and silently disables all plugins under `--no-interaction` — including
`symfony/runtime`'s, which generates the bootstrap glue `bin/console` needs. Without this,
`bin/console` dies with "Symfony Runtime is missing" immediately after a `composer install`
that reported success.

**`COPY --from=build --chown=www-data:www-data /app /app`**, not a separate `RUN chown -R`.
One pass instead of two over a tree with thousands of warmed cache files.

**Runtime mount points.** If the app reads a dokku storage mount at boot (zm's doctrine
`folio` connection points at `%env(APP_DATA_DIR)%/folio/_bootstrap.folio`), `mkdir -p` the
directory in the prod stage so the build's cache warmup can resolve the path. The volume
shadows the empty directory at runtime.

### 2. `Caddyfile`

```
{ frankenphp }

:{$PORT:80} {
	root * /app/public
	request_body { max_size 128MB }
	php_server
}
```

That is genuinely the whole thing; `php_server` covers what the old `nginx.conf`'s
`try_files … /index.php?$query_string` block did.

**No `encode` directive.** dokku's own nginx vhost sits in front of the container, and
Cloudflare in front of that, and Cloudflare compresses to the browser regardless of what the
origin sends. Compressing at the origin only adds a layer that can go wrong in transit — on
packages, origin zstd was valid at the origin and still broke every asset in the browser
with `net::ERR_CONTENT_DECODING_FAILED` once it hit Cloudflare's edge. Drop the old
`gzip on` block; don't port it.

**`request_body max_size`.** Caddy enforces its own body cap before PHP sees the request, so
`upload_max_filesize` in php.ini is no longer sufficient on its own. Set Caddy's slightly
above PHP's so an over-size upload produces PHP's error rather than a bare Caddy 413.

### 3. `docker/php.ini`

Replaces `fpm_custom.conf`. FrankenPHP embeds PHP rather than running php-fpm, so there is
no pool config to hold `php_value[]` lines; these go in `$PHP_INI_DIR/conf.d/` and apply to
`bin/console` too. Port the old numbers rather than inventing new ones, then add opcache:

```ini
opcache.enable = 1
opcache.validate_timestamps = 0
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.interned_strings_buffer = 32
```

`validate_timestamps=0` is safe because the code in the image never changes — a deploy is a
new container. Under one long-lived process this is most of the win.

### 4. `Procfile`

```
web: frankenphp run --config /etc/caddy/Caddyfile
```

Dokku's Procfile support overrides the Dockerfile's `CMD` per process type **even under the
dockerfile builder**, so a stale `web:` line silently keeps the old broken command. Worker
lines carry over unchanged.

While you are in here: check the app's restart policy.

```bash
dokku ps:report <app> | grep -i restart
```

`messenger:consume --time-limit` exits **0** when the limit is reached, and docker's
`on-failure` policy (the dokku default) does not restart a container that exited 0. Under
`on-failure` every worker dies for good one time-limit after deploy and never returns —
visible as `Status <worker> 1: missing` while every web container is running. Fix with
`dokku ps:set <app> restart-policy unless-stopped`.

### 5. `.dockerignore`

Dokku builds from a git archive, so this mostly matters for local `docker build` runs — but
keeping the two contexts identical is what makes a local build a real rehearsal of the
deploy instead of an approximation. Exclude `vendor`, `var`, `public/assets`,
`public/bundles`, `.git`, tests, docs, and any data directory that lives on a volume.

### And in `app.json`

Drop `"image"`, `"buildpacks"`, and `WEB_CONCURRENCY`. Move `importmap:install` /
`asset-map:compile` out of the `predeploy` — they happen at build time now — leaving
migrations. `WEB_CONCURRENCY` also needs `dokku config:unset <app> WEB_CONCURRENCY`, since
`app.json`'s `env` block only seeds values at app creation.

## Rehearse locally — this is the point

```bash
docker build -t <app>-frankenphp:test .
```

Roughly 3–5 minutes cold (most of it compiling `intl`), seconds after that.

This is worth far more than it looks, because **it builds against `composer.lock`'s released
versions, not the `mono/link` symlinks**. Local dev resolves `vendor/survos/*` to
`~/sites/mono/bu/*` at HEAD, so app config written against a new bundle option works locally
and cannot possibly work on a deploy that installs the pinned release. The local docker
build is the first thing in the workflow that catches it. On zm it caught two, one after the
other:

```
Unrecognized option "favicon" under "survos_tabler". Available options are …
Unrecognized option "routes_enabled" under "survos_iiif". Available options are ""
```

Neither is a FrankenPHP problem — the buildpack deploy would have failed identically. The
fix is `composer update "survos/*" -W`, then re-run `php ~/sites/mono/link .` to restore the
symlinks. **Expect this on any app that has not deployed in a while**, and budget for it: it
is a real dependency bump, not a formality.

## Then deploy

```bash
git push dokku main
dokku logs <app> -t
```

Post-deploy checks, in this order — they map to the three bugs found on packages after its
first green deploy:

1. `curl -sI https://<app>/health` — 200.
2. A page that loads a **bundle** asset, not just an AssetMapper one: `/bundles/…` 404s
   means `assets:install` was skipped (see above).
3. `https://` in generated absolute URLs. TLS terminates upstream and reaches the container
   as plain HTTP, so without `trusted_proxies` Symfony ignores `X-Forwarded-Proto` and emits
   `http://` — which puts `http://` identities into JSON-LD `@id`s on an https site and
   breaks `history.replaceState()` in ux-search with a cross-origin `SecurityError`. See
   CONVENTIONS.md, "Reverse proxy / trusted_proxies"; zm and packages both apply it in
   `config/packages/framework.yaml`.

## Worker mode

Not enabled by this migration. FrankenPHP's worker mode keeps the kernel booted between
requests, which is the next order-of-magnitude win and also the point at which any service
holding per-request state becomes a cross-request leak. Bundles have to opt in deliberately:
`survos/schema-org-bundle`, for instance, ships a `SchemaOrgResetListener` that empties its
request graph on every main request precisely so one page's nodes cannot leak into the next
one's. Treat worker mode as its own migration, per app, after the plain FrankenPHP deploy
has been stable.

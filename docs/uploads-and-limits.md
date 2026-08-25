# File uploads on Dokku: the three limits

Verified on priceit, 2026-08-25, after an upload that returned 413 with the app
configured for 100M.

A request carrying a file passes through **three** things that can reject it,
and they are configured in three different places. Setting one and testing is
how an afternoon disappears — the symptom is identical each time.

```
client
  │
  ├─▶ Dokku's host nginx        client_max_body_size   ← dokku nginx:set
  │      (TLS, vhost, proxy_pass)
  │
  ├─▶ nginx inside the container client_max_body_size   ← nginx.conf, via -C
  │      (heroku-php-nginx)
  │
  └─▶ php-fpm                    post_max_size          ← fpm_custom.conf, via -F
                                 upload_max_filesize
```

The outer one rejects first, so raising only the app's own limits changes
nothing at all. Stock values are small: nginx defaults to **1M**, PHP to **2M**
per file and an **8M** post. One phone photo clears all three.

## All three

**1. Dokku's proxy** (server-side, not in your repo):

```bash
dokku nginx:set <app> client-max-body-size 100m
dokku proxy:build-config <app>
dokku nginx:report <app> | grep -i body     # confirm
```

**2. The container's nginx** — `nginx.conf` in the repo:

```nginx
client_max_body_size 100M;

index index.php;
location / { try_files $uri /index.php$is_args$args; }
```

**3. php-fpm** — `fpm_custom.conf` in the repo:

```ini
php_value[memory_limit] = 256M
php_value[post_max_size] = 100M
php_value[upload_max_filesize] = 100M
```

Both files are passed to the web process in the `Procfile`:

```
web: vendor/bin/heroku-php-nginx -C nginx.conf -F fpm_custom.conf public/
```

## The part that will bite you later

**`dokku nginx:set` is server state, not repo state.** It lives under
`/var/lib/dokku/config/<app>` and survives deploys — but nothing in your
codebase records that production depends on it. Rebuild the host, or create the
app somewhere else, and uploads silently break at 1M again with no diff to
explain why.

The repo-tracked equivalent is an `nginx.conf.sigil` in the app, which replaces
Dokku's vhost template. Worth it for anything long-lived; `nginx:set` is fine
for a quick fix as long as someone writes it down.

## Telling the three apart

- **413, `<hr><center>nginx</center>`** — an nginx rejected it. If the app's own
  `client_max_body_size` is already high, it was Dokku's proxy.
- **The app runs but `$_FILES` is empty, or a 500 deep in the framework** —
  PHP's `post_max_size` was exceeded. PHP discards the body and carries on,
  which is why this one looks like an application bug rather than a limit.
- **502 with a Cloudflare error page** — not a limit at all. Cloudflare
  replaces 5xx bodies with its own, so any JSON the app returned is gone. Do
  not return 5xx from an API a tunnel fronts; use a 4xx and the body survives.

## Also worth knowing

`asset-map:compile` belongs in `app.json`'s `predeploy`. Without it the page
renders and every asset 404s, which reads as a broken app rather than a missing
build step:

```json
{"scripts": {"dokku": {"predeploy": "sh -c 'bin/console asset-map:compile'"}}}
```

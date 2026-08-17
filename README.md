# Survos Deployment Bundle

Helpers for deploying a Symfony app to **Dokku** (e.g. on Hetzner). Provides a
single `dokku` console command that creates the app, scaffolds the deployment
files (`Procfile`, `nginx.conf`, `fpm_custom.conf`, `app.json`), manages config
and storage, and deploys.

```bash
composer require --dev survos/deployment-bundle
```

## The `dokku` command

```
bin/console dokku <action> [param] [--app=NAME] [--host=HOST] [--force]
```

| Action | What it does |
|--------|--------------|
| `bootstrap` (default) | `init` + `scaffold` in one go |
| `init` | Create the Dokku app + add the `dokku` git remote |
| `scaffold` | Create/update `Procfile`, `nginx.conf`, `fpm_custom.conf`, `app.json` |

> **Moving to FrankenPHP?** `scaffold` still writes the buildpack files (nginx + php-fpm).
> To put an app on FrankenPHP/Caddy instead — one long-lived process, no per-request PHP
> boot — follow [docs/frankenphp-migration.md](docs/frankenphp-migration.md). Done on
> packages and zm; the scaffolder has not been taught the FrankenPHP shape yet.
| `config [KEY=value]` | Show all config, or set one var |
| `storage [/host:/container]` | List or add a persistent mount |
| `deploy` | `git push dokku main` |
| `logs` / `ps` / `restart` / `destroy` | App lifecycle |

- **Preview by default.** Without `--force`, mutating steps are only *listed*.
  Add `--force` to actually create the app, write files, and set config.
- `--host` defaults to `ssh.survos.com`; the app name is auto-detected from the
  `dokku` git remote, else the directory name. Pass `--app=ai-demo` to override.
- Requires SSH access as `dokku@<host>` (test: `ssh dokku@ssh.survos.com apps:list`).

### Standalone app — happy path

```bash
bin/console dokku bootstrap --app=myapp --force   # app + remote + scaffold files
bin/console dokku config OPENAI_API_KEY=sk-...      # set secrets (repeat per var)
bin/console dokku deploy --force                    # git push dokku main
bin/console dokku logs                              # watch
```

The app is served from `public/` via `heroku-php-nginx` (see the generated
`Procfile`). The PHP buildpack runs `composer install --no-dev` on the server,
so `require-dev` packages (including this bundle) are NOT deployed.

---

## Deploying an app that lives in a **monorepo subdirectory**

Dokku deploys whatever you `git push`, and `dokku deploy` runs a plain
`git push dokku main`. That only works when the app **is its own git repo**. If
the app is a subdirectory of a larger monorepo (e.g. `demo/` inside
`symfony/ai`), a plain push would send the whole monorepo with the wrong root.

The fix: build a **throwaway git repo from the app directory** and push *that*.
Drop a `bin/deploy` script in the app (this is exactly what we use for the
`symfony/ai` demo → `ai-demo.survos.com`):

```bash
#!/usr/bin/env bash
# Deploy a monorepo-subdir Symfony app to Dokku via a throwaway git repo.
set -euo pipefail
APP="${DOKKU_APP:-ai-demo}"
DOKKU_HOST="${DOKKU_HOST:-ssh.survos.com}"
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"; ROOT="$(pwd)"

# 1) Config/secrets from .env.local (values never printed; .env.local stays gitignored)
CFG=( APP_ENV=prod APP_DEBUG=0 )
if [ -f .env.local ]; then
  while IFS= read -r line; do
    case "$line" in
      OPENAI_API_KEY=*|MISTRAL_API_KEY=*|ANTHROPIC_API_KEY=*|CEREBRAS_API_KEY=*)
        key="${line%%=*}"; val="${line#*=}"; val="${val%\"}"; val="${val#\"}"; val="${val%\'}"; val="${val#\'}"
        CFG+=( "${key}=${val}" );;
    esac
  done < .env.local
fi
ssh "dokku@${DOKKU_HOST}" config:set --no-restart "${APP}" "${CFG[@]}" >/dev/null

# 2) Throwaway repo built from THIS dir → push (triggers the build)
TMP_GIT="${ROOT}/.git-deploy"; rm -rf "$TMP_GIT"; trap 'rm -rf "$TMP_GIT"' EXIT
G() { git --git-dir="$TMP_GIT" --work-tree="$ROOT" "$@"; }
G init -q -b main
G add -A
G add -f composer.lock          # see gotcha below
G -c user.email=deploy@survos.com -c user.name=deploy commit -q -m "deploy ${APP}"
G remote add dokku "dokku@${DOKKU_HOST}:${APP}"
G push -f dokku main
```

Run `bin/console dokku scaffold --app=<app> --force` once to generate the
`Procfile`/`nginx.conf`/`app.json` in the subdir, then deploy with `bin/deploy`.
(`git subtree push --prefix=<dir> dokku main` is an alternative, but the
throwaway repo is simpler and keeps the monorepo history untouched.)

> A `bin/deploy` can equally be a **Castor** task or a **justfile** recipe —
> plain bash just avoids an extra dependency.

---

## Gotchas (learned deploying `ai-demo`)

1. **Track `composer.lock` for apps.** The PHP buildpack requires it. Monorepos
   often gitignore `composer.lock` globally (correct for *libraries*); add a
   negation in the app's own `.gitignore` so the app commits its lock:
   ```gitignore
   !composer.lock
   ```

2. **`app.json` `WEB_CONCURRENCY` must have a `value`, not a `generator`.** The
   scaffolded template used `"generator": "echo 5"`; Dokku can't run generators
   non-interactively and the release fails with
   *"required env var WEB_CONCURRENCY has no value … no TTY for prompt"*. Use:
   ```json
   "WEB_CONCURRENCY": { "description": "workers", "value": "2" }
   ```

3. **Trim `app.json` for minimal apps.** The template's `predeploy` runs
   `importmap:install`, `asset-map:compile` and `doctrine:migrations:migrate`.
   An app with no database should drop the migrations step (the AI demo only
   needs `bin/console asset-map:compile`).

   The template no longer declares `dokku-postgres`/`dokku-redis` addons or
   `secrets:decrypt-to-local`. The convention is the **system** Postgres and
   Redis reached via `DATABASE_URL`/`REDIS_URL` in Dokku config — the addon
   declarations were inert (Dokku does not auto-provision from `app.json`) and
   implied a wiring that did not exist. Add an addon back only if you genuinely
   want a Dokku-managed service for that app.

4. **Secrets: use `dokku config:set`.** The `bin/deploy` above pulls them from
   `.env.local`, which stays gitignored and is never pushed. Set config **before**
   the push so `APP_ENV=prod` is present at build time (asset compilation, cache
   warmup).

   **The Symfony Vault is deprecated for our apps — don't add it to new ones.**
   It was worth trying and it didn't pay off:

   - **It hides config from `dokku config:show`.** That is the tool everyone uses
     to inspect production, so a vaulted `DATABASE_URL` makes an app look
     misconfigured when it is fine. This has cost real debugging time.
   - **The security floor is unchanged.** `SYMFONY_DECRYPTION_SECRET` has to live
     in Dokku config anyway, so anyone who can read Dokku config can decrypt the
     vault. You end up maintaining two mechanisms with the weaker one's guarantees.
   - **It adds a boot-time dependency.** `secrets:decrypt-to-local` must succeed in
     `predeploy` before the app can start.
   - **Sharp edge:** `secrets:decrypt-to-local` writes `.env.prod.local`, *not*
     `.env.local` — a reliable source of confusion.

   Apps still on the vault (`lingua`, `mediary`, `ssai`) should migrate: read the
   value, `dokku config:set` it (a real env var takes precedence over the vault, so
   this is safe to do first and verify), then remove the vault entry. Several other
   apps carry vestigial `config/secrets/prod/` entries that are already overridden
   by Dokku config and can simply be deleted.

5. **Consuming a fork live.** If the app depends on an unreleased fork (e.g.
   `symfony/ai:dev-tac`), the app's `composer.json` must carry the VCS
   `repositories` entry + `minimum-stability: dev` so the buildpack's
   `composer install` can fetch it on the server. No local paths (`../`) survive
   a Dokku build.

---

## Verifying

```bash
bin/console dokku logs                 # or: ssh dokku@<host> logs <app> -t
curl -sI http://<app>.<host>/          # expect 200
```

## Further reading
- dokku CLI: https://github.com/SebastianSzturo/dokku-cli
- https://hamel.dev/blog/posts/dokku/
- Survos clusters: https://autobase.survos.com/clusters

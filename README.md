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

3. **Trim `app.json` for minimal apps.** The default template declares
   `dokku-postgres`/`dokku-redis` addons and a predeploy that runs
   `secrets:decrypt-to-local` + `doctrine:migrations:migrate`. If your app
   doesn't use a DB or the Symfony Vault, those fail or waste time — strip the
   `addons` and reduce `predeploy` (the AI demo only needs
   `bin/console asset-map:compile`).

4. **Secrets:** set API keys via `dokku config:set` (the `bin/deploy` above pulls
   them from `.env.local`, which stays gitignored and is never pushed) **or** via
   the Symfony Vault (then set `SYMFONY_DECRYPTION_SECRET` as a Dokku config var
   and keep `secrets:decrypt-to-local` in `predeploy`). Set config **before** the
   push so `APP_ENV=prod` is present at build time (asset compilation, cache warmup).

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

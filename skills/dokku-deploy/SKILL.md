---
name: dokku-deploy
description: Prepare and publish a standard Symfony application to the Survos fsn1 Dokku host, including app creation, Heroku PHP buildpack files, PostgreSQL provisioning through SSH, guarded schema validation, migrations, data loading, TLS, and live verification. Use for new Dokku apps and repeat deployments; do not use for non-Symfony services or destructive app/database removal.
---

# Deploy Symfony to fsn1 Dokku

Deploy a committed Symfony repository to `fsn1` using observable gates. Read
[references/steps.md](references/steps.md) before the first deployment of an
app or whenever its database, builder, domain, or remote is uncertain.

## Non-negotiable gates

- Default to host alias `fsn1`, never `ssh.survos.com`. Verify every remote.
- Never push to Dokku while Doctrine's effective local connection is SQLite.
  Switch the app to a reachable local PostgreSQL database first.
- Before every `git push dokku`, run `bin/console doctrine:schema:validate`
  against that local PostgreSQL connection. Both mapping and database must pass.
- Create and link the production PostgreSQL service through
  `ssh dokku@fsn1`; the PostgreSQL port is intentionally not public.
- Never expose or echo `DATABASE_URL`, `APP_SECRET`, or other config values.
- Dokku `postgres:create`, `postgres:link`, and `config:set` print credentials
  in normal output. Run them with output suppressed; on failure, rerun only a
  credential-safe diagnostic rather than dumping the command output.
- Treat `postgres:info` as credential-bearing too: its `Dsn` field contains the
  database password. Never emit its unfiltered output in tool logs or reports.
- Preview/read live state before mutation. App creation, database provisioning,
  linking, domain/TLS changes, and deployment require the user's authorization.
- Do not call a deployment complete from build output or `/health` alone.
  Verify the app process, migrations, a database-backed page, an asset, and the
  public HTTPS URL.

## Sequence

1. Inspect Git status, branch, `origin` and any Dokku remote; resolve uncommitted
   deploy files before deployment.
2. Confirm `survos/deployment-bundle` is installed in `require-dev`, then use
   its read-only diagnosis where available. Treat its output as evidence, not
   authority: the current builder detection can regard an unscaffolded app as
   FrankenPHP-ready.
3. Create/verify the Dokku app and add or correct the remote to
   `dokku@fsn1:<app>`.
4. Create or review tracked `Procfile`, `nginx.conf`, and `app.json` files.
   Standard/demo apps use the Heroku PHP buildpack and serve `public/`. Copy
   [assets/nginx.conf](assets/nginx.conf) as the baseline nginx configuration;
   the Procfile must load it with `-C nginx.conf`. Docker/FrankenPHP is optional
   and reserved for an explicitly chosen high-traffic deployment.
5. Confirm local PostgreSQL connectivity and run migrations plus the mandatory
   full `doctrine:schema:validate`. Stop if SQLite is effective or validation
   fails.
6. Via SSH, create or verify `<app>-db`, link it to `<app>`, and confirm that
   `DATABASE_URL` is set without printing it. Also set `APP_ENV=prod`,
   `APP_DEBUG=0`, and a non-empty `APP_SECRET`.
7. Commit the deploy files and push the same commit to `origin` before Dokku,
   unless the user explicitly requests a deployment-only experiment.
8. Before pushing, confirm the configured healthcheck path exists in Symfony's
   router. Push to the verified Dokku remote. `app.json` must run Doctrine
   migrations during every predeploy. Watch the release and stop on failed
   build, predeploy, migration, healthcheck, proxy configuration, or process
   startup.
9. If the app exposes a bounded `app:load`, run it explicitly after the first
   successful migration when data is required. Do not put corpus loading in
   every predeploy.
10. Verify process state, recent logs, HTTPS, a database-backed route, and a
   compiled asset. Verify the public DNS points to fsn1, replace Dokku's
   internal `<app>.fsn1-survos` vhost with the public domain, set the Let's
   Encrypt email to `tacman@gmail.com`, and issue the certificate. If the
   Cloudflare zone uses Flexible SSL, create a scoped DNS-only app record; do
   not change the whole zone's SSL mode for one deployment.
11. Report the exact commit, app, database service, domain/TLS state, migration
    state, checks performed, and anything intentionally deferred.

## Boundaries

- Do not destroy or recreate an existing app/database to resolve an ordinary
  configuration problem.
- Do not copy production data unless requested. An empty database followed by
  migrations and the application's bounded loader is the standard corpus-app
  path.
- Do not run a broad loader without first identifying its size and getting
  confirmation when it may be expensive.
- Keep `app.json` free of env `generator` entries. Secrets live in Dokku config,
  not Git or Symfony Vault.
- A persistent `/app/var` mount is optional for a database-backed app; add it
  only for actual runtime files that must survive releases.
- Do not create a Dockerfile merely to deploy a simple site. If FrankenPHP is
  explicitly selected, use the separate high-traffic migration procedure and
  perform its local Docker rehearsal.

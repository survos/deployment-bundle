<?php

declare(strict_types=1);

namespace Survos\DeploymentBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Ask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\AskChoice;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Modernized Dokku deployment commands: ONE class, MANY commands via method-level
 * #[AsCommand] (Symfony 7.4+). The centerpiece is `dokku:init`, a guided wizard that
 * diagnoses the app's deploy state, fixes what's missing (with confirmation), and
 * suggests the next step.
 *
 * Coexists with the legacy `dokku <action>` command for backward compatibility.
 *
 * @phpstan-type Check array{label: string, ok: bool|null, hint: string, fix: ?\Closure, fixLabel: string}
 */
final class DokkuCommands
{
    private SymfonyStyle $io;
    private string $host = 'ssh.survos.com';
    private bool $force = false;
    private string $app = '';

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[AsCommand('dokku:init', 'Diagnose the deploy state, fix any gaps, and suggest the next step')]
    public function init(
        SymfonyStyle $io,
        #[Option('App name (default: the dokku git remote, else the project directory)')] ?string $app = null,
        #[Option('Dokku host')] string $host = 'ssh.survos.com',
        #[Option('Apply fixes (otherwise preview only)')] bool $force = false,
        // Each per-check confirm() below stays imperative on purpose: the checklist
        // itself is computed at runtime from live SSH-diagnosed state (variable length,
        // variable content), which #[Ask]/#[AskChoice] aren't a fit for — those
        // attributes populate a FIXED, always-present parameter once before the command
        // body runs. This trailing menu, unlike the checklist, is always exactly these
        // four choices regardless of app state, so it's a clean fit for #[AskChoice].
        // With -n/--no-interaction (or any non-interactive input), both this and every
        // $io->confirm() below silently fall back to their declared default instead of
        // prompting — so `dokku:init --force -n` runs fully unattended, accepting
        // every fix and choosing "deploy".
        #[AskChoice('What next?', ['deploy', 'config', 'logs', 'nothing'], default: 'deploy')]
        ?string $next = null,
    ): int {
        $this->boot($io, $app, $host, $force);
        $io->title("Dokku · {$this->app} · {$this->host}");

        $checks = $this->diagnose();
        $this->renderChecklist($checks);

        $todo = array_values(array_filter($checks, static fn (array $c): bool => true !== $c['ok'] && null !== $c['fix']));
        if (!$todo) {
            $io->success("Everything's ready. Deploy with:  bin/console dokku:deploy");

            return Command::SUCCESS;
        }

        if (!$this->force) {
            $io->note(\sprintf('%d item(s) need attention. Re-run with --force to fix them interactively.', \count($todo)));

            return Command::SUCCESS;
        }

        foreach ($todo as $c) {
            if ($io->confirm($c['fixLabel'], true)) {
                ($c['fix'])();
            }
        }

        return match ($next ?? 'deploy') {
            'deploy' => $this->deploy($io, $this->app, $this->host, true),
            'config' => $this->config($io, null, $this->app, $this->host, $this->force),
            'logs' => $this->logs($io, $this->app, $this->host),
            default => Command::SUCCESS,
        };
    }

    #[AsCommand('dokku:deploy', 'Deploy via git push dokku main')]
    public function deploy(
        SymfonyStyle $io,
        #[Option] ?string $app = null,
        #[Option] string $host = 'ssh.survos.com',
        #[Option('Actually push (otherwise preview)')] bool $force = false,
    ): int {
        $this->boot($io, $app, $host, $force);
        $io->section("Deploy {$this->app}");

        if (!$this->force) {
            $io->text('[preview] $ git push dokku main   (re-run with --force)');

            return Command::SUCCESS;
        }

        $process = Process::fromShellCommandline('git push dokku main')->setTimeout(900);
        $process->setTty(Process::isTtySupported());
        $process->run(static fn ($t, $buffer) => print $buffer);

        if (!$process->isSuccessful()) {
            $io->error('Deploy failed (see output above).');

            return Command::FAILURE;
        }
        $io->success('Deployed. Check: bin/console dokku:logs');

        return Command::SUCCESS;
    }

    #[AsCommand('dokku:config', 'Show all env vars, or set one with KEY=value')]
    public function config(
        SymfonyStyle $io,
        #[Argument('KEY=value to set; omit to list all')]
        #[Ask('Env var to set as KEY=value (blank to list all)', default: '')]
        ?string $keyValue = null,
        #[Option] ?string $app = null,
        #[Option] string $host = 'ssh.survos.com',
        #[Option] bool $force = false,
    ): int {
        $this->boot($io, $app, $host, $force);

        if (null === $keyValue || '' === $keyValue) {
            $io->section("Config · {$this->app}");
            $this->ssh("config:show {$this->app}", mutates: false);

            return Command::SUCCESS;
        }

        if (!str_contains($keyValue, '=')) {
            $io->error("Expected KEY=value, got: {$keyValue}");

            return Command::FAILURE;
        }

        $this->ssh('config:set '.$this->app.' '.escapeshellarg($keyValue), mutates: true);

        return Command::SUCCESS;
    }

    #[AsCommand('dokku:logs', 'Tail the last 100 lines of app logs')]
    public function logs(SymfonyStyle $io, #[Option] ?string $app = null, #[Option] string $host = 'ssh.survos.com'): int
    {
        $this->boot($io, $app, $host, false);
        $this->ssh("logs {$this->app} --num 100", mutates: false);
        $io->text("Follow live:  ssh dokku@{$this->host} logs {$this->app} -t");

        return Command::SUCCESS;
    }

    #[AsCommand('dokku:destroy', 'Delete the Dokku app (asks to confirm)')]
    public function destroy(SymfonyStyle $io, #[Option] ?string $app = null, #[Option] string $host = 'ssh.survos.com'): int
    {
        $this->boot($io, $app, $host, true);
        if (!$io->confirm("Destroy app '{$this->app}'? This cannot be undone.", false)) {
            $io->text('Cancelled.');

            return Command::SUCCESS;
        }
        $this->ssh("apps:destroy {$this->app} --force", mutates: true);
        $io->success('Destroyed. Remove the git remote with: git remote remove dokku');

        return Command::SUCCESS;
    }

    // --- diagnosis -----------------------------------------------------------

    /**
     * @return list<Check>
     */
    private function diagnose(): array
    {
        $appExists = $this->appExists();
        $remote = $this->gitRemoteUrl('dokku');
        $hasRemote = null !== $remote;
        $envProd = 'prod' === $this->configGet('APP_ENV');
        $hasSecret = '' !== $this->configGet('APP_SECRET');
        $hasDb = '' !== $this->configGet('DATABASE_URL');
        $scaffolded = is_file($this->projectDir.'/Procfile') && is_file($this->projectDir.'/app.json');
        $hasStorage = $this->hasStorageMount();
        $onFrankenphp = $this->usesFrankenphp();
        $noGenerators = !$this->appJsonHasEnvGenerator();

        return [
            $this->check('Dokku app exists', $appExists, 'apps:create', 'Create the app?', fn () => $this->ssh("apps:create {$this->app}", allowFail: true, mutates: true)),
            $this->check('git remote "dokku"', $hasRemote, "dokku@{$this->host}:{$this->app}", 'Add the dokku git remote?', fn () => $this->run("git remote add dokku dokku@{$this->host}:{$this->app}", mutates: true)),
            $this->check('Deploy files (Procfile, app.json)', $scaffolded ?: false, 'scaffold', 'Scaffold the deploy files?', fn () => $this->scaffold()),
            // FrankenPHP is the default builder for Survos apps, including throwaway ones.
            // No auto-fix: this is a five-file migration with an app-specific extension
            // audit, and the local rehearsal build is the point of it. See the
            // frankenphp-migration skill in mono, and bu/deployment-bundle/docs/.
            $this->check('Builder: FrankenPHP (not the PHP buildpack)', $onFrankenphp, 'see frankenphp-migration skill — Dockerfile + Caddyfile, drop nginx.conf/fpm_custom.conf', 'no auto-fix (multi-file migration)', null),
            // dokku 0.38.27 stopped running app.json env `generator` entries. The deploy
            // does not warn -- it aborts at release with "required env var X has no value,
            // no default, and no TTY for prompt", after composer and asset build have
            // already succeeded, so it reads like a runtime fault rather than a manifest
            // one. 0.38.16 ran generators, so apps that deployed fine for years break the
            // first time they touch a newer host.
            $this->check('app.json env has no `generator` entries', $noGenerators, 'replace "generator" with "value" — dokku >= 0.38.17 ignores generators', 'no auto-fix (edit app.json)', null),
            $this->check('APP_ENV=prod', $envProd, 'config:set APP_ENV=prod', 'Set APP_ENV=prod?', fn () => $this->ssh("config:set {$this->app} APP_ENV=prod", mutates: true)),
            $this->check('APP_SECRET set', $hasSecret, 'config:set APP_SECRET', 'Generate and set an APP_SECRET?', fn () => $this->ssh("config:set {$this->app} APP_SECRET=".bin2hex(random_bytes(16)), mutates: true)),
            // NOTE: this fix provisions a dedicated per-app dokku-postgres addon. Some
            // survos-sites apps instead share one Postgres instance across apps (see
            // zm) — if that's your convention, decline this fix and set DATABASE_URL
            // yourself with `dokku:config` instead.
            $this->check('DATABASE_URL (postgres linked)', $hasDb, 'postgres:create + postgres:link', 'Provision + link a postgres DB?', fn () => $this->provisionPostgres()),
            $this->check('Persistent storage mounted', $hasStorage, 'storage:ensure-directory + storage:mount', 'Mount persistent storage at /app/var?', fn () => $this->provisionStorage()),
        ];
    }

    /**
     * @return Check
     */
    private function check(string $label, ?bool $ok, string $hint, string $fixLabel, ?\Closure $fix): array
    {
        return ['label' => $label, 'ok' => $ok, 'hint' => $hint, 'fix' => $fix, 'fixLabel' => $fixLabel];
    }

    /**
     * @param list<Check> $checks
     */
    private function renderChecklist(array $checks): void
    {
        $rows = [];
        foreach ($checks as $c) {
            $mark = match ($c['ok']) {
                true => '<info>✓</info>',
                false => '<error>✗</error>',
                default => '<comment>?</comment>',
            };
            $rows[] = [$mark, $c['label'], true === $c['ok'] ? '' : $c['hint']];
        }
        $this->io->table(['', 'Check', 'Fix'], $rows);
    }

    private function provisionPostgres(): void
    {
        $svc = $this->app.'-db';
        $this->ssh("postgres:create {$svc}", allowFail: true, mutates: true);
        $this->ssh("postgres:link {$svc} {$this->app}", allowFail: true, mutates: true);
    }

    /** True if the app has at least one storage mount (any mount — this doesn't
     *  verify /app/var specifically, since some apps mount elsewhere or not at all
     *  by design). Empty/unreachable both read as "no mount" (false, not null).
     *  `storage:list` always prints a "-----> app volume bind-mounts:" header line
     *  even with zero mounts, so checking for non-empty output is a false positive —
     *  actual mounts appear as additional lines after that header. */
    private function hasStorageMount(): bool
    {
        $p = Process::fromShellCommandline(\sprintf('ssh dokku@%s storage:list %s 2>/dev/null', escapeshellarg($this->host), escapeshellarg($this->app)));
        $p->setTimeout(30);
        $p->run();

        if (!$p->isSuccessful()) {
            return false;
        }

        $lines = array_filter(array_map('trim', explode("\n", trim($p->getOutput()))), static fn (string $l): bool => '' !== $l);

        return \count($lines) > 1;
    }

    private function provisionStorage(): void
    {
        $hostPath = "/var/lib/dokku/data/storage/{$this->app}";
        $this->ssh("storage:ensure-directory {$this->app}", allowFail: true, mutates: true);
        $this->ssh("storage:mount {$this->app} {$hostPath}:/app/var", allowFail: true, mutates: true);
    }

    private function scaffold(): void
    {
        // NOTE: the legacy `dokku scaffold` writes BUILDPACK files (nginx.conf,
        // fpm_custom.conf, a herokuish app.json). That is no longer the target shape --
        // FrankenPHP is the default for Survos apps, throwaway ones included. Pointing
        // people at it would scaffold an app straight into the migration backlog.
        $this->io->note([
            'FrankenPHP is the default builder. Do not scaffold buildpack files.',
            'Follow the frankenphp-migration skill (mono/.claude/skills/), or copy the five',
            'files from a working reference: ~/sites/packages (smallest) or ~/sites/zm.',
            'Files: Dockerfile, Caddyfile, docker/php.ini, Procfile, .dockerignore.',
            'The local `docker build` rehearsal is not optional -- it is the only step that',
            'resolves vendor/survos/* to the RELEASED versions in composer.lock instead of',
            'the mono/link symlinks, so it is where config written against mono HEAD fails.',
        ]);
    }

    /**
     * Buildpack apps are identifiable from the repo alone: a Dockerfile mentioning
     * frankenphp means dokku picks the dockerfile builder; nginx.conf or a herokuish
     * app.json means it does not.
     */
    private function usesFrankenphp(): bool
    {
        $dockerfile = $this->projectDir.'/Dockerfile';
        if (is_file($dockerfile) && false !== stripos((string) file_get_contents($dockerfile), 'frankenphp')) {
            return true;
        }

        return !is_file($this->projectDir.'/nginx.conf')
            && !is_file($this->projectDir.'/fpm_custom.conf');
    }

    /** @see the `app.json env has no generator entries` check for why this matters. */
    private function appJsonHasEnvGenerator(): bool
    {
        $appJson = $this->projectDir.'/app.json';
        if (!is_file($appJson)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($appJson), true);
        if (!is_array($data)) {
            return false;
        }
        foreach ($data['env'] ?? [] as $spec) {
            if (is_array($spec) && isset($spec['generator'])) {
                return true;
            }
        }

        return false;
    }

    // --- low-level helpers (shared shape with the legacy command) ------------

    private function boot(SymfonyStyle $io, ?string $app, string $host, bool $force): void
    {
        $this->io = $io;
        $this->host = $host;
        $this->force = $force;
        $this->app = $app ?: $this->appFromRemote() ?: basename($this->projectDir);
        if (!$force) {
            $io->note('PREVIEW — read-only checks run; mutating steps are listed. Use --force to apply.');
        }
    }

    private function ssh(string $args, bool $allowFail = false, bool $mutates = false): void
    {
        $this->run(\sprintf('ssh dokku@%s %s', escapeshellarg($this->host), $args), $allowFail, $mutates);
    }

    private function run(string $cmd, bool $allowFail = false, bool $mutates = false): void
    {
        $this->io->text(\sprintf('[%s] $ %s', $mutates ? 'change' : 'read', $cmd));
        if ($mutates && !$this->force) {
            $this->io->text('  (planned only; use --force)');

            return;
        }
        $p = Process::fromShellCommandline($cmd)->setTimeout(120);
        $p->run();
        if ($p->isSuccessful()) {
            if ('' !== $out = trim($p->getOutput())) {
                $this->io->text('  '.$out);
            }
        } elseif (!$allowFail) {
            $this->io->error(trim($p->getErrorOutput()) ?: 'command failed');
        }
    }

    /** Read a single dokku config value (empty string if unset / unreachable). */
    private function configGet(string $key): string
    {
        $p = Process::fromShellCommandline(\sprintf('ssh dokku@%s config:get %s %s 2>/dev/null', escapeshellarg($this->host), escapeshellarg($this->app), escapeshellarg($key)));
        $p->setTimeout(30);
        $p->run();

        return $p->isSuccessful() ? trim($p->getOutput()) : '';
    }

    private function appExists(): ?bool
    {
        $p = Process::fromShellCommandline(\sprintf('ssh dokku@%s apps:exists %s', escapeshellarg($this->host), escapeshellarg($this->app)));
        $p->setTimeout(30);
        $p->run();

        return 0 === $p->getExitCode() ? true : (1 === $p->getExitCode() ? false : null);
    }

    private function gitRemoteUrl(string $name): ?string
    {
        $p = Process::fromShellCommandline('git remote get-url '.escapeshellarg($name));
        $p->run();

        return $p->isSuccessful() ? trim($p->getOutput()) : null;
    }

    private function appFromRemote(): ?string
    {
        $url = $this->gitRemoteUrl('dokku');

        return ($url && preg_match('/^dokku@[^:]+:(.+)$/', $url, $m)) ? $m[1] : null;
    }
}

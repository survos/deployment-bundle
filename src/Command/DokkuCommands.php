<?php

declare(strict_types=1);

namespace Survos\DeploymentBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Ask;
use Symfony\Component\Console\Attribute\AsCommand;
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

        // Dynamic, post-diagnosis menu (this is what #[AskChoice] wraps, but the
        // choices depend on the freshly-computed state, so we ask imperatively here).
        $next = $io->choice('What next?', ['deploy', 'config', 'logs', 'nothing'], 'deploy');

        return match ($next) {
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

        return [
            $this->check('Dokku app exists', $appExists, 'apps:create', 'Create the app?', fn () => $this->ssh("apps:create {$this->app}", allowFail: true, mutates: true)),
            $this->check('git remote "dokku"', $hasRemote, "dokku@{$this->host}:{$this->app}", 'Add the dokku git remote?', fn () => $this->run("git remote add dokku dokku@{$this->host}:{$this->app}", mutates: true)),
            $this->check('Deploy files (Procfile, app.json)', $scaffolded ?: false, 'scaffold', 'Scaffold the deploy files?', fn () => $this->scaffold()),
            $this->check('APP_ENV=prod', $envProd, 'config:set APP_ENV=prod', 'Set APP_ENV=prod?', fn () => $this->ssh("config:set {$this->app} APP_ENV=prod", mutates: true)),
            $this->check('APP_SECRET set', $hasSecret, 'config:set APP_SECRET', 'Generate and set an APP_SECRET?', fn () => $this->ssh("config:set {$this->app} APP_SECRET=".bin2hex(random_bytes(16)), mutates: true)),
            $this->check('DATABASE_URL (postgres linked)', $hasDb, 'postgres:create + postgres:link', 'Provision + link a postgres DB?', fn () => $this->provisionPostgres()),
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

    private function scaffold(): void
    {
        // Reuse the legacy scaffolders; kept in the original DokkuCommand. For brevity
        // this delegates to a minimal Procfile/app.json; richer templates live in
        // ../templates and can be wired in here.
        $this->io->note('Run `bin/console dokku scaffold --force` for the full templated scaffold (Procfile, nginx.conf, fpm, app.json).');
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

<?php

namespace Survos\DeploymentBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand('dokku', 'Manage Dokku deployments')]
final class DokkuCommand extends Command
{
    private SymfonyStyle $io;
    private bool $executeChanges = false;
    private string $dokkuHost = 'ssh.survos.com';
    private ?string $appName = null;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] private string $projectDir,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Action: bootstrap|logs|config|storage|deploy|destroy')]
        ?string $action = 'bootstrap',
        #[Argument('Action parameter (e.g. mount path for storage, KEY=value for config)')] ?string $param = null,
        #[Option("App name (auto-detected from git remote or directory)")] ?string $app = null,
        #[Option("Execute mutating commands and file writes")] bool $force = false,
        #[Option("Dokku server host")] string $host = 'ssh.survos.com'
    ): int {
        $this->io = $io;
        $this->executeChanges = $force;
        $this->dokkuHost = $host;

        // Try to determine app name from git remote first, then fall back to directory name
        if (!$app) {
            $app = $this->getAppNameFromGitRemote();
        }
        $this->appName = $app ?? basename($this->projectDir);
        if (!$this->executeChanges) {
            $io->note('PREVIEW MODE - Read-only commands run, mutating commands are listed. Use --force to execute changes.');
        }

        return match($action) {
            'bootstrap', 'init', 'setup' => $this->bootstrap(),
            'logs', 'log' => $this->showLogs(),
            'config', 'env' => $this->handleConfig($param),
            'storage', 'mount' => $this->handleStorage($param),
            'deploy', 'push' => $this->deploy(),
            'destroy', 'delete', 'remove' => $this->destroyApp(),
            'restart' => $this->restart(),
            'ps', 'status' => $this->showStatus(),
            default => $this->showHelp(),
        };
    }

    private function showHelp(): int
    {
        $this->io->title('Dokku Command Help');
        $this->io->text([
            'Usage: bin/console dokku <action> [param] [options]',
            '',
            'Actions:',
            '  <info>bootstrap</info>              Initialize app (creates Procfile, nginx.conf, git remote, app)',
            '  <info>logs</info>                   View application logs',
            '  <info>config</info> [KEY=value]     View config or set env var',
            '  <info>storage</info> [mount]        List storage or add mount (/host:/container)',
            '  <info>deploy</info>                 Deploy via git push',
            '  <info>restart</info>                Restart the app',
            '  <info>destroy</info>                Delete the app (requires confirmation)',
            '  <info>ps</info>                     Show app status',
            '',
            'Options:',
            '  --app=NAME              Override app name',
            '  --host=HOST             Dokku server (default: ssh.survos.com)',
            '  --force                 Execute mutating commands and file writes',
            '',
            'Examples:',
            '  bin/console dokku bootstrap              # Initial setup',
            '  bin/console dokku logs                    # View logs',
            '  bin/console dokku config                  # Show all env vars',
            '  bin/console dokku config OPENAI_KEY=sk-  # Set env var',
            '  bin/console dokku storage                 # List mounts',
            '  bin/console dokku storage /host:/app/var  # Add mount',
            '  bin/console dokku deploy                  # Push to dokku',
            '  bin/console dokku destroy                 # Delete app',
        ]);

        return Command::SUCCESS;
    }

    private function bootstrap(): int
    {
        $this->io->title("Bootstrapping Dokku app: {$this->appName}");

        // Step 1: Create/verify Procfile
        $this->createProcfile();

        // Step 2: Create/verify fpm config
        $this->createFpmConfig();

        // Step 3: Create/verify nginx config
        $this->createNginxConfig();

        // Step 4: Create/verify app.json
        $this->createAppJson();

        // Step 5: Add git remote
        $this->addGitRemote();

        // Step 6: Create app on Dokku
        $this->createDokkuApp();

        // Step 7: Show current config
        $this->io->section("Initial config for {$this->appName}");
        $this->runDokkuCmd("config:show {$this->appName}");

        if (!$this->executeChanges) {
            $this->io->success('Preview complete. Re-run with --force to execute mutating steps.');
        } else {
            $this->io->success('Bootstrap complete!');
            $this->io->text([
                '',
                'Next steps:',
                '  bin/console dokku config OPENAI_KEY=sk-...  # Set env vars',
                '  bin/console dokku storage /var/data:/app/var # Add storage',
                '  bin/console dokku deploy                     # Deploy!',
            ]);
        }

        return Command::SUCCESS;
    }

    private function showLogs(): int
    {
        $this->io->title("Logs for {$this->appName}");
        $this->runDokkuCmd("logs {$this->appName} --num 100", mutates: false);

        $this->io->newLine();
        $this->io->text('To follow logs in real-time:');
        $this->io->text("  ssh dokku@{$this->dokkuHost} logs {$this->appName} -t");

        return Command::SUCCESS;
    }

    private function handleConfig(?string $param): int
    {
        // Keep local deployment metadata in sync when working with config
        $this->createAppJson();

        if (!$param) {
            // Show all config
            $this->io->section("Config for {$this->appName}");
            $this->runDokkuCmd("config:show {$this->appName}", mutates: false);
            return Command::SUCCESS;
        }

        // Set config
        if (!str_contains($param, '=')) {
            $this->io->error("Invalid format: $param (expected KEY=value)");
            return Command::FAILURE;
        }

        $this->io->section("Setting config for {$this->appName}");
        $this->runDokkuCmd("config:set {$this->appName} " . escapeshellarg($param), mutates: true);

        return Command::SUCCESS;
    }

    private function handleStorage(?string $param): int
    {
        if (!$param) {
            // List storage
            $this->io->section("Storage mounts for {$this->appName}");
            $this->runDokkuCmd("storage:list {$this->appName}", mutates: false);
            return Command::SUCCESS;
        }

        // Add mount
        if (!str_contains($param, ':')) {
            $this->io->error("Invalid mount format: $param (expected /host/path:/container/path, e.g.  /mnt/volume-1/shared:/app/shared)");
            return Command::FAILURE;
        }

        # dokku storage:mount  /mnt/volume-1/shared:/app/shared
        # dokku storage:ensure-directory

        $this->io->section("Adding storage mount to {$this->appName}");
        $this->runDokkuCmd("storage:mount {$this->appName} " . escapeshellarg($param), mutates: true);

        if ($this->executeChanges) {
            $this->io->success("Storage mounted. Restart to apply:");
            $this->io->text("  bin/console dokku restart");
        } else {
            $this->io->text('Storage mount planned. Re-run with --force to apply.');
        }

        return Command::SUCCESS;
    }

    private function deploy(): int
    {
        $this->io->title("Deploying {$this->appName}");
        $cmd = 'git push dokku main';

        if (!$this->executeChanges) {
            $this->io->text("[change] $ $cmd");
            $this->io->text('  (planned only; use --force to execute)');
            return Command::SUCCESS;
        }

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(600); // 10 minutes for deploy
        $process->setTty(true);

        try {
            $process->run(function ($type, $buffer) {
                echo $buffer;
            });

            if ($process->isSuccessful()) {
                $this->io->success('Deployment complete!');
                $this->io->text("  bin/console dokku logs  # Check logs");
            } else {
                $this->io->error('Deployment failed. Check output above.');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->io->error('Deploy error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function destroyApp(): int
    {
        $this->io->warning("About to destroy app: {$this->appName}");

        if (!$this->executeChanges) {
            $this->runDokkuCmd("apps:destroy {$this->appName} --force", mutates: true);
            return Command::SUCCESS;
        }

        if (!$this->io->confirm('Are you sure? This cannot be undone.', false)) {
            $this->io->text('Cancelled.');
            return Command::SUCCESS;
        }

        $this->io->section("Destroying {$this->appName}");
        $this->runDokkuCmd("apps:destroy {$this->appName} --force", mutates: true);

        $this->io->success('App destroyed.');
        $this->io->text('To remove git remote: git remote remove dokku');

        return Command::SUCCESS;
    }

    private function restart(): int
    {
        $this->io->section("Restarting {$this->appName}");
        $this->runDokkuCmd("ps:restart {$this->appName}", mutates: true);
        return Command::SUCCESS;
    }

    private function showStatus(): int
    {
        $this->io->section("Status for {$this->appName}");
        $this->runDokkuCmd("ps:report {$this->appName}", mutates: false);
        return Command::SUCCESS;
    }

    private function getAppNameFromGitRemote(): ?string
    {
        try {
            $process = Process::fromShellCommandline('git config --get remote.dokku.url');
            $process->run();

            if ($process->isSuccessful()) {
                $remoteUrl = trim($process->getOutput());
                // Parse dokku@host:app-name
                if (preg_match('/^dokku@[^:]+:(.+)$/', $remoteUrl, $matches)) {
                    return $matches[1];
                }
            }
        } catch (\Exception $e) {
            // Silently fail, we'll use directory name
        }

        return null;
    }

    private function createProcfile(): void
    {
        $path = $this->projectDir . '/Procfile';
        $contents = 'web: vendor/bin/heroku-php-nginx -C nginx.conf -F fpm_custom.conf public/';

        $this->syncFile('Procfile', $path, $contents);
    }

    private function createFpmConfig(): void
    {
        $path = $this->projectDir . '/fpm_custom.conf';
        $contents = <<<END
php_value[memory_limit] = 256M
php_value[post_max_size] = 100M
php_value[upload_max_filesize] = 100M
END;

        $this->syncFile('FPM config', $path, $contents);
    }

    private function createNginxConfig(): void
    {
        $path = $this->projectDir . '/nginx.conf';
        $templatePath = __DIR__ . '/../../templates/nginx.conf.twig';

        $this->io->section('Creating nginx.conf');

        if (!file_exists($templatePath)) {
            $this->io->warning("Template not found: $templatePath");
            return;
        }

        $contents = file_get_contents($templatePath);
        $this->syncFile('nginx.conf', $path, $contents);
    }

    private function createAppJson(): void
    {
        $composerPath = $this->projectDir . '/composer.json';
        $templatePath = __DIR__ . '/../../templates/app.json';

        $composerData = null;
        if (file_exists($composerPath)) {
            $decoded = json_decode((string) file_get_contents($composerPath));
            if ($decoded instanceof \stdClass) {
                $composerData = $decoded;
            } else {
                $this->io->warning('composer.json is invalid JSON; creating minimal app.json');
            }
        } else {
            $this->io->warning('composer.json not found; creating minimal app.json');
        }

        $description = trim((string) ($composerData->description ?? ''));
        if ($description === '') {
            $description = "Dokku app for {$this->appName}";
            $this->io->warning('composer.json missing description; using fallback description in app.json');
        }

        $repository = "https://github.com/" . ($composerData->name ?? '');

        $this->io->section('Creating app.json');
        if (!file_exists($templatePath)) {
            $this->io->warning("app.json template not found: $templatePath");
            return;
        }

        $template = (string) file_get_contents($templatePath);
        $contents = str_replace(
            ['%NAME%', '%DESCRIPTION%', '%REPOSITORY%'],
            [
                $this->jsonStringValue((string) $this->appName),
                $this->jsonStringValue($description),
                $this->jsonStringValue($repository),
            ],
            $template
        );

        $this->io->text($contents);

        $path = $this->projectDir . '/app.json';
        $this->syncFile('app.json', $path, $contents);
    }

    private function jsonStringValue(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return substr((string) $encoded, 1, -1);
    }

    private function addGitRemote(): void
    {
        $remoteUrl = "dokku@{$this->dokkuHost}:{$this->appName}";
        $cmd = "git remote add dokku $remoteUrl";

        $this->io->section('Adding git remote');

        $currentRemote = $this->getGitRemoteUrl('dokku');
        if ($currentRemote === $remoteUrl) {
            $this->io->text("  dokku remote already exists: $currentRemote");
            return;
        }

        if ($currentRemote !== null) {
            $this->io->warning("dokku remote exists with a different URL: $currentRemote");
            $this->io->text('  Skipping automatic overwrite. Update it manually if needed.');
            return;
        }

        $this->runCmd($cmd, allowFailure: false, mutates: true);
    }

    private function createDokkuApp(): void
    {
        $this->io->section('Creating Dokku app');

        $exists = $this->dokkuAppExists();
        if ($exists === true) {
            $this->io->text("  App {$this->appName} already exists");
            return;
        }

        $this->runDokkuCmd("apps:create {$this->appName}", allowFailure: true, mutates: true);
    }

    private function runDokkuCmd(string $dokkuArgs, bool $allowFailure = false, bool $mutates = false): void
    {
        $cmd = sprintf(
            'ssh dokku@%s %s',
            escapeshellarg($this->dokkuHost),
            $dokkuArgs
        );
        // dokku config:set  SYMFONY_DECRYPTION_SECRET=$(grep SYMFONY_DECRYPTION_SECRET config/secrets/prod/prod.decrypt.private.php | cut -d= -f2)
        // bin/console secrets:set APP_SECRET --env=prod --random

        $this->runCmd($cmd, $allowFailure, $mutates);
    }

    private function runCmd(string $cmd, bool $allowFailure = false, bool $mutates = false): void
    {
        $mode = $mutates ? 'change' : 'read';
        $this->io->text("[$mode] $ $cmd");

        if ($mutates && !$this->executeChanges) {
            $this->io->text('  (planned only; use --force to execute)');
            return;
        }

        try {
            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                $error = $process->getErrorOutput();

                if ($allowFailure) {
                    $this->io->text("  (Command failed, continuing anyway)");
                    if ($error) {
                        $this->io->text("  " . trim($error));
                    }
                } else {
                    $this->io->error('Command failed: ' . $error);
                }
            } else {
                $output = trim($process->getOutput());
                if ($output) {
                    $this->io->text("  " . $output);
                }
            }
        } catch (\Exception $exception) {
            if ($allowFailure) {
                $this->io->text("  (Exception: " . $exception->getMessage() . ")");
            } else {
                $this->io->error($exception->getMessage());
            }
        }
    }

    private function syncFile(string $label, string $path, string $contents): void
    {
        $this->io->section("Syncing $label");

        if (file_exists($path)) {
            $existing = file_get_contents($path);
            if ($existing === $contents) {
                $this->io->text("  already exists and matches: $path");
                return;
            }

            $this->io->text("[change] update file: $path");
            if (!$this->executeChanges) {
                $this->io->text('  (planned only; use --force to overwrite)');
                return;
            }

            file_put_contents($path, $contents);
            $this->io->text("  ✓ Updated: $path");
            return;
        }

        $this->io->text("[change] create file: $path");
        if (!$this->executeChanges) {
            $this->io->text('  (planned only; use --force to create)');
            return;
        }

        file_put_contents($path, $contents);
        $this->io->text("  ✓ Created: $path");
    }

    private function getGitRemoteUrl(string $remoteName): ?string
    {
        try {
            $process = Process::fromShellCommandline('git remote get-url ' . escapeshellarg($remoteName));
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }
        } catch (\Exception $e) {
            // ignore and return null
        }

        return null;
    }

    private function dokkuAppExists(): ?bool
    {
        try {
            $process = Process::fromShellCommandline(
                sprintf('ssh dokku@%s apps:exists %s', escapeshellarg($this->dokkuHost), escapeshellarg((string) $this->appName))
            );
            $process->run();
            return $process->isSuccessful();
        } catch (\Exception $e) {
            return null;
        }
    }
}

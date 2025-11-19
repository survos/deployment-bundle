<?php

namespace Survos\DeploymentBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

use function Symfony\Component\String\u;

#[AsCommand('dokku', 'Manage Dokku deployments')]
final class DokkuCommand extends Command
{
    private SymfonyStyle $io;
    private bool $dryRun = false;
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
        #[Argument(description: 'Action: bootstrap|logs|config|storage|deploy|destroy')] ?string $action = 'bootstrap',
        #[Argument(description: 'Action parameter (e.g. mount path for storage, KEY=value for config)')] ?string $param = null,
        #[Option(description: "App name (auto-detected from git remote or directory)")] ?string $app = null,
        #[Option(description: "Show what would happen without executing")] bool $dry = false,
        #[Option(description: "Dokku server host")] string $host = 'ssh.survos.com'
    ): int {
        $this->io = $io;
        $this->dryRun = $dry;
        $this->dokkuHost = $host;

        // Try to determine app name from git remote first, then fall back to directory name
        if (!$app) {
            $app = $this->getAppNameFromGitRemote();
        }
        $this->appName = $app ?? basename($this->projectDir);

        if ($this->dryRun) {
            $io->note('DRY RUN MODE - No commands will be executed');
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
            '  --dry                   Show commands without executing',
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

        if ($this->dryRun) {
            $this->io->success('Dry run complete. Run without --dry to execute.');
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
        $this->runDokkuCmd("logs {$this->appName} --num 100");

        $this->io->newLine();
        $this->io->text('To follow logs in real-time:');
        $this->io->text("  ssh dokku@{$this->dokkuHost} logs {$this->appName} -t");

        return Command::SUCCESS;
    }

    private function handleConfig(?string $param): int
    {
        if (!$param) {
            // Show all config
            $this->io->section("Config for {$this->appName}");
            $this->runDokkuCmd("config:show {$this->appName}");
            return Command::SUCCESS;
        }

        // Set config
        if (!str_contains($param, '=')) {
            $this->io->error("Invalid format: $param (expected KEY=value)");
            return Command::FAILURE;
        }

        $this->io->section("Setting config for {$this->appName}");
        $this->runDokkuCmd("config:set {$this->appName} " . escapeshellarg($param));

        return Command::SUCCESS;
    }

    private function handleStorage(?string $param): int
    {
        if (!$param) {
            // List storage
            $this->io->section("Storage mounts for {$this->appName}");
            $this->runDokkuCmd("storage:list {$this->appName}");
            return Command::SUCCESS;
        }

        // Add mount
        if (!str_contains($param, ':')) {
            $this->io->error("Invalid mount format: $param (expected /host/path:/container/path)");
            return Command::FAILURE;
        }

        $this->io->section("Adding storage mount to {$this->appName}");
        $this->runDokkuCmd("storage:mount {$this->appName} " . escapeshellarg($param));

        $this->io->success("Storage mounted. Restart to apply:");
        $this->io->text("  bin/console dokku restart");

        return Command::SUCCESS;
    }

    private function deploy(): int
    {
        $this->io->title("Deploying {$this->appName}");

        if ($this->dryRun) {
            $this->io->text('Would run: git push dokku main');
            return Command::SUCCESS;
        }

        $process = Process::fromShellCommandline('git push dokku main');
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

        if (!$this->io->confirm('Are you sure? This cannot be undone.', false)) {
            $this->io->text('Cancelled.');
            return Command::SUCCESS;
        }

        $this->io->section("Destroying {$this->appName}");
        $this->runDokkuCmd("apps:destroy {$this->appName} --force");

        $this->io->success('App destroyed.');
        $this->io->text('To remove git remote: git remote remove dokku');

        return Command::SUCCESS;
    }

    private function restart(): int
    {
        $this->io->section("Restarting {$this->appName}");
        $this->runDokkuCmd("ps:restart {$this->appName}");
        return Command::SUCCESS;
    }

    private function showStatus(): int
    {
        $this->io->section("Status for {$this->appName}");
        $this->runDokkuCmd("ps:report {$this->appName}");
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

        $this->io->section('Creating Procfile');
        $this->io->text($contents);

        if (!$this->dryRun) {
            file_put_contents($path, $contents);
            $this->io->text("✓ Written to: $path");
        }
    }

    private function createFpmConfig(): void
    {
        $path = $this->projectDir . '/fpm_custom.conf';
        $contents = <<<END
php_value[memory_limit] = 256M
php_value[post_max_size] = 100M
php_value[upload_max_filesize] = 100M
END;

        $this->io->section('Creating FPM config');
        $this->io->text($contents);

        if (!$this->dryRun) {
            file_put_contents($path, $contents);
            $this->io->text("✓ Written to: $path");
        }
    }

    private function createNginxConfig(): void
    {
        $path = $this->projectDir . '/nginx.conf';
        $templatePath = __DIR__ . '/../../templates/nginx.conf.twig';
        assert(file_exists($templatePath), $templatePath . ' does not exist');

        $this->io->section('Creating nginx.conf');

        if (!$this->dryRun) {
            if (file_exists($templatePath)) {
                $contents = file_get_contents($templatePath);
                file_put_contents($path, $contents);
                $this->io->text("✓ Written to: $path");
            } else {
                $this->io->warning("Template not found: $templatePath");
            }
        } else {
            $this->io->text("Would copy from: $templatePath");
        }
    }

    private function createAppJson(): void
    {
        $composerPath = $this->projectDir . '/composer.json';

        if (!file_exists($composerPath)) {
            $this->io->warning('composer.json not found, skipping app.json creation');
            return;
        }

        $composerData = json_decode(file_get_contents($composerPath));

        if (!isset($composerData->description)) {
            $this->io->warning('composer.json missing description. Run composer validate and composer normalize first!');
            return;
        }

        $app = [
            'name' => $this->appName,
            'description' => $composerData->description,
            'repository' => "https://github.com/" . ($composerData->name ?? ''),
        ];

        $this->io->section('Creating app.json');
        $this->io->text(json_encode($app, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (!$this->dryRun) {
            $path = $this->projectDir . '/app.json';
            file_put_contents($path, json_encode($app, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->io->text("✓ Written to: $path");
        }
    }

    private function addGitRemote(): void
    {
        $remoteUrl = "dokku@{$this->dokkuHost}:{$this->appName}";
        $cmd = "git remote add dokku $remoteUrl";

        $this->io->section('Adding git remote');
        $this->runCmd($cmd, allowFailure: true); // Allow failure if remote already exists
    }

    private function createDokkuApp(): void
    {
        $this->io->section('Creating Dokku app');
        $this->runDokkuCmd("apps:create {$this->appName}", allowFailure: true);
    }

    private function runDokkuCmd(string $dokkuArgs, bool $allowFailure=false): void
    {
        $cmd = sprintf(
            'ssh dokku@%s %s',
            escapeshellarg($this->dokkuHost),
            $dokkuArgs
        );

        $this->runCmd($cmd, $allowFailure);
    }

    private function runCmd(string $cmd, bool $allowFailure = false): void
    {
        $this->io->text("$ $cmd");

        if ($this->dryRun) {
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
}

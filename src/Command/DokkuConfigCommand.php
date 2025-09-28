<?php

namespace Survos\DeploymentBundle\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Twig\Environment;

//use Zenstruck\Console\RunsCommands;
//use Zenstruck\Console\RunsProcesses;
use function Symfony\Component\String\u;

#[AsCommand('dokku:config', 'Configure a project for deployment on dukku')]
final class DokkuConfigCommand extends Command
{
    //use RunsCommands;
    //use RunsProcesses;
    private bool $force = false;
    private SymfonyStyle $io;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] private string $projectDir,
        ?string                                                 $name = null
    )
    {
        parent::__construct($name);
    }

    public function __invoke(
        SymfonyStyle                                                           $io,
        #[Argument(description: 'the repo prefix, e.g. barcode-demo')] ?string $name = null,
        #[Option("actually run the dokku commands")] ?bool                     $force = null
    ): void
    {

        $app = $name;
        $cmd = 'export NVM_DIR="$HOME/.nvm"; '
            . '[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"; '
            . 'dokku config ' . escapeshellarg($app);

        $process = new Process(['bash', '-lc', $cmd]);
        $process->run();

        if (!$process->isSuccessful()) {
            dd($process->getErrorOutput());
        }

        $io->writeln($process->getOutput());

        $this->force = $force;
        $this->io = $io;
        // should this be a maker bundle?
        $procfileContents = <<< END
web:  vendor/bin/heroku-php-nginx -C nginx.conf  -F fpm_custom.conf public/
END;
        file_put_contents($this->projectDir . '/Procfile', $procfileContents);
        file_put_contents($this->projectDir . '/fpm_custom.conf', <<<END
php_value[memory_limit] = 256M
php_value[post_max_size] = 100M
php_value[upload_max_filesize] = 100M
END
        );

        $composerData = json_decode(file_get_contents('composer.json'));
        assert($composerData->description, "run composer validate and composer normalize first!");
        if (!$name) {
            $name = u($composerData->name)->after('/')->toString();
        }

        $app = json_decode(file_get_contents($x = __DIR__ . './../../templates/app.json'));
        $app->name = $name;
        $app->description = $composerData->description;
        $app->repository = "https://github.com/" . $composerData->name;

        file_put_contents($this->projectDir . '/app.json', json_encode($app, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE));

        $conf = file_get_contents(__DIR__ . './../../templates/nginx.conf.twig');

        file_put_contents($this->projectDir . '/nginx.conf', $conf);

        $io->success('dokku:config files written.');

        $this->runCmd($cmd = 'git remote add dokku dokku@ssh.survos.com:' . $name);
        $this->runCmd($cmd = 'dokku apps:create');

//        $this->runCmd($cmd = 'dokku config:set APP_ENV=prod --no-restart');
//        $this->runCmd($cmd = 'dokku config:set REDIS=redis://dokku.survos.com:6379 --no-restart');
//        $this->runCmd($cmd = 'bin/console secrets:generate-keys --env=prod');
//        $this->runCmd($cmd = 'bin/console secrets:generate-keys');
//        $this->runCmd($cmd = 'bin/console secret:set APP_SECRET -r --env=prod');
//        $this->runCmd($cmd = 'bin/console secret:set APP_SECRET -r --env=dev');
        if ($force) {
            /** @phpstan-ignore-next-line */
            $secret = base64_encode(require "config/secrets/prod/prod.decrypt.private.php");
            $this->runCmd($cmd = "dokku config:set SYMFONY_DECRYPTION_SECRET=$secret APP_ENV=prod --no-restart");
        }


        $io->text("now run dokku config to see the variables. @todo: add secrets");
    }

    private function runCmd(string $cmd): void
    {

        $this->io->writeln($cmd);
        $io = $this->io;
        if ($this->force) {
            try {
                $process = new Process(['ls', '-la', '/tmp']);
// or for a shell command:
                $process = Process::fromShellCommandline('tar -xzf archive.tar.gz -C /destination');

                $process->run();

                if (!$process->isSuccessful()) {
                    $io->error('Command failed: ' . $process->getErrorOutput());
                } else {
                    $output = $process->getOutput();
                    $io->text($output);
                }

            } catch (\Exception $exception) {
                $this->io->error($cmd);
            }
        }
    }
}

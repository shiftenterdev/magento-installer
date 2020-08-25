<?php

namespace Magento\Installer\Console;

use GuzzleHttp\Client;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use ZipArchive;

class NewMagentoCommand extends Command
{

    const APP_LATEST_VERSION = '2.4.0';
    const DOWNLOAD_URL = 'https://github.com/magento/magento2/archive/';

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('new')
            ->setDescription('Create a new Magento application')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addArgument('version', InputArgument::REQUIRED)
            ->addOption('sample-data', 's', InputOption::VALUE_NONE, 'Installs the Magento with sample data')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Execute the command.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (version_compare(PHP_VERSION, '7.3.0', '<')) {
            throw new RuntimeException('The Magento installer requires PHP 7.3.0 or greater. Please use "composer create-project ...." command instead.');
        }

//        if (!extension_loaded('zip')) {
//            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
//        }

        $name = $input->getArgument('name');

        $version = $input->getArgument('version');

        $application_version = $version ?? self::APP_LATEST_VERSION;

        $directory =  $name;

        if (!$input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        $output->writeln('<info>Application\'s files are pouring...</info>');

        $this->download($tarFile = $this->makeFilename(), $application_version)
            ->extract($tarFile, $directory)
            ->prepareWritableDirectories($directory, $output)
            ->cleanUp($tarFile);

        $composer = $this->findComposer();

        $commands = [
            $composer . ' install --no-scripts',
            $composer . ' run-script post-root-package-install',
            $composer . ' run-script post-create-project-cmd',
            $composer . ' run-script post-autoload-dump',
        ];

        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                return $value . ' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(function ($value) {
                return $value . ' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), $directory, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('Warning: ' . $e->getMessage());
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        if ($process->isSuccessful()) {
            $output->writeln('<comment>Your Magento Application ready! Make your shop awesome.</comment>');
        }

        return 0;
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param string $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Magento Application already exists!');
        }
    }

    /**
     * Clean-up the Tar file.
     *
     * @param $tarFile
     * @return $this
     */
    protected function cleanUp($tarFile)
    {
        @chmod($tarFile, 0777);

        @unlink($tarFile);

        return $this;
    }

    /**
     * Make sure the storage and bootstrap cache directories are writable.
     *
     * @param string $appDirectory
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return $this
     */
    protected function prepareWritableDirectories($appDirectory, OutputInterface $output)
    {
        $filesystem = new Filesystem;

        try {
            $filesystem->chmod($appDirectory . DIRECTORY_SEPARATOR . 'pub/static', 0755, 0000, true);
            $filesystem->chmod($appDirectory . DIRECTORY_SEPARATOR . 'generated', 0755, 0000, true);
            $filesystem->chmod($appDirectory . DIRECTORY_SEPARATOR . 'var', 0755, 0000, true);
        } catch (IOExceptionInterface $e) {
            $output->writeln('<comment>You should verify that the "var", "pub/static" & "generated" directories are writable.</comment>');
        }

        return $this;
    }

    /**
     * Extract the Tar file into the given directory.
     *
     * @param $archiveFile
     * @param string $directory
     * @return $this
     */
    protected function extract($archiveFile, $directory)
    {
        $archive = new \PharData($archiveFile);

        $archive->extractTo(getcwd());

        $basename = $archive->getBasename();

        rename($basename,$directory);

        return $this;
    }

    /**
     * Download the temporary Tar to the given file.
     *
     * @param $tarFile
     * @param string $version
     * @return $this
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function download($tarFile, $version = self::APP_LATEST_VERSION)
    {
        $filename = $version . '.tar.gz';

        $url = self::DOWNLOAD_URL . $filename;

        $response = (new Client)
            ->request('GET', $url, ['progress' => function (
                $downloadTotal,
                $downloadedBytes
            ) {
                $D = number_format($downloadedBytes / 1024 / 1024, 2);
                $T = number_format($downloadTotal / 1024 / 1024, 2);
                $P = 0 . ' %';
                if ($T != 0) {
                    $P = number_format($D / $T * 100, 2) . ' %';
                    echo $D . 'MB / ' . $T . "MB | " . $P . "\r";
                }else {
                    echo $D . 'MB / ' . "? MB | " . $P . "\r";
                }

            },]);

        file_put_contents($tarFile, $response->getBody());

        return $this;
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd() . '/magento_' . md5(time() . uniqid()) . '.tar.gz';
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        $composerPath = getcwd() . '/composer.phar';

        if (file_exists($composerPath)) {
            return '"' . PHP_BINARY . '" ' . $composerPath;
        }

        return 'composer';
    }

}

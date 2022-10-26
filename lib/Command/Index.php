<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Command;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Files_External\Service\GlobalStoragesService;
use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\TimelineWrite;
use OCP\Encryption\IManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\StorageNotAvailableException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Index extends Command
{
    /** @var ?GlobalStoragesService */
    protected $globalService;

    /** @var int[][] */
    protected array $sizes;

    protected IUserManager $userManager;
    protected IRootFolder $rootFolder;
    protected IPreview $preview;
    protected IConfig $config;
    protected OutputInterface $output;
    protected IManager $encryptionManager;
    protected IDBConnection $connection;
    protected Connection $connectionForSchema;
    protected TimelineWrite $timelineWrite;

    // Stats
    private int $nProcessed = 0;
    private int $nSkipped = 0;
    private int $nInvalid = 0;

    // Helper for the progress bar
    private int $previousLineLength = 0;

    public function __construct(
        IRootFolder $rootFolder,
        IUserManager $userManager,
        IPreview $preview,
        IConfig $config,
        IManager $encryptionManager,
        IDBConnection $connection,
        Connection $connectionForSchema,
        ContainerInterface $container
    ) {
        parent::__construct();

        $this->userManager = $userManager;
        $this->rootFolder = $rootFolder;
        $this->preview = $preview;
        $this->config = $config;
        $this->encryptionManager = $encryptionManager;
        $this->connection = $connection;
        $this->connectionForSchema = $connectionForSchema;
        $this->timelineWrite = new TimelineWrite($connection, $preview);

        try {
            $this->globalService = $container->get(GlobalStoragesService::class);
        } catch (ContainerExceptionInterface $e) {
            $this->globalService = null;
        }
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:index')
            ->setDescription('Generate photo entries')
            ->addOption(
                'refresh',
                'f',
                InputOption::VALUE_NONE,
                'Refresh existing entries'
            )
            ->addOption(
                'clear',
                null,
                InputOption::VALUE_NONE,
                'Clear existing index before creating a new one (SLOW)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Add missing indices
        $output->writeln('Checking database indices');
        \OCA\Memories\Db\AddMissingIndices::run(new SchemaWrapper($this->connectionForSchema), $this->connectionForSchema);

        // Print mime type support information
        $output->writeln("\nMIME Type support:");
        $mimes = array_merge(Application::IMAGE_MIMES, Application::VIDEO_MIMES);
        $someUnsupported = false;
        foreach ($mimes as &$mimeType) {
            if ($this->preview->isMimeSupported($mimeType)) {
                $output->writeln("  {$mimeType}: supported");
            } else {
                $output->writeln("  {$mimeType}: <error>not supported</error>");
                $someUnsupported = true;
            }
        }

        // Print file type support info
        if ($someUnsupported) {
            $output->writeln("\nSome file types are not supported by your preview provider.\nPlease see https://github.com/pulsejet/memories/wiki/File-Type-Support\n");
        } else {
            $output->writeln("\nAll file types are supported by your preview provider.\n");
        }

        // Get options and arguments
        $refresh = $input->getOption('refresh') ? true : false;
        $clear = $input->getOption('clear') ? true : false;

        // Clear index if asked for this
        if ($clear && $input->isInteractive()) {
            $output->write('Are you sure you want to clear the existing index? (y/N): ');
            $answer = trim(fgets(STDIN));
            if ('y' !== $answer) {
                $output->writeln('Aborting');

                return 1;
            }
        }
        if ($clear) {
            $this->timelineWrite->clear();
            $output->writeln('Cleared existing index');
        }

        // Run with the static process
        try {
            \OCA\Memories\Exif::ensureStaticExiftoolProc();

            return $this->executeWithOpts($output, $refresh);
        } catch (\Exception $e) {
            error_log('FATAL: '.$e->getMessage());

            return 1;
        } finally {
            \OCA\Memories\Exif::closeStaticExiftoolProc();
        }
    }

    protected function executeWithOpts(OutputInterface $output, bool &$refresh): int
    {
        // Refuse to run without exiftool
        if (!$this->testExif()) {
            error_log('FATAL: exiftool could not be executed or test failed');
            error_log('Make sure you have perl 5 installed in PATH');

            return 1;
        }

        // Time measurement
        $startTime = microtime(true);

        if ($this->encryptionManager->isEnabled()) {
            error_log('FATAL: Encryption is enabled. Aborted.');

            return 1;
        }
        $this->output = $output;

        $this->userManager->callForSeenUsers(function (IUser &$user) use (&$refresh) {
            $this->generateUserEntries($user, $refresh);
        });

        // Show some stats
        $endTime = microtime(true);
        $execTime = (int) (($endTime - $startTime) * 1000) / 1000;
        $nTotal = $this->nInvalid + $this->nSkipped + $this->nProcessed;
        $this->output->writeln('==========================================');
        $this->output->writeln("Checked {$nTotal} files in {$execTime} sec");
        $this->output->writeln($this->nInvalid.' not valid media items');
        $this->output->writeln($this->nSkipped.' skipped because unmodified');
        $this->output->writeln($this->nProcessed.' (re-)processed');
        $this->output->writeln('==========================================');

        return 0;
    }

    /** Make sure exiftool is available */
    private function testExif()
    {
        $testfile = __DIR__.'/../../exiftest.jpg';
        $stream = fopen($testfile, 'r');
        if (!$stream) {
            error_log("Couldn't open Exif test file {$testfile}");

            return false;
        }

        $exif = null;

        try {
            $exif = \OCA\Memories\Exif::getExifFromStream($stream);
        } catch (\Exception $e) {
            error_log("Couldn't read Exif data from test file: ".$e->getMessage());

            return false;
        } finally {
            fclose($stream);
        }

        if (!$exif) {
            error_log('Got blank Exif data from test file');

            return false;
        }

        if ('2004:08:31 19:52:58' !== $exif['DateTimeOriginal']) {
            error_log('Got unexpected Exif data from test file');

            return false;
        }

        return true;
    }

    private function generateUserEntries(IUser &$user, bool &$refresh): void
    {
        \OC_Util::tearDownFS();
        \OC_Util::setupFS($user->getUID());

        $uid = $user->getUID();
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $this->parseFolder($userFolder, $refresh);
        if ($this->previousLineLength) {
            $this->output->write("\r".str_repeat(' ', $this->previousLineLength)."\r");
        }
    }

    private function parseFolder(Folder &$folder, bool &$refresh): void
    {
        try {
            $folderPath = $folder->getPath();

            // Respect the '.nomedia' file. If present don't traverse the folder
            if ($folder->nodeExists('.nomedia')) {
                $this->output->writeln('Skipping folder '.$folderPath.' because of .nomedia file');
                $this->previousLineLength = 0;

                return;
            }

            // Clear previous line and write new one
            $line = 'Scanning folder '.$folderPath;
            if ($this->previousLineLength) {
                $this->output->write("\r".str_repeat(' ', $this->previousLineLength)."\r");
            }
            $this->output->write($line."\r");
            $this->previousLineLength = \strlen($line);

            $nodes = $folder->getDirectoryListing();

            foreach ($nodes as &$node) {
                if ($node instanceof Folder) {
                    $this->parseFolder($node, $refresh);
                } elseif ($node instanceof File) {
                    $this->parseFile($node, $refresh);
                }
            }
        } catch (StorageNotAvailableException $e) {
            $this->output->writeln(sprintf(
                '<error>Storage for folder folder %s is not available: %s</error>',
                $folder->getPath(),
                $e->getHint()
            ));
        }
    }

    private function parseFile(File &$file, bool &$refresh): void
    {
        $res = $this->timelineWrite->processFile($file, $refresh);
        if (2 === $res) {
            ++$this->nProcessed;
        } elseif (1 === $res) {
            ++$this->nSkipped;
        } else {
            ++$this->nInvalid;
        }
    }
}

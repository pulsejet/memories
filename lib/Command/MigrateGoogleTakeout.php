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

use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Exif;
use OCA\Memories\Service;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateGoogleTakeout extends Command
{
    protected const MIGRATOR_VERSION = 3;
    protected const MIGRATED_KEY = 'memoriesMigratorVersion';

    protected OutputInterface $output;
    protected InputInterface $input;

    // Stats
    private int $nProcessed = 0;

    private array $mimeTypes = [];

    public function __construct(
        protected IRootFolder $rootFolder,
        protected IUserManager $userManager,
        protected IConfig $config,
        protected IDBConnection $connection,
        protected ITempManager $tempManager,
        protected TimelineWrite $timelineWrite,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:migrate-google-takeout')
            ->setDescription('Migrate JSON metadata from Google Takeout')
            ->addOption('override', 'o', null, 'Override existing EXIF metadata')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Migrate only for the specified user')
            ->addOption('folder', 'f', InputOption::VALUE_REQUIRED, 'Migrate only for the specified folder')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $this->input = $input;
        $this->mimeTypes = Exif::allowedEditMimetypes();

        // Provide ample warnings
        if ($input->isInteractive()) {
            $output->writeln('This command will migrate JSON metadata from Google Takeout to EXIF metadata.');
            $output->writeln('Only metadata that is missing from EXIF will be migrated, unless --override is specified.');
            $output->writeln('It will also update the JSON files to mark them as migrated.');
            $output->writeln('Make sure you have a backup of your originals before running this command.');
            $output->writeln('Also make sure exiftool is working beforehand by running memories:index on some files.');
            $output->write('Are you sure you want to continue? (y/N): ');
            $answer = trim(fgets(STDIN));
            if ('y' !== $answer) {
                $output->writeln('Aborting');

                return 1;
            }
        }

        // Start static exif process
        Exif::ensureStaticExiftoolProc();
        Service\BinExt::testExiftool(); // throws

        // Call migration for each user
        if ($input->getOption('user')) {
            $user = $this->userManager->get($input->getOption('user'));
            if (!$user) {
                $output->writeln("<error>User {$input->getOption('user')} does not exist</error>");

                return 1;
            }

            $this->migrateUser($user);
        } else {
            $this->userManager->callForSeenUsers(function (IUser $user) {
                $this->migrateUser($user);
            });
        }

        // Print statistics
        $output->writeln("\nMigrated JSON metadata from {$this->nProcessed} files");

        return 0;
    }

    protected function migrateUser(IUser $user): void
    {
        $uid = $user->getUID();
        $this->output->writeln("Migrating user {$uid}");

        // Get user's root folder
        \OC_Util::tearDownFS();
        \OC_Util::setupFS($uid);
        $folder = $this->rootFolder->getUserFolder($uid);

        // Check if we need to migrate a specific folder
        if ($path = $this->input->getOption('folder')) {
            try {
                $folder = $folder->get($path);
                if (!$folder instanceof Folder) {
                    throw new \Exception();
                }
            } catch (\Exception) {
                $this->output->writeln("<error>Folder {$path} does not exist</error>");

                return;
            }
        }

        // Iterate all files
        $this->migrateFolder($folder);
    }

    protected function migrateFolder(Folder $folder): void
    {
        // Check for .nomedia
        if ($folder->nodeExists('.nomedia') || $folder->nodeExists('.nomemories')) {
            return;
        }

        // Get all nodes in this folder
        $nodes = $folder->getDirectoryListing();
        $path = $folder->getPath();
        $this->output->writeln("Scanning folder {$path}");

        foreach ($nodes as $i => $node) {
            if ($node instanceof Folder) {
                $this->migrateFolder($node);
            } elseif ($node instanceof File) {
                try {
                    $this->migrateFile($node);
                } catch (\Exception $e) {
                    $this->output->writeln("<error>Error migrating file {$node->getPath()}: {$e->getMessage()}</error>");
                }
            }
        }
    }

    protected function migrateFile(File $file): void
    {
        // Check if this is a supported file
        if (!\in_array($file->getMimeType(), $this->mimeTypes, true)) {
            return;
        }

        // Check for existence of JSON metadata
        $path = $file->getPath();
        $json = [];

        /** @var \OCP\Files\File */
        $jsonFile = null;

        try {
            $jsonPath = $path.'.json';

            /** @var \OCP\Files\File */
            $jsonFile = $this->rootFolder->get($jsonPath);
            if (!$jsonFile->isReadable() || \OCP\Files\FileInfo::TYPE_FOLDER === $jsonFile->getType()) {
                return;
            }

            $json = json_decode($jsonFile->getContent(), true);
        } catch (\OCP\Files\NotFoundException $e) {
            return;
        } catch (\Exception $e) {
            $this->output->writeln("<error>Error while reading JSON metadata for {$path}: {$e->getMessage()}</error>");

            return;
        }

        // Check if JSON metadata is valid
        // For now, check if it at least has either title or url
        if (!isset($json['title']) && !isset($json['url'])) {
            $this->output->writeln("<error>JSON metadata for {$path} is invalid, skipping</error>");

            return;
        }

        // Check if JSON metadata is already migrated
        if (isset($json[self::MIGRATED_KEY]) && $json[self::MIGRATED_KEY] >= self::MIGRATOR_VERSION) {
            return;
        }

        // Convert Takeout metadata to exiftool JSON format
        $txf = $this->takeoutToExiftoolJson($json);

        // Get current EXIF metadata
        $exif = Exif::getExifFromFile($file);

        // Check if EXIF is blank, which is probably wrong
        if (0 === \count($exif)) {
            $this->output->writeln("<error>EXIF metadata for {$path} is blank, probably an error</error>");

            return;
        }

        // Keep keys that are not in EXIF unless --override is specified
        if (!((bool) $this->input->getOption('override'))) {
            $txf = array_filter($txf, static function ($value, $key) use ($exif) {
                return !isset($exif[$key]);
            }, ARRAY_FILTER_USE_BOTH);

            // The AllDates field does not actually exist but
            // sets DateTimeOriginal or TrackCreateDate
            if (isset($exif['TrackCreateDate']) || isset($exif['DateTimeOriginal'])) {
                unset($txf['AllDates']);
            }
        }

        // Special case: if $txf has both GPSLatitude and GPSLongitude,
        // also specify GPSCoordinates, since videos need this and refs
        if (isset($txf['GPSLatitude'], $txf['GPSLongitude'])) {
            $txf['GPSLatitudeRef'] = $txf['GPSLatitude'];
            $txf['GPSLongitudeRef'] = $txf['GPSLongitude'];
            $txf['GPSCoordinates'] = $txf['GPSLatitude'].', '.$txf['GPSLongitude'];
        }

        // Check if there is anything to write
        if (\count($txf) > 0) {
            $keysWritten = implode(', ', array_keys($txf));
            $this->output->writeln("Writing EXIF metadata for {$path} ({$keysWritten})");

            // Write EXIF metadata
            try {
                Exif::setFileExif($file, $txf);
            } catch (\Exception $e) {
                $this->output->writeln("<error>Error while writing EXIF metadata for {$path}: {$e->getMessage()}</error>");

                return;
            }
        } else {
            $this->output->writeln("No new EXIF metadata to write for {$path}");
        }

        // Mark JSON metadata as migrated
        $json[self::MIGRATED_KEY] = self::MIGRATOR_VERSION;

        // Write JSON metadata
        try {
            $jsonFile->putContent(json_encode($json, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->output->writeln("<error>Error while updating JSON file for {$path}: {$e->getMessage()}</error>");

            return;
        }

        ++$this->nProcessed;
    }

    protected function takeoutToExiftoolJson(array $json): array
    {
        // Helper to get a value from nested JSON
        $get = static function (string $source) use ($json): mixed {
            $keys = array_reverse(explode('.', $source));
            while (\count($keys) > 0) {
                $key = array_pop($keys);
                if (!isset($json[$key])) {
                    return null;
                }
                $json = $json[$key];
            }

            // Check if empty string
            if (\is_string($json) && '' === $json) {
                return null;
            }

            // Check if numeric and zero
            if (is_numeric($json)) {
                if (0.0 === (float) $json) {
                    return null;
                }

                return (float) $json;
            }

            return $json;
        };

        $txf = [];

        // Description
        $txf['Description'] = $get('description');

        // Date/Time
        $epoch = $get('photoTakenTime.timestamp');
        if (is_numeric($epoch)) {
            $date = new \DateTime();
            $date->setTimestamp((int) $epoch);
            $txf['AllDates'] = $date->format('Y:m:d H:i:sO');
        }

        // Location coordinates
        $txf['GPSLatitude'] = $get('geoData.latitude');
        $txf['GPSLongitude'] = $get('geoData.longitude');
        $txf['GPSAltitude'] = $get('geoData.altitude');

        // Remove all null values
        return array_filter($txf, static fn (mixed $value) => null !== $value);
    }
}

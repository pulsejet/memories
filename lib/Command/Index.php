<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Varun Patil <radialapps@gmail.com>
 *
 * @author Varun Patil <radialapps@gmail.com>
 *
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
 *
 */

namespace OCA\BetterPhotos\Command;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Encryption\IManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\StorageNotAvailableException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IUser;
use OCP\IUserManager;
use OCA\Files_External\Service\GlobalStoragesService;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Index extends Command {

	/** @var ?GlobalStoragesService */
	protected $globalService;

	/** @var int[][] */
	protected array $sizes;

	protected IUserManager $userManager;
	protected IRootFolder $rootFolder;
	protected IPreview $previewGenerator;
	protected IConfig $config;
	protected OutputInterface $output;
	protected IManager $encryptionManager;
	protected IDBConnection $connection;
	protected \OCA\BetterPhotos\Util $util;

	public function __construct(IRootFolder $rootFolder,
								IUserManager $userManager,
								IPreview $previewGenerator,
								IConfig $config,
								IManager $encryptionManager,
								IDBConnection $connection,
								ContainerInterface $container) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->rootFolder = $rootFolder;
		$this->previewGenerator = $previewGenerator;
		$this->config = $config;
		$this->encryptionManager = $encryptionManager;
		$this->connection = $connection;
		$this->util = new \OCA\BetterPhotos\Util($previewGenerator, $connection);

		try {
			$this->globalService = $container->get(GlobalStoragesService::class);
		} catch (ContainerExceptionInterface $e) {
			$this->globalService = null;
		}
	}

	protected function configure(): void {
		$this
			->setName('betterphotos:index')
			->setDescription('Generate entries');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->encryptionManager->isEnabled()) {
			$output->writeln('Encryption is enabled. Aborted.');
			return 1;
		}
		$this->output = $output;

        $this->userManager->callForSeenUsers(function (IUser $user) {
            $this->generateUserEntries($user);
        });

		return 0;
	}

	private function generateUserEntries(IUser $user): void {
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($user->getUID());

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$this->parseFolder($user, $userFolder);
	}

	private function parseFolder(IUser $user, Folder $folder): void {
		try {
			$folderPath = $folder->getPath();

			// Respect the '.nomedia' file. If present don't traverse the folder
			// Same for external mounts with previews disabled
			if ($folder->nodeExists('.nomedia')) {
				$this->output->writeln('Skipping folder ' . $folderPath);
				return;
			}

			$this->output->writeln('Scanning folder ' . $folderPath);

			$nodes = $folder->getDirectoryListing();

			foreach ($nodes as $node) {
				if ($node instanceof Folder) {
					$this->parseFolder($user, $node);
				} elseif ($node instanceof File) {
					$this->parseFile($user, $node);
				}
			}
		} catch (StorageNotAvailableException $e) {
			$this->output->writeln(sprintf('<error>Storage for folder folder %s is not available: %s</error>',
				$folder->getPath(),
				$e->getHint()
			));
		}
	}

	private function parseFile(IUser $user, File $file): void {
		$this->output->writeln('Generating entry for ' . $file->getPath() . ' ' . $file->getId());
		$this->util->processFile($user->getUID(), $file, false);
	}
}
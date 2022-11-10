<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Exif;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;

class ApiBase extends Controller
{
    protected IConfig $config;
    protected IUserSession $userSession;
    protected IRootFolder $rootFolder;
    protected IAppManager $appManager;
    protected TimelineQuery $timelineQuery;
    protected TimelineWrite $timelineWrite;
    protected IShareManager $shareManager;
    protected IPreview $previewManager;

    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserSession $userSession,
        IDBConnection $connection,
        IRootFolder $rootFolder,
        IAppManager $appManager,
        IShareManager $shareManager,
        IPreview $preview
    ) {
        parent::__construct(Application::APPNAME, $request);

        $this->config = $config;
        $this->userSession = $userSession;
        $this->connection = $connection;
        $this->rootFolder = $rootFolder;
        $this->appManager = $appManager;
        $this->shareManager = $shareManager;
        $this->previewManager = $preview;
        $this->timelineQuery = new TimelineQuery($connection);
        $this->timelineWrite = new TimelineWrite($connection, $preview);
    }

    /** Get logged in user's UID or throw HTTP error */
    protected function getUid(): string
    {
        $user = $this->userSession->getUser();
        if ($this->getShareToken()) {
            $user = null;
        } elseif (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        return $user ? $user->getUID() : '';
    }

    /** Get the Folder object relevant to the request */
    protected function getRequestFolder()
    {
        // Albums have no folder
        if ($this->request->getParam('album')) {
            return null;
        }

        // Public shared folder
        if ($token = $this->getShareToken()) {
            $share = $this->shareManager->getShareByToken($token)->getNode(); // throws exception if not found
            if (!$share instanceof Folder) {
                throw new \Exception('Share not found or invalid');
            }

            return $share;
        }

        // Anything else needs a user
        $user = $this->userSession->getUser();
        if (null === $user) {
            return null;
        }
        $uid = $user->getUID();

        $folder = null;
        $folderPath = $this->request->getParam('folder');
        $forcedTimelinePath = $this->request->getParam('timelinePath');
        $userFolder = $this->rootFolder->getUserFolder($uid);

        if (null !== $folderPath) {
            $folder = $userFolder->get($folderPath);
        } elseif (null !== $forcedTimelinePath) {
            $folder = $userFolder->get($forcedTimelinePath);
        } else {
            $configPath = Exif::removeExtraSlash(Exif::getPhotosPath($this->config, $uid));
            $folder = $userFolder->get($configPath);
        }

        if (!$folder instanceof Folder) {
            throw new \Exception('Folder not found');
        }

        return $folder;
    }

    /**
     * Get a file with ID from user's folder
     *
     * @param int $fileId
     *
     * @return File|null
     */
    protected function getUserFile(int $id)
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return null;
        }
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // Check for permissions and get numeric Id
        $file = $userFolder->getById($id);
        if (0 === \count($file)) {
            return null;
        }

        // Check if node is a file
        if (!($file[0] instanceof File)) {
            return null;
        }

        return $file[0];
    }

    protected function isRecursive()
    {
        return null === $this->request->getParam('folder');
    }

    protected function isArchive()
    {
        return null !== $this->request->getParam('archive');
    }

    protected function isMonthView()
    {
        return null !== $this->request->getParam('monthView');
    }

    protected function isReverse()
    {
        return null !== $this->request->getParam('reverse');
    }

    protected function getShareToken()
    {
        return $this->request->getParam('folder_share');
    }

    /**
     * Check if albums are enabled for this user.
     */
    protected function albumsIsEnabled(): bool
    {
        return \OCA\Memories\Util::albumsIsEnabled($this->appManager);
    }

    /**
     * Check if tags is enabled for this user.
     */
    protected function tagsIsEnabled(): bool
    {
        return \OCA\Memories\Util::tagsIsEnabled($this->appManager);
    }

    /**
     * Check if recognize is enabled for this user.
     */
    protected function recognizeIsEnabled(): bool
    {
        return \OCA\Memories\Util::recognizeIsEnabled($this->appManager);
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Varun Patil <radialapps@gmail.com>
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

use OCP\App\IAppManager;
use OCP\IConfig;

trait GenericApiControllerUtils
{
    protected IAppManager $appManager;
    protected IConfig $config;

    /** Get logged in user's UID or throw exception */
    protected function getUID(): string
    {
        $user = $this->userSession->getUser();
        if ($this->getShareToken()) {
            $user = null;
        } elseif (null === $user) {
            throw new \Exception('User not logged in');
        }

        return $user ? $user->getUID() : '';
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

    // Check if facerecognition is installed and enabled for this user.
    protected function facerecognitionIsInstalled(): bool
    {
        return \OCA\Memories\Util::facerecognitionIsInstalled($this->appManager);
    }

    /**
     * Check if facerecognition is enabled for this user.
     */
    protected function facerecognitionIsEnabled(): bool
    {
        return \OCA\Memories\Util::facerecognitionIsEnabled($this->config, $this->getUID());
    }

    /**
     * Check if geolocation is enabled for this user.
     */
    protected function placesIsEnabled(): bool
    {
        return \OCA\Memories\Util::placesGISType() > 0;
    }
}

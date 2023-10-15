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
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\TimelineQuery;
use OCP\App\IAppManager;
use OCP\AppFramework\ApiController;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

abstract class GenericApiController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected IConfig $config,
        protected IUserSession $userSession,
        protected IDBConnection $connection,
        protected IRootFolder $rootFolder,
        protected IAppManager $appManager,
        protected LoggerInterface $logger,
        protected TimelineQuery $tq,
        protected FsManager $fs,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }
}

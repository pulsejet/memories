<?php

declare(strict_types=1);

/**
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

namespace OCA\Polaroid\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IUserManager;

class PostWriteListener implements IEventListener {
	private IUserManager $userManager;
    private \OCA\Polaroid\Db\Util $util;

	public function __construct(IDBConnection $connection,
								IUserManager $userManager) {
		$this->userManager = $userManager;
        $this->util = new \OCA\Polaroid\Db\Util($connection);
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeWrittenEvent) &&
		    !($event instanceof NodeTouchedEvent) &&
			!($event instanceof NodeRenamedEvent)) {
			return;
		}

		$node = $event->getNode();
		if ($node instanceof Folder) {
			return;
		}

		$this->util->processFile($node);
	}
}
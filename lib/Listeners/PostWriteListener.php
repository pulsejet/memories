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

namespace OCA\Memories\Listeners;

use \OCA\Memories\Db\TimelineWrite;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IUserManager;

class PostWriteListener implements IEventListener {
    private TimelineWrite $timelineWrite;

    public function __construct(IDBConnection $connection,
                                IUserManager $userManager) {
        $this->userManager = $userManager;
        $this->timelineWrite = new TimelineWrite($connection);
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeWrittenEvent) &&
            !($event instanceof NodeTouchedEvent)) {
            return;
        }

        $node = $event->getNode();
        if ($node instanceof Folder) {
            return;
        }

        // Check the mime type first
        if (!$this->timelineWrite->getFileType($node)) {
            return;
        }

        // Check if a directory at a higher level contains a .nomedia file
        // Do this by getting all the parent folders first, then checking them
        // in reverse order from root to leaf. The rationale is that the
        // .nomedia file is most likely to be in higher level directories.
        $parents = [];
        try {
            $parent = $node->getParent();
            while ($parent) {
                $parents[] = $parent;
                $parent = $parent->getParent();
            }
        }
        catch (\OCP\Files\NotFoundException $e) {
            // This happens when the parent is in the root directory
            // and getParent() is called on it.
        }

        // Traverse the array in reverse order looking for .nomedia
        $parents = array_reverse($parents);
        foreach ($parents as &$parent) {
            if ($parent->nodeExists('.nomedia')) {
                return;
            }
        }

        $this->timelineWrite->processFile($node);
    }
}
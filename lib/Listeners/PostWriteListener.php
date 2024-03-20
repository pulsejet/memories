<?php

declare(strict_types=1);

/**
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

namespace OCA\Memories\Listeners;

use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Service\Index;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class PostWriteListener implements IEventListener
{
    public function __construct(
        private TimelineWrite $tw,
        private LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void
    {
        if (!($event instanceof NodeWrittenEvent)
            && !($event instanceof NodeTouchedEvent)) {
            return;
        }

        $node = $event->getNode();

        // Check the mime type first
        if (!($node instanceof File) || !Index::isSupported($node)) {
            return;
        }

        // Check if a directory at a higher level contains a .nomedia file
        try {
            $parent = $node;

            /** @psalm-suppress RedundantConditionGivenDocblockType */
            while ($parent = $parent->getParent()) {
                if ($parent->nodeExists('.nomedia') || $parent->nodeExists('.nomemories')) {
                    return;
                }
            }
        } catch (\OCP\Files\NotFoundException $e) {
            // This happens when the parent is in the root directory
            // and getParent() is called on it.
        }

        try {
            $this->tw->processFile($node);
        } catch (\Exception $e) {
            $this->logger->error('Write hook failed to index file', [
                'app' => 'memories',
                'path' => $node->getPath(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}

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

use OCA\Memories\Service\Index;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * @template-implements IEventListener<Event>
 */
final class PostWriteListener implements IEventListener
{
    public function __construct(
        private Index $indexer,
    ) {}

    #[\Override]
    public function handle(Event $event): void
    {
        /** @var null|\OCP\Files\Node */
        $node = null;

        if ($event instanceof NodeWrittenEvent
            || $event instanceof NodeTouchedEvent) {
            $node = $event->getNode();
            if (!$node instanceof File) {
                return;
            }
        } elseif ($event instanceof NodeCopiedEvent) {
            $node = $event->getTarget();
            if (!($node instanceof Folder) && !($node instanceof File)) {
                return;
            }
        } else {
            return;
        }

        // Check the mime type first
        if ($node instanceof File && !Index::isSupported($node)) {
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

        if ($node instanceof File) {
            $this->indexer->indexFile($node);
        } elseif ($node instanceof Folder) {
            $this->indexer->indexFolder($node);
        }
    }
}

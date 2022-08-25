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

namespace OCA\Memories\AppInfo;

use OCA\Memories\Listeners\PostWriteListener;
use OCA\Memories\Listeners\PostDeleteListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;

class Application extends App implements IBootstrap {
	public const APPNAME = 'memories';

	public const IMAGE_MIMES = [
		'image/png',
		'image/jpeg',
		'image/heic',
		'image/png',
		'image/tiff',
		// 'image/gif',			// too rarely used for photos
		// 'image/x-xbitmap',	// too rarely used for photos
		// 'image/bmp',			// too rarely used for photos
		// 'image/svg+xml',		// too rarely used for photos
	];

	public const VIDEO_MIMES = [
		'video/mpeg',
		// 'video/ogg',			// too rarely used for photos
		// 'video/webm',		// too rarely used for photos
		'video/mp4',
		// 'video/x-m4v',		// too rarely used for photos
		'video/quicktime',
		'video/x-matroska',
	];

	public function __construct() {
		parent::__construct(self::APPNAME);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(NodeWrittenEvent::class, PostWriteListener::class);
        $context->registerEventListener(NodeTouchedEvent::class, PostWriteListener::class);
        $context->registerEventListener(NodeDeletedEvent::class, PostDeleteListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
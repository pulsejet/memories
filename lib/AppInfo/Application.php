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

namespace OCA\Memories\AppInfo;

use OCA\Memories\ClustersBackend;
use OCA\Memories\Listeners\BeforeTemplateListener;
use OCA\Memories\Listeners\PostDeleteListener;
use OCA\Memories\Listeners\PostLogoutListener;
use OCA\Memories\Listeners\PostWriteListener;
use OCA\Memories\Util;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\User\Events\UserLoggedOutEvent;

const AUTH_HEADER = 'HTTP_AUTHORIZATION';

class Application extends App implements IBootstrap
{
    public const APPNAME = 'memories';

    public const IMAGE_MIMES = [
        'image/png',
        'image/jpeg',
        'image/heic',
        'image/heif',
        'image/webp',
        'image/avif',
        'image/tiff',
        'image/gif',
        'image/bmp',
        'image/x-dcraw',        // RAW
        // 'image/x-xbitmap',   // too rarely used for photos
        // 'image/svg+xml',     // too rarely used for photos
    ];

    public const VIDEO_MIMES = [
        'video/mpeg',
        'video/webm',
        'video/mp4',
        'video/quicktime',
        'video/x-matroska',
        'video/MP2T',
        'video/x-msvideo',
        // 'video/x-m4v',       // too rarely used for photos
        // 'video/ogg',         // too rarely used for photos
    ];

    public function __construct()
    {
        parent::__construct(self::APPNAME);
    }

    public function register(IRegistrationContext $context): void
    {
        // Register file hooks
        $context->registerEventListener(NodeWrittenEvent::class, PostWriteListener::class);
        $context->registerEventListener(NodeTouchedEvent::class, PostWriteListener::class);
        $context->registerEventListener(NodeDeletedEvent::class, PostDeleteListener::class);

        // Register other global hooks
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, BeforeTemplateListener::class);
        $context->registerEventListener(UserLoggedOutEvent::class, PostLogoutListener::class);

        // Register clusters backends
        ClustersBackend\AlbumsBackend::register();
        ClustersBackend\TagsBackend::register();
        ClustersBackend\PlacesBackend::register();
        ClustersBackend\RecognizeBackend::register();
        ClustersBackend\FaceRecognitionBackend::register();

        // Extra hooks for native extension calls
        if (Util::callerIsNative()) {
            // Android webview sends an empty Authorization header which screws up DAV
            if (isset($_SERVER[AUTH_HEADER]) && empty($_SERVER[AUTH_HEADER])) {
                unset($_SERVER[AUTH_HEADER]);
            }
        }
    }

    public function boot(IBootContext $context): void
    {
    }
}

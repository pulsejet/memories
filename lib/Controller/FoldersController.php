<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\Files\FileInfo;
use OCP\Files\Folder;

class FoldersController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function sub(string $folder): Http\Response
    {
        return Util::guardEx(function () use ($folder) {
            $folder = Util::sanitizePath($folder);
            if (null === $folder) {
                throw Exceptions::BadRequest('Invalid parameter folder');
            }

            // Get the root folder (share root or user root)
            $root = $this->fs->getShareNode() ?? Util::getUserFolder();
            if (!$root instanceof Folder) {
                throw Exceptions::BadRequest('Root is not a folder');
            }

            // Get the inner folder
            try {
                $node = $root->get($folder);
            } catch (\OCP\Files\NotFoundException) {
                throw Exceptions::NotFound("Folder not found: {$folder}");
            }

            // Make sure we have a folder
            if (!$node instanceof Folder) {
                throw Exceptions::BadRequest('Path is not a folder');
            }

            // Ugly: get the view of the folder with reflection
            // This is unfortunately the only way to get the contents of a folder
            // matching a MIME type without using SEARCH, which is deep.
            //
            // To top it off, all this is completely useless at the moment
            // because the MIME search is done PHP-side
            $rp = new \ReflectionProperty(\OC\Files\Node\Node::class, 'view');
            $rp->setAccessible(true);

            /** @var \OC\Files\View */
            $view = $rp->getValue($node);

            // Get the subfolders
            $folders = $view->getDirectoryContent($node->getPath(), FileInfo::MIMETYPE_FOLDER, $node);

            // Sort by name
            usort($folders, static fn ($a, $b) => strnatcmp($a->getName(), $b->getName()));

            // Construct root for the base folder. This way we can reuse the
            // root by filtering out the subfolders we don't want.
            $root = new TimelineRoot();
            $this->fs->populateRoot($root);

            // Process to response type
            $list = array_map(function ($node) use ($root) {
                // Base changes permanently remove any mounts outside the
                // target folder, so we need to use a clone for each subfolder
                $root = clone $root;

                // Switch the cloned root to use only this folder
                $root->addFolder($node);
                $root->baseChange($node->getPath());

                return [
                    'fileid' => $node->getId(),
                    'name' => $node->getName(),
                    'previews' => $this->tq->getRootPreviews($root),
                ];
            }, $folders);

            return new Http\JSONResponse($list, Http::STATUS_OK);
        });
    }
}

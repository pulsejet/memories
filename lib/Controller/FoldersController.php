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
     */
    public function sub(string $folder): Http\Response
    {
        return Util::guardEx(function () use ($folder) {
            try {
                $node = Util::getUserFolder()->get($folder);
            } catch (\OCP\Files\NotFoundException) {
                throw Exceptions::NotFound('Folder not found');
            }

            if (!$node instanceof Folder) {
                throw Exceptions::NotFound('Path is not a folder');
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
                $root->addFolder($node);
                $root->baseChange($node->getPath());

                return [
                    'fileid' => $node->getId(),
                    'name' => $node->getName(),
                    'path' => $node->getPath(),
                    'previews' => $this->tq->getRootPreviews($root),
                ];
            }, $folders);

            return new Http\JSONResponse($list, Http::STATUS_OK);
        });
    }
}

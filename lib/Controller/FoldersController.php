<?php

namespace OCA\Memories\Controller;

use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\Files\FileInfo;
use OCP\Files\Folder;

class FoldersController extends GenericApiController
{
    protected TimelineQuery $timelineQuery;

    /**
     * @NoAdminRequired
     */
    public function sub(string $folder): Http\Response
    {
        return Util::guardEx(function () use ($folder) {
            try {
                $node = Util::getUserFolder()->get($folder);
            } catch (\OCP\Files\NotFoundException $e) {
                throw Exceptions::NotFound('Folder not found');
            }

            if (!$node instanceof Folder) {
                throw Exceptions::NotFound('Path is not a folder');
            }

            // Ugly: get the view of the folder with reflection
            // This is unfortunately the only way to get the contents of a folder
            // matching a MIME type without using SEARCH, which is deep
            $rp = new \ReflectionProperty('\OC\Files\Node\Node', 'view');
            $rp->setAccessible(true);
            $view = $rp->getValue($node);

            // Get the subfolders
            $folders = $view->getDirectoryContent($node->getPath(), FileInfo::MIMETYPE_FOLDER, $node);

            // Sort by name
            usort($folders, fn ($a, $b) => strnatcmp($a->getName(), $b->getName()));

            // Process to response type
            $list = array_map(fn ($node) => [
                'fileid' => $node->getId(),
                'name' => $node->getName(),
                'path' => $node->getPath(),
                'previews' => $this->timelineQuery->getFolderPreviews($node),
            ], $folders);

            return new Http\JSONResponse($list, Http::STATUS_OK);
        });
    }
}

<?php

namespace OCA\Memories\Controller;

use OCA\Memories\Db\TimelineQuery;
use OCP\Files\FileInfo;
use OCP\Files\Folder;

trait FoldersTrait
{
    protected TimelineQuery $timelineQuery;

    /**
     * Get subfolders entry for days response.
     */
    public function getSubfoldersEntry(Folder &$folder)
    {
        // Ugly: get the view of the folder with reflection
        // This is unfortunately the only way to get the contents of a folder
        // matching a MIME type without using SEARCH, which is deep
        $rp = new \ReflectionProperty('\OC\Files\Node\Node', 'view');
        $rp->setAccessible(true);
        $view = $rp->getValue($folder);

        // Get the subfolders
        $folders = $view->getDirectoryContent($folder->getPath(), FileInfo::MIMETYPE_FOLDER, $folder);

        // Sort by name
        usort($folders, function ($a, $b) {
            return strnatcmp($a->getName(), $b->getName());
        });

        // Process to response type
        return [
            'dayid' => \OCA\Memories\Util::$TAG_DAYID_FOLDERS,
            'count' => \count($folders),
            'detail' => array_map(function ($node) use (&$folder) {
                return [
                    'fileid' => $node->getId(),
                    'name' => $node->getName(),
                    'isfolder' => 1,
                    'path' => $node->getPath(),
                    'previews' => $this->getFolderPreviews($folder, $node),
                ];
            }, $folders, []),
        ];
    }

    private function getFolderPreviews(Folder &$parent, FileInfo &$fileInfo)
    {
        $folder = $parent->getById($fileInfo->getId());
        if (0 === \count($folder)) {
            return [];
        }

        return $this->timelineQuery->getFolderPreviews($folder[0]);
    }
}

<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\Files\FileInfo;

class TimelineRoot
{
    protected array $folders;
    protected array $folderPaths;

    /** Initialize */
    public function __construct()
    {
    }

    /**
     * Populate the root with the current user's folders.
     */
    public function populate()
    {
        \OC::$server->get(FsManager::class)->populateRoot($this);
    }

    /**
     * Add a folder to the root.
     *
     * @throws \Exception if node is not valid readable folder
     */
    public function addFolder(FileInfo $info)
    {
        $folderPath = $info->getPath();

        if (FileInfo::MIMETYPE_FOLDER !== $info->getMimetype()) {
            throw new \Exception("Not a folder: {$folderPath}");
        }

        if (!$info->isReadable()) {
            throw new \Exception("Folder not readable: {$folderPath}");
        }

        // Add top level folder
        $id = $info->getId();
        $this->folders[$id] = $info;
        $this->folderPaths[$id] = $folderPath;
    }

    // Add mountpoints recursively
    public function addMountPoints()
    {
        $mp = [];
        foreach ($this->folderPaths as $id => $folderPath) {
            $mounts = \OC\Files\Filesystem::getMountManager()->findIn($folderPath);
            foreach ($mounts as &$mount) {
                $id = $mount->getStorageRootId();
                $path = $mount->getMountPoint();
                $mp[$id] = $path;
            }
        }
        $this->folderPaths += $mp;
    }

    public function getFolderPath(int $id)
    {
        return $this->folderPaths[$id];
    }

    public function getIds()
    {
        return array_keys($this->folderPaths);
    }

    public function getOneId()
    {
        return array_key_first($this->folders);
    }

    public function getFolder(int $id)
    {
        return $this->folders[$id];
    }

    public function isEmpty()
    {
        return empty($this->folderPaths);
    }
}

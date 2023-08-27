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
        $this->folders = [];
        $this->folderPaths = [];
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
        $path = $info->getPath();

        if (FileInfo::MIMETYPE_FOLDER !== $info->getMimetype()) {
            throw new \Exception("Not a folder: {$path}");
        }

        if (!$info->isReadable()) {
            throw new \Exception("Folder not readable: {$path}");
        }

        // Add top level folder
        $this->setFolder($info->getId(), $info, $path);
    }

    // Add mountpoints recursively
    public function addMountPoints()
    {
        $folders = [];
        foreach ($this->folderPaths as $id => $folderPath) {
            $mounts = \OC\Files\Filesystem::getMountManager()->findIn($folderPath);
            foreach ($mounts as $mount) {
                $this->setFolder($mount->getStorageRootId(), null, $mount->getMountPoint());
            }
        }
        $this->folderPaths += $folders;
    }

    public function excludePaths(array $paths)
    {
        foreach ($paths as $path) {
            foreach ($this->folderPaths as $id => $folderPath) {
                // dirname strips the trailing slash, so we can directly add a
                // trailing slash to folderPath and path to prevent false matches.
                // https://github.com/pulsejet/memories/issues/668
                if (str_starts_with($folderPath.'/', $path.'/')) {
                    unset($this->folderPaths[$id], $this->folders[$id]);
                }
            }
        }
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

    private function setFolder(int $id, ?FileInfo $fileInfo, ?string $path)
    {
        if (null !== $path) {
            $this->folderPaths[$id] = $path;
        }

        if (null !== $fileInfo) {
            $this->folders[$id] = $fileInfo;
        }
    }
}

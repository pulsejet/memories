<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\Files\FileInfo;

class TimelineRoot
{
    /** @var array<int, \OCP\Files\FileInfo> */
    protected array $folders = [];

    /** @var array<int, string> */
    protected array $folderPaths = [];

    /**
     * Add a folder to the root.
     *
     * @throws \Exception if node is not valid readable folder
     */
    public function addFolder(FileInfo $info): void
    {
        $path = $info->getPath();

        if (FileInfo::MIMETYPE_FOLDER !== $info->getMimetype()) {
            throw new \Exception("Not a folder: {$path}");
        }

        if (!$info->isReadable()) {
            throw new \Exception("Folder not readable: {$path}");
        }

        // Add top level folder
        $this->setFolder($info->getId() ?? 0, $info, $path);
    }

    /**
     * Add mountpoints recursively.
     */
    public function addMountPoints(): void
    {
        $manager = \OC\Files\Filesystem::getMountManager();
        foreach ($this->folderPaths as $id => $folderPath) {
            $mounts = $manager->findIn($folderPath);
            foreach ($mounts as $mount) {
                $id = $mount->getStorageRootId();
                $path = $mount->getMountPoint();

                // Ignore hidden mounts or any mounts in hidden folders
                // (any edge cases/exceptions here?)
                if (str_contains($path, '/.')) {
                    continue;
                }

                $this->setFolder($id, null, $path);
            }
        }
    }

    /**
     * Exclude all folders that are in the given paths.
     *
     * @param string[] $paths The paths to exclude
     */
    public function excludePaths(array $paths): void
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

    /**
     * Change the base folder to a different one.
     * This excludes all folders not prefixed with the new base path.
     *
     * @param string $path The new base path
     */
    public function baseChange(string $path): void
    {
        foreach ($this->folderPaths as $id => $folderPath) {
            if (!str_starts_with($folderPath.'/', $path.'/')) {
                unset($this->folderPaths[$id], $this->folders[$id]);
            }
        }
    }

    /** @return int[] */
    public function getIds(): array
    {
        return array_keys($this->folderPaths);
    }

    public function getOneId(): ?int
    {
        return array_key_first($this->folders);
    }

    public function getFolder(int $id): ?FileInfo
    {
        return $this->folders[$id];
    }

    public function isEmpty(): bool
    {
        return empty($this->folderPaths);
    }

    private function setFolder(int $id, ?FileInfo $fileInfo, ?string $path): void
    {
        if (null !== $path) {
            $this->folderPaths[$id] = $path;
        }

        if (null !== $fileInfo) {
            $this->folders[$id] = $fileInfo;
        }
    }
}

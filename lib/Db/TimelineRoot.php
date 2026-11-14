<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\Files\FileInfo;

final class TimelineRoot
{
    /** @var array<int, \OCP\Files\FileInfo> */
    private array $folders = [];

    /** @var array<int, string> */
    private array $folderPaths = [];

    /** @var array<string, array<string, true>> */
    private array $aclMappings = [];

    /** @var array<int, true> */
    private array $aclDeniedTops = [];

    /** @var array<int, true> */
    private array $excludedIds = [];

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
     * Add a folder by ID if it is inside one of the folders in the root.
     */
    public function addSubfolder(int $id, string $path): void
    {
        // Same as mounts, skip anything in hidden folders
        if (str_contains($path, '/.')) {
            return;
        }

        foreach ($this->folderPaths as $folderPath) {
            if (str_starts_with($path.'/', $folderPath.'/')) {
                $this->setFolder($id, null, $path);

                return;
            }
        }
    }

    /**
     * Exclude files and folders (with their subtrees) by ID.
     *
     * @param int[] $ids
     */
    public function excludeIds(array $ids): void
    {
        foreach ($ids as $id) {
            $this->excludedIds[$id] = true;
        }
    }

    /** @return int[] */
    public function getExcludedIds(): array
    {
        return array_keys($this->excludedIds);
    }

    /**
     * Get the mounts containing the folders in the root.
     *
     * @return \OCP\Files\Mount\IMountPoint[]
     */
    public function getMounts(): array
    {
        $manager = \OC\Files\Filesystem::getMountManager();
        $mounts = [];
        foreach ($this->folderPaths as $path) {
            try {
                $mount = $manager->find($path);
                $mounts[$mount->getMountPoint()] = $mount;
            } catch (\OCP\Files\NotFoundException) {
            }
        }

        return array_values($mounts);
    }

    /**
     * Apply the group folder ACL rules matching these mappings to the root.
     *
     * @param list<array{string, string}> $mappings pairs of mapping type and id
     */
    public function addAclMappings(array $mappings): void
    {
        foreach ($mappings as [$type, $id]) {
            $this->aclMappings[$type][$id] = true;
        }
    }

    /** @return array<string, string[]> mapping ids by type */
    public function getAclMappings(): array
    {
        return array_map(static fn (array $ids) => array_map('strval', array_keys($ids)), $this->aclMappings);
    }

    /**
     * Mark a top folder as not readable itself; folders below may still be.
     */
    public function addAclDeniedTop(int $id): void
    {
        $this->aclDeniedTops[$id] = true;
    }

    /** @return int[] */
    public function getAclDeniedTops(): array
    {
        return array_keys($this->aclDeniedTops);
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
        return $this->folders[$id] ?? null;
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

<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;

trait TimelineWriteEmbeddedTags
{
    /**
     * Process embedded tags from EXIF data and store them in the database
     *
     * @param File $file File node to process
     * @param array $exif EXIF data extracted from the file
     */
    public function processEmbeddedTags(File $file, array $exif): void
    {
        // Get the user ID from the file owner
        $userId = $file->getOwner()->getUID();
        
        // Extract embedded tags from EXIF data
        $embeddedTags = $this->extractEmbeddedTags($exif);
        
        if (empty($embeddedTags)) {
            return;
        }
        
        // Insert or update tags
        foreach ($embeddedTags as $tagPath) {
            $this->ensureTagExists($userId, $tagPath);
        }
    }
    
    /**
     * Extract embedded tags from EXIF data
     * 
     * @param array $exif EXIF data
     * @return array Array of tag paths
     */
    private function extractEmbeddedTags(array $exif): array
    {
        $embeddedTags = [];
        
        // Helper function to ensure we have an array
        $ensureArray = function ($value) {
            if (empty($value)) {
                return [];
            }
            return is_array($value) ? $value : [$value];
        };
        
        // Extract from TagsList (split by '/')
        if (!empty($exif['TagsList'])) {
            $tagsList = $ensureArray($exif['TagsList']);
            foreach ($tagsList as $tag) {
                $embeddedTags[] = explode('/', $tag);
            }
        }
        
        // Extract from HierarchicalSubject (split by '|')
        if (empty($embeddedTags) && !empty($exif['HierarchicalSubject'])) {
            $hierarchicalSubject = $ensureArray($exif['HierarchicalSubject']);
            foreach ($hierarchicalSubject as $tag) {
                $embeddedTags[] = explode('|', $tag);
            }
        }
        
        // Extract from Keywords (as individual tags)
        if (empty($embeddedTags) && !empty($exif['Keywords'])) {
            $keywords = $ensureArray($exif['Keywords']);
            foreach ($keywords as $tag) {
                $embeddedTags[] = [$tag];
            }
        }
        
        // Extract from Subject (as individual tags)
        if (empty($embeddedTags) && !empty($exif['Subject'])) {
            $subject = $ensureArray($exif['Subject']);
            foreach ($subject as $tag) {
                $embeddedTags[] = [$tag];
            }
        }
        
        return $embeddedTags;
    }
    
    /**
     * Ensure a tag exists in the database
     *
     * @param string $userId User ID
     * @param array $tagPath Tag path as an array
     */
    private function ensureTagExists(string $userId, array $tagPath): void
    {
        $level = 0;
        $parentTagId = null; // Track parent tag ID instead of name
        $fullPath = '';
        
        foreach ($tagPath as $index => $tagPart) {
            // Skip empty tag parts
            if (empty($tagPart)) {
                continue;
            }
            
            // Trim whitespace
            $tagPart = trim($tagPart);
            if (empty($tagPart)) {
                continue;
            }
            
            // Build the full path
            $fullPath = $fullPath ? $fullPath . '/' . $tagPart : $tagPart;
            
            // Check if tag already exists
            $query = $this->connection->getQueryBuilder();
            $existingTag = $query->select('id')
                ->from('memories_embedded_tags')
                ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
                ->andWhere($query->expr()->eq('path', $query->createNamedParameter($fullPath, IQueryBuilder::PARAM_STR)))
                ->executeQuery()
                ->fetch();
            
            // If tag doesn't exist, insert it
            if (!$existingTag) {
                $query = $this->connection->getQueryBuilder();
                $query->insert('memories_embedded_tags')
                    ->values([
                        'user_id' => $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR),
                        'tag' => $query->createNamedParameter($tagPart, IQueryBuilder::PARAM_STR),
                        'parent_tag_id' => $query->createNamedParameter($parentTagId, $parentTagId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
                        'path' => $query->createNamedParameter($fullPath, IQueryBuilder::PARAM_STR),
                        'level' => $query->createNamedParameter($level, IQueryBuilder::PARAM_INT),
                    ])
                    ->executeStatement();
                
                // Get the ID of the newly inserted tag
                $query = $this->connection->getQueryBuilder();
                $newTagId = $query->select('id')
                    ->from('memories_embedded_tags')
                    ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
                    ->andWhere($query->expr()->eq('path', $query->createNamedParameter($fullPath, IQueryBuilder::PARAM_STR)))
                    ->executeQuery()
                    ->fetchOne();
                
                $parentTagId = (int)$newTagId;
            } else {
                // If tag exists, get its ID for the next iteration
                $parentTagId = (int)$existingTag['id'];
            }
            
            // Update for next iteration
            $level++;
        }
    }
    
    /**
     * Delete a tag by path
     *
     * @param string $userId User ID
     * @param string $path Tag path
     * @param bool $recursive Whether to delete child tags
     * @return bool True if tag was deleted
     */
    public function deleteTagByPath(string $userId, string $path, bool $recursive = false): bool
    {
        // If not recursive, check if tag has children
        if (!$recursive) {
            // Get the tag ID first
            $query = $this->connection->getQueryBuilder();
            $tagId = $query->select('id')
                ->from('memories_embedded_tags')
                ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
                ->andWhere($query->expr()->eq('path', $query->createNamedParameter($path, IQueryBuilder::PARAM_STR)))
                ->executeQuery()
                ->fetchOne();
                
            if (!$tagId) {
                // Tag not found
                return false;
            }
            
            $query = $this->connection->getQueryBuilder();
            $hasChildren = $query->select('id')
                ->from('memories_embedded_tags')
                ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
                ->andWhere($query->expr()->eq('parent_tag_id', $query->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
                ->executeQuery()
                ->fetch();
            
            if ($hasChildren) {
                // Tag has children and recursive is false, so don't delete
                return false;
            }
        }
        
        // Delete the tag (and children if recursive)
        $query = $this->connection->getQueryBuilder();
        $expr = $query->expr();
        $conditions = [
            $expr->eq('user_id', $query->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
        ];
        
        if ($recursive) {
            // If recursive, delete all tags that start with this path
            $conditions[] = $expr->orX(
                $expr->eq('path', $query->createNamedParameter($path, IQueryBuilder::PARAM_STR)),
                $expr->like('path', $query->createNamedParameter($path . '/%', IQueryBuilder::PARAM_STR))
            );
        } else {
            // If not recursive, delete only this exact tag
            $conditions[] = $expr->eq('path', $query->createNamedParameter($path, IQueryBuilder::PARAM_STR));
        }
        
        $query->delete('memories_embedded_tags')
            ->where(...$conditions)
            ->executeStatement();
        
        return true;
    }
}
<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Video;

use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Exceptions;
use OCP\IConfig;
use OCA\Memories\Service\TripMediaService;

/**
 * Service to generate highlight videos for trips
 */
class TripVideoGenerator
{
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly IRootFolder $rootFolder,
        private readonly IL10N $l10n,
        private readonly TimelineRoot $timeline,
        private readonly IConfig $config,
        private readonly TripMediaService $tripMediaService,
    ) {
    }

    /**
     * Generate a highlight video for a trip
     */
    public function generateTripVideo(
        int $tripId, 
        string $userId, 
        int $maxImages = 15, 
        float $transitionDuration = 1.0, 
        float $imageDuration = 3.0,
        float $videoSegmentDuration = 3.0,
        ?float $maxPercentage = 0.2,
        int $minItems = 15
    ): string {
        $this->logger->info("Generating trip video for tripId={$tripId}, userId={$userId}, maxItems={$maxImages}, maxPercentage={$maxPercentage}, minItems={$minItems}");
        
        // Get trip details
        $trip = $this->getTripById($tripId);
        if (!$trip) {
            throw new \RuntimeException("Trip not found: {$tripId}");
        }
        
        // Create temporary directory
        $tempId = uniqid('', true);
        $tempDir = sys_get_temp_dir() . "/memories_trip_video_{$tripId}_{$tempId}";
        if (!mkdir($tempDir, 0755, true)) {
            throw new \RuntimeException("Failed to create temporary directory: {$tempDir}");
        }
        
        try {
            // Get trip media files using the shared service
            $mediaFiles = $this->tripMediaService->getTripMedia(
                $tripId, 
                $userId, 
                $maxImages, 
                null, // maxVideoDuration
                $maxPercentage,
                $minItems
            );
            
            if (empty($mediaFiles)) {
                throw new \RuntimeException("No media files found for trip {$tripId}");
            }
            
            // Process files (download, prepare for ffmpeg)
            $processedFiles = $this->prepareMediaFiles($mediaFiles, $tempDir, $userId, $videoSegmentDuration);
            if (empty($processedFiles)) {
                throw new \RuntimeException("Failed to process any media files");
            }
            
            // Create title card
            if (!empty($processedFiles)) {
                $titleCardPath = $this->createTitleCard($tempDir, $trip, $processedFiles[0], $userId);
                if ($titleCardPath) {
                    // Insert title card at the beginning of the processed files array
                    array_unshift($processedFiles, [
                        'type' => 'title',
                        'path' => $titleCardPath,
                        'datetaken' => $processedFiles[0]['datetaken'] ?? '',
                    ]);
                }
            }
            
            // Select background music
            $musicPath = $this->selectBackgroundMusic($tempDir);
            
            // Generate base video without music first
            $baseVideoPath = $this->generateBaseVideo($processedFiles, $tempDir, $transitionDuration, $imageDuration);
            
            // Now add the music separately
            $finalVideoPath = $this->addMusicToVideo($baseVideoPath, $musicPath, $tempDir);
            
            // Store the generated video
            $videoUrl = $this->storeVideo($finalVideoPath, $trip, $userId);
            
            // Delete temp directory
            $this->deleteTempDirectory($tempDir);
            
            return $videoUrl;
        } catch (\Exception $e) {
            // Clean up temp directory
            $this->deleteTempDirectory($tempDir);
            throw new \RuntimeException("Error generating video: " . $e->getMessage());
        }
    }

    /**
     * Generate the base video without any music
     */
    private function generateBaseVideo(
        array $processedFiles,
        string $tempDir,
        float $transitionDuration,
        float $imageDuration
    ): string {
        $this->logger->info("Generating base video without music");
        
        if (empty($processedFiles)) {
            throw new \RuntimeException("No media files to process");
        }
        
        $baseVideoPath = $tempDir . '/base_video.mp4';
        
        // Create individual clip segments
        $concatFile = $tempDir . '/concat.txt';
        $concatContent = '';
        $clipFiles = [];
        
        // Log the sequence of media types
        $mediaSequence = [];
        foreach ($processedFiles as $file) {
            $mediaSequence[] = $file['type'];
        }
        $this->logger->info("Media sequence: " . implode(', ', $mediaSequence));
        
        foreach ($processedFiles as $i => $file) {
            $clipPath = $tempDir . '/clip_' . $i . '.mp4';
            
            if ($file['type'] === 'image') {
                // For images, create a video clip with the specified duration
                $cmd = "ffmpeg -y -loop 1 -i " . escapeshellarg($file['path']) . 
                      " -c:v libx264 -pix_fmt yuv420p " .
                      " -t " . $imageDuration . 
                      " " . escapeshellarg($clipPath);
            } elseif ($file['type'] === 'title') {
                // For title card, use a slightly longer duration
                $cmd = "ffmpeg -y -loop 1 -i " . escapeshellarg($file['path']) . 
                      " -c:v libx264 -pix_fmt yuv420p " .
                      " -t " . ($imageDuration * 1.2) . 
                      " " . escapeshellarg($clipPath);
            } else {
                // For videos, just copy them with consistent encoding
                $cmd = "ffmpeg -y -i " . escapeshellarg($file['path']) . 
                      " -c:v libx264 -crf 23 -preset fast " .
                      " -c:a aac -b:a 128k " .
                      " " . escapeshellarg($clipPath);
            }
            
            $this->logger->info("Creating clip {$i} ({$file['type']}): " . $cmd);
            
            exec($cmd . ' 2>&1', $output, $returnCode);
            if ($returnCode === 0) {
                $clipFiles[] = $clipPath;
                $concatContent .= "file '" . $clipPath . "'\n";
                $this->logger->info("Successfully created clip {$i}");
            } else {
                $this->logger->warning("Error creating clip for {$file['type']} file {$i}: " . implode("\n", $output));
            }
        }
        
        // Write concat file
        file_put_contents($concatFile, $concatContent);
        
        // Create the base video
        $concatCmd = "ffmpeg -y -f concat -safe 0 -i " . escapeshellarg($concatFile) . 
                   " -c:v libx264 -preset medium -crf 23 -pix_fmt yuv420p " . 
                   " -c:a aac -b:a 128k -ar 44100 " .
                   escapeshellarg($baseVideoPath);
        
        $this->logger->info("Creating base video: " . $concatCmd);
        exec($concatCmd . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($baseVideoPath) || filesize($baseVideoPath) < 1000) {
            $this->logger->error("Error creating base video: " . implode("\n", $output));
            throw new \RuntimeException("Failed to create base video");
        }
        
        $this->logger->info("Base video created successfully at: " . $baseVideoPath);
        return $baseVideoPath;
    }

    /**
     * Create a title card for the video with trip information and a blurred background
     */
    private function createTitleCard(string $tempDir, array $trip, array $firstMediaFile, string $userId): ?string
    {
        try {
            $this->logger->info("Creating title card for trip");
            
            // Find the first image file to use as background
            $backgroundImage = null;
            
            if ($firstMediaFile['type'] === 'image') {
                $backgroundImage = $firstMediaFile['path'];
            } else {
                // Try to find an image in the trip
                $qb = $this->db->getQueryBuilder();
                $qb->select('f.fileid')
                    ->from('memories_trip_photos', 'tp')
                    ->innerJoin('tp', 'filecache', 'f', 'tp.fileid = f.fileid')
                    ->innerJoin('f', 'mimetypes', 'mt', 'f.mimetype = mt.id')
                    ->where($qb->expr()->eq('tp.trip_id', $qb->createNamedParameter($trip['id'], \PDO::PARAM_INT)))
                    ->andWhere($qb->expr()->like('mt.mimetype', $qb->createNamedParameter('image/%')))
                    ->orderBy('tp.id', 'ASC')
                    ->setMaxResults(1);
                
                $result = $qb->executeQuery();
                $fileId = $result->fetchOne();
                $result->closeCursor();
                
                if ($fileId) {
                    $userFolder = $this->rootFolder->getUserFolder($userId);
                    $nodes = $userFolder->getById((int)$fileId);
                    
                    if (!empty($nodes)) {
                        $node = $nodes[0];
                        $tempImagePath = $tempDir . '/title_background_orig.jpg';
                        file_put_contents($tempImagePath, $node->getContent());
                        $backgroundImage = $tempImagePath;
                    }
                }
            }
            
            if (!$backgroundImage) {
                $this->logger->warning("No suitable background image found for title card");
                return null;
            }
            
            // Title card dimensions
            $width = 1920;
            $height = 1080;
            
            // Create the base image at full resolution
            $tempBaseImagePath = $tempDir . '/title_background_base.jpg';
            $this->resizeImage($backgroundImage, $tempBaseImagePath, $width, $height);
            
            // Create blurred version of the background
            $titleCardPath = $tempDir . '/title_card.jpg';
            $blurCmd = "ffmpeg -y -i " . escapeshellarg($tempBaseImagePath) . 
                     " -vf \"boxblur=20:10\" " . escapeshellarg($titleCardPath);
            
            exec($blurCmd . ' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->logger->warning("Error creating blurred background: " . implode("\n", $output));
                
                // If blurring fails, use the original resized image
                copy($tempBaseImagePath, $titleCardPath);
            }
            
            // Get trip info
            $tripName = $trip['custom_name'] ?? $trip['name'] ?? "Trip {$trip['id']}";
            $location = $trip['location'] ?? '';
            $timeframe = $trip['timeframe'] ?? '';
            
            // Create text overlay
            $overlayPath = $tempDir . '/title_overlay.png';
            
            // Create a transparent PNG with text
            $overlay = imagecreatetruecolor($width, $height);
            imagealphablending($overlay, false);
            imagesavealpha($overlay, true);
            $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
            imagefill($overlay, 0, 0, $transparent);
            imagealphablending($overlay, true);
            
            // Text colors and settings
            $white = imagecolorallocate($overlay, 255, 255, 255);
            $shadow = imagecolorallocate($overlay, 0, 0, 0);
            
            // Add semi-transparent dark overlay for better text visibility
            $overlay_bg = imagecolorallocatealpha($overlay, 0, 0, 0, 80);
            imagefilledrectangle($overlay, 0, 0, $width, $height, $overlay_bg);
            
            // Create title text using FFmpeg for better text rendering
            $finalTitleCardPath = $tempDir . '/title_card_final.jpg';
            
            // Prepare text elements for FFmpeg drawtext filter
            $tripNameEscaped = str_replace("'", "\\'", $tripName);
            $locationEscaped = str_replace("'", "\\'", $location);
            $timeframeEscaped = str_replace("'", "\\'", $timeframe);
            
            // Use FFmpeg's drawtext filter for high quality text
            $textCmd = "ffmpeg -y -i " . escapeshellarg($titleCardPath) . " -vf \"" .
                     // Title - large, centered, with shadow
                     "drawtext=text='" . $tripNameEscaped . "':fontcolor=white:fontsize=72:" .
                     "x=(w-text_w)/2:y=(h-text_h)/2-100:shadowcolor=black:shadowx=3:shadowy=3," .
                     // Location - medium size, below title
                     "drawtext=text='" . $locationEscaped . "':fontcolor=white:fontsize=54:" .
                     "x=(w-text_w)/2:y=(h-text_h)/2+50:shadowcolor=black:shadowx=2:shadowy=2," .
                     // Timeframe - smaller, at bottom
                     "drawtext=text='" . $timeframeEscaped . "':fontcolor=white:fontsize=48:" .
                     "x=(w-text_w)/2:y=(h-text_h)/2+150:shadowcolor=black:shadowx=2:shadowy=2" .
                     "\" " . escapeshellarg($finalTitleCardPath);
            
            exec($textCmd . ' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->logger->warning("Error creating text overlay with FFmpeg: " . implode("\n", $output));
                
                // Fall back to PHP's GD if FFmpeg text rendering fails
                // Add text shadow and text
                // Title - large, centered
                $titleY = $height / 2 - 100;
                $this->drawLargeText($overlay, $width / 2, $titleY, $tripName, 72, $white, $shadow);
                
                // Location - medium size, below title
                $locationY = $height / 2 + 50;
                $this->drawLargeText($overlay, $width / 2, $locationY, $location, 54, $white, $shadow);
                
                // Timeframe - smaller, at bottom
                $timeframeY = $height / 2 + 150;
                $this->drawLargeText($overlay, $width / 2, $timeframeY, $timeframe, 48, $white, $shadow);
                
                // Save overlay
                imagepng($overlay, $overlayPath);
                imagedestroy($overlay);
                
                // Combine background and overlay
                $combineCmd = "ffmpeg -y -i " . escapeshellarg($titleCardPath) . 
                             " -i " . escapeshellarg($overlayPath) . 
                             " -filter_complex \"[0:v][1:v]overlay=0:0\" " .
                             escapeshellarg($finalTitleCardPath);
                
                exec($combineCmd . ' 2>&1', $output, $returnCode);
                if ($returnCode !== 0) {
                    $this->logger->warning("Error combining title card layers: " . implode("\n", $output));
                    return $titleCardPath; // Return just the blurred background if combining fails
                }
            }
            
            return $finalTitleCardPath;
        } catch (\Exception $e) {
            $this->logger->warning("Error creating title card: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Draw large text on an image
     */
    private function drawLargeText($image, $centerX, $y, $text, $fontSize, $color, $shadowColor) {
        // Draw shadow first
        $shadowOffsetX = 3;
        $shadowOffsetY = 3;
        
        // Calculate text width for a simple approximation (rough estimate)
        $charWidth = $fontSize * 0.6;
        $textWidth = strlen($text) * $charWidth;
        $x = $centerX - ($textWidth / 2);
        
        // Manual drawing of large letters
        $charSize = $fontSize / 5;
        
        // Draw shadow and then text
        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            $charX = $x + ($i * $charWidth);
            imagechar($image, 5, $charX + $shadowOffsetX, $y + $shadowOffsetY, $char, $shadowColor);
            imagechar($image, 5, $charX, $y, $char, $color);
            
            // Draw larger version of the text by repeating characters in a pattern
            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dy = -1; $dy <= 1; $dy++) {
                    if ($dx != 0 || $dy != 0) {
                        imagechar($image, 5, $charX + $dx, $y + $dy, $char, $color);
                    }
                }
            }
        }
    }

    /**
     * Selects a background music track
     */
    private function selectBackgroundMusic(string $tempDir): string {
        echo "\n===== MUSIC SELECTION START =====\n";
        
        // Create a directory for music cache if it doesn't exist
        $appDataDir = $this->getRootMusicCacheDir();
        echo "Music cache directory: " . $appDataDir . "\n";
        
        if (!is_dir($appDataDir)) {
            mkdir($appDataDir, 0755, true);
            echo "Created music cache directory\n";
        }
        
        // Create a temp music directory
        $musicDir = $tempDir . '/music';
        if (!is_dir($musicDir)) {
            mkdir($musicDir, 0755, true);
        }
        
        $songPath = $musicDir . '/background_music.mp3';
        
        // Get existing tracks
        $existingTracks = glob($appDataDir . '/*.{mp3,ogg}', GLOB_BRACE);
        $tracksCount = count($existingTracks);
        echo "Found {$tracksCount} existing tracks in cache\n";
        
        // Print all existing tracks
        if ($tracksCount > 0) {
            echo "Available tracks:\n";
            foreach ($existingTracks as $index => $track) {
                echo ($index + 1) . ". " . basename($track) . " (" . round(filesize($track) / 1024 / 1024, 2) . " MB)\n";
            }
        }
        
        // Get the last used track
        $lastUsedPath = $appDataDir . '/last_used.txt';
        $lastUsed = '';
        if (file_exists($lastUsedPath)) {
            $lastUsed = trim(file_get_contents($lastUsedPath));
            echo "Last used track: " . $lastUsed . "\n";
        } else {
            echo "No last used track recorded\n";
        }
        
        // Force download a new track 50% of the time or if we have less than 5 tracks
        $shouldDownloadNew = ($tracksCount < 5) || (random_int(1, 100) <= 50);
        echo "Should download new track? " . ($shouldDownloadNew ? "Yes" : "No") . "\n";
        
        // Try to download a new track if needed
        if ($shouldDownloadNew) {
            echo "Attempting to download a new track...\n";
            $newTrack = $this->fetchRandomJazzTrack($appDataDir);
            
            if ($newTrack) {
                echo "SUCCESS! Using new downloaded track: " . basename($newTrack) . "\n";
                copy($newTrack, $songPath);
                
                // Save as last used
                file_put_contents($lastUsedPath, basename($newTrack));
                echo "===== MUSIC SELECTION COMPLETE =====\n";
                return $songPath;
            } else {
                echo "Failed to download a new track\n";
            }
        }
        
        // If we get here, try to use a cached track
        echo "Selecting from cached tracks...\n";
        
        // Filter out the last used track
        $filteredTracks = $existingTracks;
        if (!empty($lastUsed) && count($existingTracks) > 1) {
            $filteredTracks = array_values(array_filter($existingTracks, function($track) use ($lastUsed) {
                return basename($track) !== $lastUsed;
            }));
            echo "Filtered out last used track, now have " . count($filteredTracks) . " tracks to choose from\n";
        }
        
        // Select a random track from the filtered list
        if (!empty($filteredTracks)) {
            // Get cryptographically secure random index
            $randIndex = random_int(0, count($filteredTracks) - 1);
            $selectedTrack = $filteredTracks[$randIndex];
            
            echo "Selected track index " . $randIndex . " from " . count($filteredTracks) . " available tracks\n";
            echo "SUCCESS! Using cached track: " . basename($selectedTrack) . "\n";
            
            // Save as last used
            file_put_contents($lastUsedPath, basename($selectedTrack));
            
            // Copy to temp directory
            copy($selectedTrack, $songPath);
            echo "===== MUSIC SELECTION COMPLETE =====\n";
            return $songPath;
        } else if (!empty($existingTracks)) {
            // If we filtered out all tracks but still have some, use any track
            $randIndex = random_int(0, count($existingTracks) - 1);
            $selectedTrack = $existingTracks[$randIndex];
            
            echo "Had to use a track that was used before: " . basename($selectedTrack) . "\n";
            
            // Save as last used
            file_put_contents($lastUsedPath, basename($selectedTrack));
            
            // Copy to temp directory
            copy($selectedTrack, $songPath);
            echo "===== MUSIC SELECTION COMPLETE =====\n";
            return $songPath;
        }
        
        // Fallback to a simple tone
        echo "No music track available, creating fallback tone\n";
        $fallbackCmd = "ffmpeg -y -f lavfi -i 'sine=frequency=440:duration=180:sample_rate=44100' -c:a libmp3lame -b:a 192k " . 
                      escapeshellarg($songPath);
        
        exec($fallbackCmd . ' 2>&1', $fallbackOutput, $fallbackCode);
        
        if ($fallbackCode !== 0 || !file_exists($songPath) || filesize($songPath) < 1000) {
            echo "Failed to create tone\n";
            // Create an empty file so at least the rest of the process works
            file_put_contents($songPath, '');
        } else {
            echo "Created fallback tone successfully\n";
        }
        
        echo "===== MUSIC SELECTION COMPLETE (FALLBACK) =====\n";
        return $songPath;
    }

    /**
     * Fetch a random jazz track from archive.org
     */
    private function fetchRandomJazzTrack(string $cacheDir): ?string {
        echo "Fetching random jazz track...\n";
        
        // Ensure directory exists
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Fixed list of reliable OGG audio files
        $audioSources = [
            'https://archive.org/download/Jazz_Night-12188/04_-_Straight_no_chaser.ogg',
            'https://archive.org/download/Jazz_Night-12188/13_-_Konflikt.ogg',
            'https://archive.org/download/Jazz_Night-12188/Jazz_at_Mladost_-_02_-_Avenue_B.ogg',
            'https://archive.org/download/Jazz_Night-12188/Jazz_at_Mladost_-_03_-_Blue_Monk.ogg'
        ];
        
        // Shuffle array to get a random order
        shuffle($audioSources);
        
        // Try to download each until successful
        foreach ($audioSources as $index => $url) {
            $filename = basename($url);
            $cachePath = $cacheDir . '/' . $filename;
            
            echo "Trying track " . ($index + 1) . ": " . $filename . "\n";
            
            // If already cached, return it
            if (file_exists($cachePath) && filesize($cachePath) > 10000) {
                echo "Track already exists in cache\n";
                return $cachePath;
            }
            
            // Try to download
            echo "Downloading track: " . $url . "\n";
            $audioContent = @file_get_contents($url);
            
            if ($audioContent && strlen($audioContent) > 10000) {
                echo "Downloaded track successfully (" . round(strlen($audioContent) / 1024 / 1024, 2) . " MB)\n";
                file_put_contents($cachePath, $audioContent);
                return $cachePath;
            } else {
                echo "Failed to download track\n";
            }
        }
        
        // Check if we have any existing tracks we can use
        $existingFiles = glob($cacheDir . '/*.{mp3,ogg}', GLOB_BRACE);
        if (!empty($existingFiles)) {
            $randomFile = $existingFiles[array_rand($existingFiles)];
            if (file_exists($randomFile) && filesize($randomFile) > 10000) {
                echo "Using existing track as fallback: " . basename($randomFile) . "\n";
                return $randomFile;
            }
        }
        
        echo "Failed to get any track\n";
        return null;
    }
    
    /**
     * Add music to the video
     */
    private function addMusicToVideo(string $videoPath, string $musicPath, string $tempDir): string {
        $this->logger->info("Adding music to video");
        
        if (empty($musicPath) || !file_exists($musicPath) || filesize($musicPath) < 1000) {
            $this->logger->warning("No valid music file available, creating a default tone");
            
            // Create a simple tone as a last resort
            $defaultMusicPath = $tempDir . '/default_music.mp3';
            $toneCmd = "ffmpeg -y -f lavfi -i 'sine=frequency=440:duration=180:sample_rate=44100' -c:a libmp3lame -b:a 192k " . 
                      escapeshellarg($defaultMusicPath);
            
            $this->logger->info("Creating default tone: " . $toneCmd);
            exec($toneCmd . ' 2>&1', $toneOutput, $toneCode);
            
            if ($toneCode !== 0 || !file_exists($defaultMusicPath) || filesize($defaultMusicPath) < 1000) {
                $this->logger->error("Failed to create tone: " . implode("\n", $toneOutput));
                // Create an empty file so at least the rest of the process works
                file_put_contents($defaultMusicPath, '');
                $this->logger->warning("Created empty music file as last resort");
            } else {
                $this->logger->info("Created fallback tone successfully");
            }
            
            $musicPath = $defaultMusicPath;
        }
        
        $finalVideoPath = $tempDir . '/final_video.mp4';
        
        // Get video duration
        $ffprobeCmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($videoPath) . " 2>&1";
        exec($ffprobeCmd, $output, $returnCode);
        
        $videoDuration = 0;
        if ($returnCode === 0 && !empty($output) && is_numeric(trim($output[0]))) {
            $videoDuration = (float)trim($output[0]);
        } else {
            $this->logger->warning("Failed to get video duration: " . implode("\n", $output));
            // Fallback
            $videoDuration = 60; // Assume 1 minute if we can't determine
        }
        
        // Check if video has audio
        $hasAudio = false;
        $checkAudioCmd = "ffprobe -v error -select_streams a -show_streams " . escapeshellarg($videoPath) . " 2>&1";
        exec($checkAudioCmd, $audioOutput, $audioReturnCode);
        $hasAudio = !empty($audioOutput);
        $this->logger->info("Video audio check: " . ($hasAudio ? "Has audio" : "No audio"));
        
        if (!$hasAudio) {
            // If video has no audio, just add the music track
            $this->logger->info("Video has no audio, simply adding music track");
            $simpleAddCmd = "ffmpeg -y -i " . escapeshellarg($videoPath) . 
                         " -i " . escapeshellarg($musicPath) . 
                         " -c:v copy -c:a aac -b:a 192k -shortest -map 0:v:0 -map 1:a:0 " .
                         escapeshellarg($finalVideoPath) . " 2>&1";
            
            $this->logger->info("Adding music: " . $simpleAddCmd);
            exec($simpleAddCmd . ' 2>&1', $output, $returnCode);
        } else {
            // Video has audio - force audio levels directly with volume filters
            // This approach uses two passes to ensure compatibility
            $tempMixPath = $tempDir . '/temp_audio_mix.mp4';
            
            // First pass: Extract and amplify original audio
            $extractOriginalAudio = $tempDir . '/original_audio.aac';
            $extractAudioCmd = "ffmpeg -y -i " . escapeshellarg($videoPath) . 
                           " -vn -acodec copy " . escapeshellarg($extractOriginalAudio) . " 2>&1";
            
            $this->logger->info("Extracting original audio: " . $extractAudioCmd);
            exec($extractAudioCmd, $extractOutput, $extractReturnCode);
            
            if ($extractReturnCode === 0 && file_exists($extractOriginalAudio)) {
                // Second pass: Create version with background music at lower volume
                $mixedAudioPath = $tempDir . '/mixed_audio.aac';
                $mixAudioCmd = "ffmpeg -y -i " . escapeshellarg($extractOriginalAudio) . 
                            " -i " . escapeshellarg($musicPath) . 
                            " -filter_complex '[0:a]volume=4.0[a0];[1:a]volume=0.08[a1];[a0][a1]amix=inputs=2:duration=longest' " .
                            " -c:a aac -b:a 192k " . escapeshellarg($mixedAudioPath) . " 2>&1";
                
                $this->logger->info("Creating mixed audio: " . $mixAudioCmd);
                exec($mixAudioCmd, $mixOutput, $mixReturnCode);
                
                if ($mixReturnCode === 0 && file_exists($mixedAudioPath)) {
                    // Final pass: Combine original video with new audio
                    $finalCmd = "ffmpeg -y -i " . escapeshellarg($videoPath) . 
                             " -i " . escapeshellarg($mixedAudioPath) . 
                             " -c:v copy -c:a copy -map 0:v:0 -map 1:a:0 " .
                             escapeshellarg($finalVideoPath) . " 2>&1";
                    
                    $this->logger->info("Combining video with mixed audio: " . $finalCmd);
                    exec($finalCmd, $output, $returnCode);
                } else {
                    $this->logger->error("Failed to mix audio: " . implode("\n", $mixOutput));
                    $returnCode = -1; // Force fallback
                }
            } else {
                $this->logger->error("Failed to extract original audio: " . implode("\n", $extractOutput));
                $returnCode = -1; // Force fallback
            }
        }
        
        if ($returnCode !== 0 || !file_exists($finalVideoPath) || filesize($finalVideoPath) < 1000) {
            $this->logger->error("Error with audio mixing: " . implode("\n", $output));
            
            // Last resort - try the old method that at least added music
            $lastResortCmd = "ffmpeg -y -i " . escapeshellarg($videoPath) . 
                          " -i " . escapeshellarg($musicPath) . 
                          " -c:v copy -c:a aac -b:a 192k " .
                          " -map 0:v:0 -map 1:a:0 " .
                          escapeshellarg($finalVideoPath) . " 2>&1";
            
            $this->logger->info("Trying last resort music addition: " . $lastResortCmd);
            exec($lastResortCmd . ' 2>&1', $lastOutput, $lastReturnCode);
            
            if ($lastReturnCode !== 0 || !file_exists($finalVideoPath) || filesize($finalVideoPath) < 1000) {
                $this->logger->error("Last resort approach failed: " . implode("\n", $lastOutput));
                $this->logger->info("Falling back to base video without music");
                return $videoPath;
            }
        }
        
        $this->logger->info("Successfully added music to video");
        return $finalVideoPath;
    }

    /**
     * Store the generated video in the user's files
     */
    private function storeVideo(string $videoPath, array $trip, string $userId): string {
        $this->logger->info("Storing video for user {$userId}");
        
        $userFolder = $this->rootFolder->getUserFolder($userId);
        
        // Create Memories/TripVideos folder if it doesn't exist
        $memoryPath = '/Memories/TripVideos';
        if (!$userFolder->nodeExists($memoryPath)) {
            // Create parent directory first if needed
            if (!$userFolder->nodeExists('/Memories')) {
                $userFolder->newFolder('/Memories');
            }
            $userFolder->newFolder($memoryPath);
        }
        
        // Generate video filename
        $tripName = $trip['custom_name'] ?? $trip['name'] ?? "Trip {$trip['id']}";
        $safeFilename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tripName);
        $videoFilename = "trip_video_{$trip['id']}_{$safeFilename}.mp4";
        
        $targetPath = $memoryPath . '/' . $videoFilename;
        
        // Check if file already exists and add a counter if needed
        $counter = 1;
        while ($userFolder->nodeExists($targetPath)) {
            $videoFilename = "trip_video_{$trip['id']}_{$safeFilename}_{$counter}.mp4";
            $targetPath = $memoryPath . '/' . $videoFilename;
            $counter++;
        }
        
        // Copy the video to the user's files
        $fileContent = file_get_contents($videoPath);
        $file = $userFolder->newFile($targetPath);
        $file->putContent($fileContent);
        
        $this->logger->info("Stored video at {$targetPath}");
        
        return $targetPath;
    }

    /**
     * Delete a temporary directory and its contents
     */
    private function deleteTempDirectory(string $directory): void {
        if (!file_exists($directory)) {
            return;
        }
        
        $this->logger->info("Cleaning up temporary directory: {$directory}");
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        rmdir($directory);
    }

    /**
     * Get trip by ID
     */
    private function getTripById(int $tripId): ?array {
        $this->logger->info("Fetching trip details for id={$tripId}");
        
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('memories_trips')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($tripId, \PDO::PARAM_INT)))
           ->setMaxResults(1);
           
        $result = $qb->executeQuery();
        $trip = $result->fetch();
        $result->closeCursor();
        
        return $trip ?: null;
    }

    /**
     * @deprecated Use TripMediaService::getTripMedia instead
     */
    private function getTripMedia(int $tripId, string $userId, int $maxImages = 12): array {
        return $this->tripMediaService->getTripMedia($tripId, $userId, $maxImages);
    }

    /**
     * Prepare media files for video
     */
    private function prepareMediaFiles(array $mediaList, string $tempDir, string $userId, float $videoSegmentDuration = 3.0): array {
        $this->logger->info("Preparing media files, sequence: " . json_encode(array_column($mediaList, 'datetaken')));
        
        $processedFiles = [];
        
        foreach ($mediaList as $i => $media) {
            try {
                $this->logger->info("Processing media file {$i}: " . $media['path']);
                
                $userFolder = $this->rootFolder->getUserFolder($userId);
                $nodes = $userFolder->getById($media['fileid']);
                
                if (empty($nodes)) {
                    $this->logger->warning("File not found for fileid: " . $media['fileid']);
                    continue;
                }
                
                $node = $nodes[0];
                $mimeType = $node->getMimeType();
                $tempFilePath = $tempDir . '/original_' . ($mimeType === 'image/jpeg' ? 'image' : 'video') . '_' . $i . '.' . pathinfo($node->getName(), PATHINFO_EXTENSION);
                file_put_contents($tempFilePath, $node->getContent());
                
                if (strpos($mimeType, 'image/') === 0) {
                    // Process image
                    $processedFile = $tempDir . '/processed_image_' . $i . '.jpg';
                    
                    // Simply resize the image to fit the target dimensions while maintaining aspect ratio
                    $this->resizeImage($tempFilePath, $processedFile, 1920, 1080);
                    
                    $processedFiles[] = ['type' => 'image', 'path' => $processedFile, 'datetaken' => $media['datetaken']];
                } else {
                    // Process video - need to extract a segment
                    $videoDuration = $this->getVideoDuration($tempFilePath);
                    
                    // Select a segment
                    $bufferTime = min(1.0, $videoDuration * 0.1);
                    $maxStartTime = max(0, $videoDuration - $videoSegmentDuration - $bufferTime);
                    $startTime = $bufferTime;
                    
                    if ($maxStartTime > $bufferTime) {
                        $startTime = random_int((int)($bufferTime * 100), (int)($maxStartTime * 100)) / 100;
                    }
                    
                    // Extract segment - be more explicit about copying audio
                    $segmentPath = $tempDir . '/video_segment_' . $i . '.mp4';
                    $extractCmd = "ffmpeg -y -ss " . $startTime . " -i " . escapeshellarg($tempFilePath) . 
                               " -t " . $videoSegmentDuration . 
                               " -c:v libx264 -crf 23 -preset fast " . 
                               " -c:a aac -b:a 192k -ar 44100 " .  // Ensure consistent audio settings
                               escapeshellarg($segmentPath);
                    
                    $this->logger->info("Extracting video segment with command: " . $extractCmd);
                    exec($extractCmd . ' 2>&1', $output, $returnCode);
                    
                    if ($returnCode === 0 && file_exists($segmentPath) && filesize($segmentPath) > 1000) {
                        // Verify audio was copied
                        $checkAudioCmd = "ffprobe -v error -select_streams a -show_streams " . escapeshellarg($segmentPath) . " 2>&1";
                        exec($checkAudioCmd, $audioOutput, $audioReturnCode);
                        $hasAudio = !empty($audioOutput);
                        $this->logger->info("Segment audio check: " . ($hasAudio ? "Has audio" : "No audio"));
                        
                        $processedFiles[] = ['type' => 'video', 'path' => $segmentPath, 'datetaken' => $media['datetaken'], 'has_audio' => $hasAudio];
                    } else {
                        $this->logger->warning("Failed to extract video segment: " . implode("\n", $output));
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning("Error processing media file {$i}: " . $e->getMessage());
            }
        }
        
        return $processedFiles;
    }
    
    /**
     * Get video duration using ffprobe
     */
    private function getVideoDuration(string $videoPath): float
    {
        $ffprobeCmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . 
                    escapeshellarg($videoPath) . " 2>&1";
                    
        exec($ffprobeCmd, $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output) && is_numeric(trim($output[0]))) {
            return (float)trim($output[0]);
        } else {
            $this->logger->warning("Failed to get video duration: " . implode("\n", $output));
            return 10.0; // Default to 10 seconds if we can't determine
        }
    }

    /**
     * Resize an image while maintaining aspect ratio
     */
    private function resizeImage(string $inputPath, string $outputPath, int $width, int $height): bool {
        $cmd = "ffmpeg -y -i " . escapeshellarg($inputPath) . 
              " -vf \"scale={$width}:{$height}:force_original_aspect_ratio=decrease,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2\" " . 
              escapeshellarg($outputPath);
              
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        return $returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 1000;
    }

    /**
     * Get the root directory for music cache
     */
    private function getRootMusicCacheDir(): string {
        $dataDir = $this->config->getSystemValue('datadirectory', '/var/www/html/data');
        $appDataDir = $dataDir . '/appdata_' . $this->config->getSystemValue('instanceid') . '/memories/music_cache';
        return $appDataDir;
    }
    
    /**
     * Create a portrait image with blurred edges to fill the video frame
     */
    private function createPortraitWithBlurredEdges(string $inputPath, string $outputPath, int $targetWidth, int $targetHeight): bool
    {
        $this->logger->info("Processing portrait image with blurred edges: {$inputPath}");
        
        // Calculate scaled dimensions for portrait image
        $imageInfo = getimagesize($inputPath);
        if (!$imageInfo) {
            $this->logger->warning("Could not get image info for portrait image");
            return false;
        }
        
        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        
        $this->logger->info("Portrait image dimensions: {$originalWidth}x{$originalHeight}");
        
        // Calculate scale factor to fit height while maintaining aspect ratio
        $scaleFactor = $targetHeight / $originalHeight;
        $scaledWidth = (int)($originalWidth * $scaleFactor);
        
        $this->logger->info("Scaled dimensions: {$scaledWidth}x{$targetHeight}");
        
        // Create a blurred version of the full image for the background
        $tempBlurredFullPath = pathinfo($outputPath, PATHINFO_DIRNAME) . '/temp_blurred_full_' . basename($outputPath);
        $blurFullCmd = "ffmpeg -y -i " . escapeshellarg($inputPath) . 
                     " -vf \"scale={$targetWidth}:{$targetHeight},boxblur=20:5\" " . 
                     escapeshellarg($tempBlurredFullPath);
        
        $this->logger->info("Creating full blurred background: {$blurFullCmd}");
        exec($blurFullCmd . ' 2>&1', $blurFullOutput, $blurFullReturnCode);
        
        if ($blurFullReturnCode !== 0) {
            $this->logger->warning("Error creating full blurred background: " . implode("\n", $blurFullOutput));
            // Fall back to basic resize if blurring fails
            return $this->resizeImage($inputPath, $outputPath, $targetWidth, $targetHeight);
        }
        
        // Create the scaled version of the portrait image
        $tempScaledPath = pathinfo($outputPath, PATHINFO_DIRNAME) . '/temp_scaled_' . basename($outputPath);
        
        // Use scale filtering to maintain aspect ratio and center it vertically
        $scaleCmd = "ffmpeg -y -i " . escapeshellarg($inputPath) . 
                  " -vf \"scale={$scaledWidth}:{$targetHeight}\" " . 
                  escapeshellarg($tempScaledPath);
        
        $this->logger->info("Scaling portrait image: {$scaleCmd}");
        exec($scaleCmd . ' 2>&1', $scaleOutput, $scaleReturnCode);
        
        if ($scaleReturnCode !== 0) {
            $this->logger->warning("Error scaling portrait image: " . implode("\n", $scaleOutput));
            // Fall back to basic resize if scaling fails
            if (file_exists($tempBlurredFullPath)) unlink($tempBlurredFullPath);
            return $this->resizeImage($inputPath, $outputPath, $targetWidth, $targetHeight);
        }
        
        // Calculate padding for centering (should be half the remaining width)
        $leftPadding = ($targetWidth - $scaledWidth) / 2;
        $this->logger->info("Left padding for centering: {$leftPadding}");
        
        // Combine background and portrait image
        $overlayCmd = "ffmpeg -y -i " . escapeshellarg($tempBlurredFullPath) . 
                    " -i " . escapeshellarg($tempScaledPath) . 
                    " -filter_complex \"[0:v][1:v]overlay={$leftPadding}:0\" " . 
                    escapeshellarg($outputPath);
        
        $this->logger->info("Overlaying scaled image on blurred background: {$overlayCmd}");
        exec($overlayCmd . ' 2>&1', $overlayOutput, $overlayReturnCode);
        
        // Clean up temp files
        if (file_exists($tempScaledPath)) {
            unlink($tempScaledPath);
        }
        
        if (file_exists($tempBlurredFullPath)) {
            unlink($tempBlurredFullPath);
        }
        
        if ($overlayReturnCode !== 0) {
            $this->logger->warning("Error overlaying portrait image: " . implode("\n", $overlayOutput));
            return $this->resizeImage($inputPath, $outputPath, $targetWidth, $targetHeight);
        }
        
        return true;
    }
}

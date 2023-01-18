<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Command;

use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VideoSetup extends Command
{
    protected IConfig $config;
    protected OutputInterface $output;

    public function __construct(
        IConfig $config
    ) {
        parent::__construct();
        $this->config = $config;
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:video-setup')
            ->setDescription('Setup video streaming')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Preset executables
        $ffmpegPath = $this->config->getSystemValue('memories.ffmpeg_path', 'ffmpeg');
        if ('ffmpeg' === $ffmpegPath) {
            $ffmpegPath = trim(shell_exec('which ffmpeg') ?: 'ffmpeg');
            $this->config->setSystemValue('memories.ffmpeg_path', $ffmpegPath);
        }
        $ffprobePath = $this->config->getSystemValue('memories.ffprobe_path', 'ffprobe');
        if ('ffprobe' === $ffprobePath) {
            $ffprobePath = trim(shell_exec('which ffprobe') ?: 'ffprobe');
            $this->config->setSystemValue('memories.ffprobe_path', $ffprobePath);
        }

        // Get ffmpeg version
        $ffmpeg = shell_exec("{$ffmpegPath} -version") ?: '';
        if (false === strpos($ffmpeg, 'ffmpeg version')) {
            $ffmpeg = null;
            $output->writeln('<error>ffmpeg is not installed</error>');
        } else {
            $output->writeln('ffmpeg is installed');
        }

        // Get ffprobe version
        $ffprobe = shell_exec("{$ffprobePath} -version") ?: '';
        if (false === strpos($ffprobe, 'ffprobe version')) {
            $ffprobe = null;
            $output->writeln('<error>ffprobe is not installed</error>');
        } else {
            $output->writeln('ffprobe is installed');
        }

        if (null === $ffmpeg || null === $ffprobe) {
            $output->writeln('ffmpeg and ffprobe are required for video transcoding');

            return $this->suggestDisable($output);
        }

        // Check go-vod binary
        $output->writeln('Checking for go-vod binary');
        $goVodPath = $this->config->getSystemValue('memories.transcoder', false);

        if (!\is_string($goVodPath) || !file_exists($goVodPath)) {
            // Detect architecture
            $arch = \OCA\Memories\Util::getArch();
            $goVodPath = realpath(__DIR__."/../../exiftool-bin/go-vod-{$arch}");

            if (!$goVodPath) {
                $output->writeln('<error>Compatible go-vod binary not found</error>');
                $this->suggestGoVod($output);

                return $this->suggestDisable($output);
            }
        }

        $output->writeln("Trying go-vod from {$goVodPath}");
        chmod($goVodPath, 0755);

        $goVod = shell_exec($goVodPath.' test');
        if (!$goVod || false === strpos($goVod, 'test successful')) {
            $output->writeln('<error>go-vod could not be run</error>');
            $this->suggestGoVod($output);

            return $this->suggestDisable($output);
        }

        // Go transcode is working. Yay!
        $output->writeln('go-vod is installed!');
        $output->writeln('');
        $output->writeln('You can use transcoding and HLS streaming');
        $output->writeln('This is recommended for better performance, but has implications if');
        $output->writeln('you are using external storage or run Nextcloud on a slow system.');
        $output->writeln('');
        $output->writeln('Read the following documentation carefully before continuing:');
        $output->writeln('https://github.com/pulsejet/memories/wiki/Configuration');
        $output->writeln('');
        $output->writeln('Do you want to enable transcoding and HLS? [Y/n]');

        if ('n' === trim(fgets(fopen('php://stdin', 'r')))) {
            $this->config->setSystemValue('memories.no_transcode', true);
            $output->writeln('<error>Transcoding and HLS are now disabled</error>');

            return 0;
        }

        $this->config->setSystemValue('memories.transcoder', $goVodPath);
        $this->config->setSystemValue('memories.no_transcode', false);
        $output->writeln('Transcoding and HLS are now enabled! Monitor the output at /tmp/go-vod.log for any errors');
        $output->writeln('You should restart the server for changes to take effect');

        // Check for VAAPI
        $output->writeln("\nChecking for QSV (/dev/dri/renderD128)");
        if (file_exists('/dev/dri/renderD128')) {
            $output->writeln('QSV is available. Do you want to enable it? [Y/n]');

            if ('n' === trim(fgets(fopen('php://stdin', 'r')))) {
                $this->config->setSystemValue('memories.qsv', false);
                $output->writeln('QSV is now disabled');
            } else {
                $output->writeln("\nQSV is now enabled. You may still need to install the Intel Media Driver");
                $output->writeln('and ensure proper permissions for /dev/dri/renderD128.');
                $output->writeln('See the documentation for more details.');
                $this->config->setSystemValue('memories.qsv', true);
            }
        } else {
            $output->writeln('QSV is not available');
            $this->config->setSystemValue('memories.qsv', false);
        }

        return 0;
    }

    protected function suggestGoVod(OutputInterface $output): void
    {
        $output->writeln('You may build go-vod from source');
        $output->writeln('It can be downloaded from https://github.com/pulsejet/go-vod');
        $output->writeln('Once built, point the path to the binary in the config for `memories.transcoder`');
    }

    protected function suggestDisable(OutputInterface $output)
    {
        $output->writeln('Without transcoding, video playback may be slow and limited');
        $output->writeln('Do you want to disable transcoding and HLS streaming? [y/N]');
        if ('y' !== trim(fgets(fopen('php://stdin', 'r')))) {
            $output->writeln('Aborting');

            return 1;
        }

        $this->config->setSystemValue('memories.no_transcode', true);
        $output->writeln('<error>Transcoding and HLS are now disabled</error>');
        $output->writeln('You should restart the server for changes to take effect');

        return 0;
    }
}

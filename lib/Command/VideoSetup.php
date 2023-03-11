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
    protected string $sampleFile;
    protected string $logFile;

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
        $this->output = $output;

        // Preset executables
        $ffmpegPath = $this->config->getSystemValue('memories.vod.ffmpeg', 'ffmpeg');
        if ('ffmpeg' === $ffmpegPath) {
            $ffmpegPath = trim(shell_exec('which ffmpeg') ?: 'ffmpeg');
            $this->config->setSystemValue('memories.vod.ffmpeg', $ffmpegPath);
        }
        $ffprobePath = $this->config->getSystemValue('memories.vod.ffprobe', 'ffprobe');
        if ('ffprobe' === $ffprobePath) {
            $ffprobePath = trim(shell_exec('which ffprobe') ?: 'ffprobe');
            $this->config->setSystemValue('memories.vod.ffprobe', $ffprobePath);
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

            return $this->suggestDisable();
        }

        // Check go-vod binary
        $output->writeln('Checking for go-vod binary');
        $goVodPath = $this->config->getSystemValue('memories.vod.path', false);

        if (!\is_string($goVodPath) || !file_exists($goVodPath)) {
            // Detect architecture
            $arch = \OCA\Memories\Util::getArch();
            $goVodPath = realpath(__DIR__."/../../exiftool-bin/go-vod-{$arch}");

            if (!$goVodPath) {
                $output->writeln('<error>Compatible go-vod binary not found</error>');
                $this->suggestGoVod();

                return $this->suggestDisable();
            }
        }

        $output->writeln("Trying go-vod from {$goVodPath}");
        chmod($goVodPath, 0755);

        $goVod = shell_exec($goVodPath.' test');
        if (!$goVod || false === strpos($goVod, 'test successful')) {
            $output->writeln('<error>go-vod could not be run</error>');
            $this->suggestGoVod();

            return $this->suggestDisable();
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
            $this->config->setSystemValue('memories.vod.disable', true);
            $output->writeln('<error>Transcoding and HLS are now disabled</error>');

            $this->killGoVod($goVodPath);

            return 0;
        }

        $this->config->setSystemValue('memories.vod.path', $goVodPath);
        $this->config->setSystemValue('memories.vod.disable', false);

        // Feature detection
        $this->detectFeatures();

        // Success
        $output->writeln("\nTranscoding and HLS are now enabled! Monitor the log file for any errors");
        $output->writeln('<error>You should restart the server for changes to take effect</error>');

        $this->killGoVod();

        return 0;
    }

    protected function suggestGoVod(): void
    {
        $this->output->writeln('You may build go-vod from source');
        $this->output->writeln('It can be downloaded from https://github.com/pulsejet/go-vod');
        $this->output->writeln('Once built, point the path to the binary in the config for `memories.vod.path`');
    }

    protected function suggestDisable()
    {
        $this->output->writeln('Without transcoding, video playback may be slow and limited');
        $this->output->writeln('Do you want to disable transcoding and HLS streaming? [y/N]');
        if ('y' !== trim(fgets(fopen('php://stdin', 'r')))) {
            $this->output->writeln('Aborting');

            return 1;
        }

        $this->config->setSystemValue('memories.vod.disable', true);
        $this->output->writeln('<error>Transcoding and HLS are now disabled</error>');
        $this->output->writeln('You should restart the server for changes to take effect');

        return 0;
    }

    protected function detectFeatures()
    {
        // Reset the current configuration
        $this->config->deleteSystemValue('memories.vod.vaapi');
        $this->config->deleteSystemValue('memories.vod.nvenc');

        $this->output->writeln("\nStarting ffmpeg feature detection");
        $this->output->writeln('This may take a while. Please be patient');

        try {
            // Download test file
            $this->output->write("\nDownloading test video file ... ");
            $this->sampleFile = $this->downloadSampleFile();
            if (!file_exists($this->sampleFile)) {
                $this->output->writeln('FAIL');
                $this->output->writeln('<error>Could not download sample file</error>');
                $this->output->writeln('<error>Failed to perform feature detection</error>');

                return;
            }
            $this->output->writeln('OK');

            // Start go-vod
            if (!$this->startGoVod()) {
                return;
            }

            $this->checkCPU();
            $this->checkVAAPI();

            // We need to turn off VAAPI before checking NVENC
            $wasVaapi = $this->config->getSystemValue('memories.vod.vaapi', false);
            $this->config->deleteSystemValue('memories.vod.vaapi');
            $this->checkNVENC();

            // Restore VAAPI configuration
            if ($wasVaapi) {
                $this->config->setSystemValue('memories.vod.vaapi', true);
            }
        } finally {
            if (file_exists($this->sampleFile)) {
                unlink($this->sampleFile);
            }
        }

        $this->output->writeln("\nFeature detection completed");
    }

    protected function checkCPU()
    {
        $this->output->writeln('');
        $this->testResult('CPU');
    }

    protected function checkVAAPI()
    {
        // Reset current configuration
        $this->config->deleteSystemValue('memories.vod.vaapi');
        $this->config->deleteSystemValue('memories.vod.vaapi.low_power');

        // Check for VAAPI
        $this->output->write("\nChecking for VAAPI acceleration (/dev/dri/renderD128) ... ");
        if (!file_exists('/dev/dri/renderD128')) {
            $this->output->writeln('NOT FOUND');
            $this->config->deleteSystemValue('memories.vod.vaapi');

            return;
        }
        $this->output->writeln('OK');

        // Check permissions
        $this->output->write('Checking for permissions on /dev/dri/renderD128 ... ');
        if (!is_readable('/dev/dri/renderD128')) {
            $this->output->writeln('NO');
            $this->output->writeln('<error>Current user does not have read permissions on /dev/dri/renderD128</error>');
            $this->output->writeln('VAAPI will not work. You may need to add your user to the video/render groups');
            $this->config->deleteSystemValue('memories.vod.vaapi');

            return;
        }
        $this->output->writeln('OK');

        // Try enabling VAAPI
        $this->config->setSystemValue('memories.vod.vaapi', true);
        $basic = $this->testResult('VAAPI');

        // Try with low_power
        $this->config->setSystemValue('memories.vod.vaapi.low_power', true);
        $lowPower = $this->testResult('VAAPI (low_power)');
        if (!$lowPower) {
            $this->config->deleteSystemValue('memories.vod.vaapi.low_power');
        }

        // Check if passed any test
        if (!$basic && !$lowPower) {
            $this->config->deleteSystemValue('memories.vod.vaapi');

            return;
        }

        // Everything is good
        $this->output->write('Do you want to enable VAAPI acceleration? [Y/n] ');
        if ('n' === trim(fgets(fopen('php://stdin', 'r')))) {
            $this->config->setSystemValue('memories.vod.vaapi', false);
            $this->output->writeln('VAAPI is now disabled');
        } else {
            $this->output->writeln("\nVAAPI is now enabled. You may still need to install the Intel Media Driver");
            $this->output->writeln('and ensure proper permissions for /dev/dri/renderD128.');
            $this->output->writeln('See the documentation for more details.');
            $this->config->setSystemValue('memories.vod.vaapi', true);
        }
    }

    protected function checkNVENC()
    {
        $this->output->writeln("\nChecking for NVIDIA acceleration with NVENC");

        // Reset the current configuration
        $this->config->deleteSystemValue('memories.vod.nvenc.temporal_aq');
        $this->config->deleteSystemValue('memories.vod.nvenc.scale');

        // Basic test
        $this->config->setSystemValue('memories.vod.nvenc', true);

        // Different scaling methods
        $this->config->setSystemValue('memories.vod.nvenc.scale', 'npp');
        $withScaleNpp = $this->testResult('NVENC (scale_npp)', true);
        $this->config->setSystemValue('memories.vod.nvenc.scale', 'cuda');
        $withScaleCuda = $this->testResult('NVENC (scale_cuda)', true);

        if (!$withScaleNpp && !$withScaleCuda) {
            $this->config->deleteSystemValue('memories.vod.nvenc');
            $this->config->deleteSystemValue('memories.vod.nvenc.scale');
            $this->output->writeln('NVENC does not seem to be available');

            return;
        }
        if ($withScaleNpp) {
            $this->config->setSystemValue('memories.vod.nvenc.scale', 'npp');
        } elseif ($withScaleCuda) {
            $this->config->setSystemValue('memories.vod.nvenc.scale', 'cuda');
        }

        // Try with temporal-aq
        $this->config->setSystemValue('memories.vod.nvenc.temporal_aq', true);
        if (!$this->testResult('NVENC (temporal-aq)', true)) {
            $this->config->deleteSystemValue('memories.vod.nvenc.temporal_aq');
        }

        // Good to go
        $this->output->write('Do you want to enable NVENC acceleration? [Y/n] ');
        if ('n' === trim(fgets(fopen('php://stdin', 'r')))) {
            $this->config->setSystemValue('memories.vod.nvenc', false);
            $this->output->writeln('NVENC is now disabled');
        } else {
            $this->output->writeln('NVENC transcoding is now enabled');
        }
    }

    protected function test(): void
    {
        $url = \OCA\Memories\Controller\VideoController::getGoVodUrl('test', $this->sampleFile, '360p-000001.ts');

        // Make a GET request
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Check for errors
        if (curl_errno($ch)) {
            throw new \Exception('Curl: '.curl_error($ch));
        }

        // Check for 200
        if (200 !== $httpCode) {
            throw new \Exception('HTTP status: '.$httpCode);
        }

        // Check response size is greater than 10kb
        if (\strlen($response) < 10240) {
            throw new \Exception('Response size is too small');
        }
    }

    private function testResult(string $name, bool $minor = false): bool
    {
        $this->output->write("Testing transcoding with {$name} ... ");

        try {
            $this->restartGoVod($this->output);
            $this->test();
            $this->output->writeln('OK');

            return true;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $logFile = $this->logFile;
            $this->output->writeln('FAIL');
            if (!$minor) {
                $this->output->writeln("<error>{$name} transcoding failed with error {$msg}</error>");
                $this->output->writeln("Check the log file of go-vod for more details ({$logFile})");
            }

            return false;
        }
    }

    private function startGoVod(bool $suppress = false): bool
    {
        if (!$suppress) {
            $this->output->write("\nAttempting to start go-vod ... ");
        }

        try {
            $this->logFile = $logFile = \OCA\Memories\Controller\VideoController::startGoVod();
            if (!$suppress) {
                $this->output->writeln('OK');
                $this->output->writeln("go-vod logs will be stored at: {$logFile}");
            }

            return true;
        } catch (\Exception $e) {
            if (!$suppress) {
                $this->output->writeln('FAIL');
            } else {
                $this->output->writeln('<error>Failed to (re-)start go-vod</error>');
            }
            $this->output->writeln($e->getMessage());

            return false;
        }
    }

    private function killGoVod(string $path = ''): void
    {
        if ('' === $path) {
            $path = $this->config->getSystemValue('memories.vod.path');
        }

        \OCA\Memories\Util::pkill($path);
    }

    private function restartGoVod(): void
    {
        $this->killGoVod();
        sleep(1);
        $this->startGoVod(true);
    }

    private function downloadSampleFile(): string
    {
        $sampleFile = tempnam(sys_get_temp_dir(), 'sample.mp4');
        $fp = fopen($sampleFile, 'w+');
        $ch = curl_init('https://github.com/pulsejet/memories-assets/raw/main/sample.mp4');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        return $sampleFile;
    }
}

<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

final class BinExt
{
    public const EXIFTOOL_VER = '13.59';
    public const GOVOD_VER = '0.6.0';
    public const NX_VER_MIN = '1.1';

    private const GO_VOD_PID_FILE = '/tmp/go-vod.pid';
    private const GO_VOD_LOCK_FILE = '/tmp/go-vod.lock';

    /** Exiftool environment is initialized in this process */
    private bool $hasExiftoolEnv = false;

    public function __construct(
        private SystemConfig $systemConfig,
        private IConfig $config,
        private IClientService $clientService,
    ) {}

    /** Get the path to the temp directory */
    public function getTmpPath(): string
    {
        $path = $this->systemConfig->get('memories.exiftool.tmp');

        return rtrim($path ?: sys_get_temp_dir(), '/');
    }

    /** Copy a binary to temp dir for execution */
    public function getTempBin(string $path, string $name, bool $copy = true): string
    {
        // Bust cache if the path changes
        $suffix = hash('crc32', $path);

        // Check target temp file
        $target = $this->getTmpPath().'/'.$name.'-'.$suffix;
        if (file_exists($target)) {
            if (!is_executable($target) && !chmod($target, 0o755)) {
                throw new \Exception("failed to make {$name} temp binary executable: {$target}");
            }

            return $target;
        }

        if ($copy) {
            if (empty($path)) {
                throw new \Exception('binary path is empty (run occ maintenance:repair or use system perl)');
            }

            if (!copy($path, $target)) {
                throw new \Exception("failed to copy {$name} binary from {$path} to {$target}");
            }

            return $this->getTempBin($path, $name, false);
        }

        throw new \Exception("failed to find exiftool temp binary {$target}");
    }

    /** Get the name for a binary */
    public function getName(string $name, string $version = ''): string
    {
        $id = $this->systemConfig->get('instanceid');

        return empty($version) ? "{$name}-{$id}" : "{$name}-{$id}-{$version}";
    }

    /** Test configured exiftool binary */
    public function testExiftool(): string
    {
        $cmd = array_merge($this->getExiftool(), ['-ver']);

        $out = Util::execSafe($cmd, 3000);
        if (!$out) {
            throw new \Exception('failed to run exiftool: '.implode(' ', $cmd));
        }

        // Check version
        $version = trim($out);
        $target = self::EXIFTOOL_VER;
        if (!version_compare($version, $target, '=')) {
            throw new \Exception("exiftool version does not match: expected {$target} but found {$version}");
        }

        // Test with actual file
        $file = realpath(__DIR__.'/../../exiftest.jpg');
        if (!$file) {
            throw new \Exception('Could not find EXIF test file');
        }

        try {
            $dateTaken = trim((string) Util::execSafe(array_merge($this->getExiftool(), [
                '-n', '-s', '-s', '-s', '-DateTimeOriginal', $file,
            ]), 3000));
        } catch (\Exception $e) {
            throw new \Exception("Couldn't read Exif data from test file: ".$e->getMessage());
        }

        if ('' === $dateTaken) {
            throw new \Exception('Got no Exif data from test file');
        }

        if (($exp = '2004:08:31 19:52:58') !== ($got = $dateTaken)) {
            throw new \Exception("Got wrong Exif data from test file {$exp} <==> {$got}");
        }

        return $version;
    }

    /** Get path to exiftool binary */
    public function getExiftoolPBin(): string
    {
        $path = $this->systemConfig->get('memories.exiftool');

        $path = $this->getTempBin($path, $this->getName('exiftool', self::EXIFTOOL_VER));

        // Explicitly set the PAR directory to avoid cache collisions
        // https://github.com/pulsejet/memories/issues/1608
        putenv("PAR_GLOBAL_TEMP={$path}.out");

        return $path;
    }

    /**
     * Get path to exiftool binary for proc_open.
     *
     * @return string[]
     */
    public function getExiftool(): array
    {
        if (!$this->hasExiftoolEnv) {
            $this->hasExiftoolEnv = true;
            putenv('LANG=C'); // set perl lang to suppress warning
        }

        if ($this->systemConfig->get('memories.exiftool_no_local')) {
            $path = realpath(__DIR__.'/../../bin-ext/exiftool/exiftool') ?: '';

            return ['perl', $path];
        }

        return [$this->getExiftoolPBin()];
    }

    /**
     * Detect the exiftool binary to use.
     */
    public function detectExiftool(): false|string
    {
        if (!empty($path = $this->systemConfig->get('memories.exiftool')) && file_exists($path)) {
            return $path;
        }

        if ($this->systemConfig->get('memories.exiftool_no_local')) {
            return implode(' ', $this->getExiftool());
        }

        // Detect architecture
        $arch = \OCA\Memories\Util::getArch();
        $libc = \OCA\Memories\Util::getLibc();

        // Get static binary if available
        if ($arch && $libc) {
            // get target file path
            $path = realpath(__DIR__."/../../bin-ext/exiftool-{$arch}-{$libc}");

            // make sure it exists
            if ($path && file_exists($path)) {
                $this->systemConfig->set('memories.exiftool', $path);

                return $path;
            }
        }

        $this->systemConfig->set('memories.exiftool_no_local', true);

        return false;
    }

    /** Get all configured go-vod servers. */
    public function getGoVodServers(): array
    {
        $bind = $this->systemConfig->get('memories.vod.bind');
        if (!$this->systemConfig->get('memories.vod.external')) {
            return [$bind];
        }

        return $this->systemConfig->get('memories.vod.connect') ?: [$bind];
    }

    /** Get the upstream URL for a go-vod API (vod, create). */
    public function getGoVodEndpoint(string $client, string $endpoint): string
    {
        $servers = $this->getGoVodServers();

        // Sticky-route each client to one server so go-vod state stays local
        $srv = $servers[(crc32($client) & 0xFFFFFFFF) % \count($servers)];

        return "http://{$srv}/{$endpoint}";
    }

    public function goVodTConfig(): array
    {
        // Get config from system values
        return [
            'chunkSize' => 3,
            'qf' => $this->systemConfig->get('memories.vod.qf'),

            'vaapi' => $this->systemConfig->get('memories.vod.vaapi'),
            'vaapiLowPower' => $this->systemConfig->get('memories.vod.vaapi.low_power'),
            'vaapiDevice' => $this->systemConfig->get('memories.vod.vaapi.device'),

            'nvenc' => $this->systemConfig->get('memories.vod.nvenc'),
            'nvencTemporalAQ' => $this->systemConfig->get('memories.vod.nvenc.temporal_aq'),
            'nvencScale' => $this->systemConfig->get('memories.vod.nvenc.scale'),

            'useTranspose' => $this->systemConfig->get('memories.vod.use_transpose'),
            'forceSwTranspose' => $this->systemConfig->get('memories.vod.use_transpose.force_sw'),
            'useGopSize' => $this->systemConfig->get('memories.vod.use_gop_size'),
        ];
    }

    public function goVodServerConfig(): array
    {
        $dir = function (string $key, string $default): string {
            return rtrim($this->systemConfig->get($key, $default), '/')
                .'/'.trim((string) $this->systemConfig->get('instanceid'), '/');
        };

        return [
            'bind' => $this->systemConfig->get('memories.vod.bind'),
            'ffmpeg' => $this->systemConfig->get('memories.vod.ffmpeg'),
            'ffprobe' => $this->systemConfig->get('memories.vod.ffprobe'),
            'tempdir' => $dir('memories.vod.tempdir', sys_get_temp_dir().'/go-vod/'),
            'cacheDir' => $dir('memories.vod.cachedir', sys_get_temp_dir().'/go-vod-cache'),
            'nextcloudUrl' => rtrim($this->config->getSystemValueString('overwrite.cli.url', ''), '/'),
        ];
    }

    /**
     * Get temp binary for go-vod.
     */
    public function getGoVodBin(): string
    {
        $path = $this->systemConfig->get('memories.vod.path');

        return $this->getTempBin($path, $this->getName('go-vod', self::GOVOD_VER));
    }

    public function ensureGoVod(): void
    {
        if ($this->systemConfig->get('memories.vod.disable') || $this->systemConfig->get('memories.vod.external')) {
            return;
        }

        if ($this->isGoVodAlive()) {
            return;
        }

        // Serialize concurrent starters (e.g. parallel segment requests)
        $lock = @fopen(self::GO_VOD_LOCK_FILE, 'c');
        if (false !== $lock) {
            flock($lock, LOCK_EX);
        }

        try {
            // Re-check after acquiring the lock
            if ($this->isGoVodAlive()) {
                return;
            }

            // Get transcoder path
            $transcoder = $this->getGoVodBin();
            if (empty($transcoder)) {
                throw new \Exception('Transcoder not configured');
            }

            // Get local server config
            $env = $this->goVodServerConfig();
            $tmpPath = $env['tempdir'];

            // (Re-)create temp dir
            Util::execSafe(['rm', '-rf', $tmpPath], 3000);
            mkdir($tmpPath, 0o755, true);

            // Check temp directory exists
            if (!is_dir($tmpPath)) {
                throw new \Exception("Temp directory could not be created ({$tmpPath})");
            }

            // Check temp directory is writable
            if (!is_writable($tmpPath)) {
                throw new \Exception("Temp directory is not writable ({$tmpPath})");
            }

            // Write config to file
            $logFile = $tmpPath.'.log';
            $configFile = $tmpPath.'.json';
            file_put_contents($configFile, json_encode($env, JSON_PRETTY_PRINT));

            // Kill the transcoder in case it's running
            $this->pkill($this->getName('go-vod'));

            // Spawn detached via shell backgrounding so init adopts go-vod.
            // An abandoned proc_open handle would leave a zombie under the PHP worker.
            $shell = \sprintf(
                'nohup %s %s >> %s 2>&1 & echo $!',
                escapeshellarg($transcoder),
                escapeshellarg($configFile),
                escapeshellarg($logFile),
            );

            $pipes = [];
            $proc = proc_open(['sh', '-c', $shell], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ], $pipes);

            $childPid = 0;
            if (\is_resource($proc)) {
                $childPid = (int) trim((string) stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                proc_close($proc); // reaps sh; go-vod is adopted by init
            }

            // Record the pid for liveness checks
            if ($childPid > 0) {
                @file_put_contents(self::GO_VOD_PID_FILE, (string) $childPid);
            }

            // wait for 500ms
            usleep(500000);
        } finally {
            if (false !== $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Test the go-vod instance that is running */
    public function testGoVod(string $server): string
    {
        // Check if disabled
        if ($this->systemConfig->get('memories.vod.disable')) {
            throw new \Exception('transcoding is disabled');
        }

        // Ensure transcoder is running
        $this->ensureGoVod();

        try {
            $res = $this->clientService->newClient()->get("http://{$server}/health", [
                'timeout' => 1,
                'connect_timeout' => 1,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Exception $e) {
            throw new \Exception('failed to connect: '.$e->getMessage());
        }

        // Parse body
        $json = json_decode((string) $res->getBody(), true);
        if (!$json) {
            throw new \Exception('failed to parse response');
        }

        // Check version
        $version = $json['version'];
        $target = self::GOVOD_VER;
        if (!version_compare($version, $target, '=')) {
            throw new \Exception("version does not match: expected {$target} but found {$version}");
        }

        return $version;
    }

    public function testGoVodBin(string $path): string
    {
        $version = Util::execSafe([$path, '-version'], 3000) ?: '';
        if (!preg_match('/go-vod (\S*)/', $version, $matches)) {
            throw new \Exception("failed to detect version, found {$version}");
        }

        $version = $matches[1];
        $target = self::GOVOD_VER;
        if (!version_compare($version, $target, '=')) {
            throw new \Exception("version does not match: expected {$target} but found {$version}");
        }

        return $version;
    }

    /**
     * Detect the go-vod binary to use.
     */
    public function detectGoVod(): false|string
    {
        $goVodPath = $this->systemConfig->get('memories.vod.path');

        if (empty($goVodPath) || !file_exists($goVodPath)) {
            // Detect architecture
            $arch = \OCA\Memories\Util::getArch();
            $path = __DIR__."/../../bin-ext/go-vod-{$arch}";
            $goVodPath = realpath($path);

            if (!$goVodPath) {
                return false;
            }

            // Set config
            $this->systemConfig->set('memories.vod.path', $goVodPath);

            // Make executable
            if (!is_executable($goVodPath)) {
                @chmod($goVodPath, 0o755);
            }
        }

        return $goVodPath;
    }

    public function detectFFmpeg(): ?string
    {
        $ffmpegPath = $this->systemConfig->get('memories.vod.ffmpeg');
        $ffprobePath = $this->systemConfig->get('memories.vod.ffprobe');

        if (empty($ffmpegPath) || !file_exists($ffmpegPath) || empty($ffprobePath) || !file_exists($ffprobePath)) {
            // Use PATH environment variable to find ffmpeg
            $ffmpegPath = Util::execSafe(['which', 'ffmpeg'], 3000);
            $ffprobePath = Util::execSafe(['which', 'ffprobe'], 3000);
            if (!$ffmpegPath || !$ffprobePath) {
                return null;
            }

            // Trim
            $ffmpegPath = trim($ffmpegPath);
            $ffprobePath = trim($ffprobePath);

            // Set config
            $this->systemConfig->set('memories.vod.ffmpeg', $ffmpegPath);
            $this->systemConfig->set('memories.vod.ffprobe', $ffprobePath);
        }

        // Check if executable
        if (!is_executable($ffmpegPath) || !is_executable($ffprobePath)) {
            return null;
        }

        return $ffmpegPath;
    }

    public function testFFmpeg(string $path, string $name): string
    {
        $version = Util::execSafe([$path, '-version'], 3000) ?: '';
        if (!preg_match("/{$name} version \\S*/", $version, $matches)) {
            throw new \Exception("failed to detect version, found {$version}");
        }

        return explode(' ', $matches[0])[2];
    }

    public function testSystemPerl(string $path): string
    {
        if (($out = Util::execSafe([$path, '-e', 'print "OK";'], 3000)) !== 'OK') {
            throw new \Exception('Failed to run test perl script: '.(string) $out);
        }

        return Util::execSafe([$path, '-e', 'print $^V;'], 3000) ?: 'unknown version';
    }

    /**
     * Kill all instances of a process by name.
     * Similar to pkill, which may not be available on all systems.
     *
     * @param string $name Process name (only the first 12 characters are used)
     */
    public function pkill(string $name): void
    {
        // don't kill everything
        if (empty($name)) {
            return;
        }

        // only use the first 12 characters
        $name = substr($name, 0, 12);

        // check if ps or busybox is available
        $ps = ['ps'];

        if (!Util::execSafe(['which', 'ps'], 1000)) {
            if (!Util::execSafe(['which', 'busybox'], 1000)) {
                return;
            }

            $ps = ['busybox', 'ps'];
        }

        $procs = Util::execSafe(array_merge($ps, ['-eao', 'pid,comm']), 1000) ?: '';
        $procs = explode("\n", $procs);

        $matches = array_filter($procs, static fn ($l) => str_contains($l, $name));
        $pids = array_map(static fn ($l) => (int) explode(' ', trim($l))[0], $matches);
        if (empty($pids)) {
            return;
        }

        foreach ($pids as $pid) {
            posix_kill($pid, 9); // SIGKILL
        }
    }

    /** Check if the local go-vod instance is alive */
    private function isGoVodAlive(): bool
    {
        $pid = (int) @file_get_contents(self::GO_VOD_PID_FILE);
        if ($pid <= 0) {
            return false;
        }

        // Fast path: Linux /proc
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (\is_string($stat) && false !== ($end = strrpos($stat, ')'))) {
            // Check process state (Z = zombie, X = dead)
            if (\in_array(trim(substr($stat, $end + 1, 2)), ['Z', 'X'], true)) {
                return false;
            }

            // Guard against PID reuse: make sure it is actually go-vod
            $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

            return \is_string($cmdline) && str_contains($cmdline, 'go-vod');
        }

        // Fallback: ps for systems without procfs (e.g. FreeBSD).
        // Only POSIX flags so this works on Linux, FreeBSD and macOS.
        try {
            $out = trim((string) Util::execSafe(['ps', '-o', 'stat=', '-o', 'command=', '-p', (string) $pid], 1000));
        } catch (\Exception) {
            return false;
        }
        if ('' === $out) {
            return false;
        }

        $parts = preg_split('/\s+/', $out, 2);
        if (!$parts || str_starts_with($parts[0], 'Z')) {
            return false;
        }

        return isset($parts[1]) && str_contains($parts[1], 'go-vod');
    }
}

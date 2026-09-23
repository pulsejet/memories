<?php

declare(strict_types=1);

namespace OCA\Memories\Settings;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Util;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\Config\IUserConfig;
use OCP\Encryption\IManager as EncryptionManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

final class SystemConfig
{
    // Do not change the next line, it's used by the docs
    // to generate the default config page
    public const DEFAULTS = [
        // Path to exiftool binary
        'memories.exiftool' => '',

        // Do not use packaged binaries of exiftool
        // This requires perl to be available
        'memories.exiftool_no_local' => false,

        // Temporary directory for non-php binaries. The directory must be writable
        // and the webserver user should be able to create executable binaries in it.
        // go-vod temp files are separately configured (memories.vod.tempdir)
        // Defaults to system temp directory if blank
        'memories.exiftool.tmp' => '',

        // How to index user directories
        // 0 = auto-index disabled
        // 1 = index everything
        // 2 = index only user timelines
        // 3 = index only configured path
        'memories.index.mode' => '1',

        // Path to index (only used if indexing mode is 3)
        'memories.index.path' => '/',

        // Blacklist file or folder paths by regex
        'memories.index.path.blacklist' => '\/@(Recycle|eaDir)\/',

        // Places database type identifier
        'memories.gis_type' => -1,

        // Base URL of the location search service used by the metadata editor.
        // Must be compatible with the Nominatim search API.
        // Set to an empty string to disable location search.
        'memories.places.search.url' => 'https://nominatim.openstreetmap.org',

        // Available map tile servers for the map view.
        // The first entry is the default for new users.
        'memories.map.tile_servers' => FreeTileServers::TILES,

        // Default timeline path for all users
        // If set to '_empty_', the user is prompted to select a path
        'memories.timeline.default_path' => '_empty_',

        // Default viewer high resolution image loading condition
        // Valid values: 'always' | 'zoom' | 'never'
        'memories.viewer.high_res_cond_default' => 'zoom',

        // Disable transcoding
        'memories.vod.disable' => true,

        // VA-API configuration options
        'memories.vod.vaapi' => false,  // Transcode with VA-API
        'memories.vod.vaapi.low_power' => false, // Use low_power mode for VA-API
        'memories.vod.vaapi.device' => '/dev/dri/renderD128', // VA-API render node

        // NVENC configuration options
        'memories.vod.nvenc' => false,  // Transcode with NVIDIA NVENC
        'memories.vod.nvenc.temporal_aq' => false,
        'memories.vod.nvenc.scale' => 'cuda', // cuda or npp

        // Extra streaming configuration
        'memories.vod.use_transpose' => false,
        'memories.vod.use_transpose.force_sw' => false,
        'memories.vod.use_gop_size' => false,

        // Paths to ffmpeg and ffprobe binaries
        'memories.vod.ffmpeg' => '',
        'memories.vod.ffprobe' => '',

        // Path to go-vod binary
        'memories.vod.path' => '',

        // Path to use for transcoded files (/tmp/go-vod/instanceid)
        // Make sure this has plenty of space
        'memories.vod.tempdir' => '',

        // Path for durable go-vod caches, defaults to system temp go-vod-cache
        'memories.vod.cachedir' => '',

        // Bind address to use when starting the transcoding server
        'memories.vod.bind' => '127.0.0.1:47788',

        // Transcoding servers to connect to in external mode.
        // Each client is sticky-routed to one server by hash.
        'memories.vod.connect' => ['127.0.0.1:47788'],

        // Mark go-vod as external. If true, Memories will not attempt to
        // start go-vod if it is not running already.
        'memories.vod.external' => false,

        // Quality Factor used for transcoding
        // This correspondes to CRF for x264 and global_quality for VA-API
        'memories.vod.qf' => 24,

        // Set the default video quality for a first time user
        //    0 => Auto (default)
        //   -1 => Original (max quality)
        // 1080 => 1080p (and so on)
        'memories.video_default_quality' => '0',

        // Base URL of the Lens daemon (empty = disabled)
        'memories.lens.daemon_url' => '',

        // UID of the dedicated Lens service account (empty = endpoint disabled)
        'memories.lens.service_user' => '',

        // Availability of database features, e.g. triggers
        'memories.db.triggers.fcu' => false,

        // Run in read-only config mode
        'memories.readonly' => false,

        // Memories only provides an admin interface for these
        'enabledPreviewProviders' => [],
        'preview_max_x' => 4096,
        'preview_max_y' => 4096,
        'preview_max_memory' => 128,
        'preview_max_filesize_image' => 50,
        'preview_ffmpeg_path' => '',
        'default_timezone' => '',

        // Placeholders only; these are not touched by the app
        'instanceid' => 'default',
        'debug' => false,
    ];

    public function __construct(
        private IConfig $config,
        private IUserConfig $userConfig,
        private IRequest $request,
        private IAppManager $appManager,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private EncryptionManager $encryptionManager,
    ) {}

    /**
     * Get a system config key with the correct default.
     *
     * @param string $key     System config key
     * @param mixed  $default Default value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!\array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Invalid system config key: {$key}");
        }

        // Use the default value if not provided
        $default ??= self::DEFAULTS[$key];

        // Get the value from the config
        $value = $this->config->getSystemValue($key, $default);

        // Check if the value has the correct type
        if (($got = \gettype($value)) !== ($exp = \gettype($default))) {
            throw new \InvalidArgumentException("Invalid type for system config {$key}, expected {$exp}, got {$got}");
        }

        return $value;
    }

    /**
     * Set a system config key.
     *
     * @param string $key   System config key
     * @param mixed  $value Value to set
     *
     * @throws \InvalidArgumentException
     */
    public function set(string $key, mixed $value): void
    {
        // Check if the key is valid
        if (!\array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Invalid system config key: {$key}");
        }

        // Key belongs to memories namespace
        $isAppKey = str_starts_with($key, Application::APPNAME.'.');

        // Check if the value has the correct type
        if (null !== $value && ($got = \gettype($value)) !== ($exp = \gettype(self::DEFAULTS[$key]))) {
            throw new \InvalidArgumentException("Invalid type for system config {$key}, expected {$exp}, got {$got}");
        }

        // Do not allow null for non-app keys
        if (!$isAppKey && null === $value) {
            throw new \InvalidArgumentException("Invalid value for system config {$key}, null is not allowed");
        }

        $config = $this->config;
        if ($isAppKey && ($value === self::DEFAULTS[$key] || null === $value)) {
            $config->deleteSystemValue($key);
        } else {
            $config->setSystemValue($key, $value);
        }
    }

    /**
     * Check if geolocation (places) is enabled and available.
     * Returns the type of the GIS.
     */
    public function gisType(): int
    {
        return $this->get('memories.gis_type');
    }

    /**
     * Get list of timeline paths as array.
     *
     * @return string[] List of paths
     */
    public function getTimelinePaths(string $uid): array
    {
        $paths = $this->userConfig
            ->getValueString($uid, Application::APPNAME, 'timelinePath')
                ?: $this->get('memories.timeline.default_path');

        if ($this->get('debug')) {
            $override = $this->request->getHeader('X-TIMELINE-PATH');
            if (!empty($override)) {
                $paths = $override;
            }
        }

        return array_map(
            static fn ($path) => Util::sanitizePath(trim($path))
                ?? throw new \InvalidArgumentException("Invalid timeline path: {$path}"),
            explode(';', $paths),
        );
    }

    /** Check if albums are enabled for this user */
    public function albumsIsEnabled(): bool
    {
        return $this->appManager->isEnabledForUser('photos');
    }

    /** Check if tags is enabled for this user */
    public function tagsIsEnabled(): bool
    {
        return $this->appManager->isEnabledForUser('systemtags');
    }

    /** Check if recognize is enabled for this user */
    public function recognizeIsEnabled(): bool
    {
        if (!$this->recognizeIsInstalled()) {
            return false;
        }

        if ('true' !== $this->appConfig->getValueString('recognize', 'faces.enabled', 'false')) {
            return false;
        }

        return true;
    }

    /** Check if recognize is installed */
    public function recognizeIsInstalled(): bool
    {
        if (!$this->appManager->isEnabledForUser('recognize')) {
            return false;
        }

        $v = $this->appManager->getAppVersion('recognize');

        return version_compare($v, '3.8.0', '>=');
    }

    /** Check if Face Recognition is enabled by the user */
    public function facerecognitionIsEnabled(): bool
    {
        if (!$this->facerecognitionIsInstalled()) {
            return false;
        }

        try {
            $uid = $this->userSession->getUser()?->getUID();
            if (null === $uid) {
                return false;
            }

            return 'true' === $this->userConfig->getValueString($uid, 'facerecognition', 'enabled', 'false');
        } catch (\Exception) {
            // not logged in
        }

        return false;
    }

    /** Check if Face Recognition is installed and enabled for this user */
    public function facerecognitionIsInstalled(): bool
    {
        if (!$this->appManager->isEnabledForUser('facerecognition')) {
            return false;
        }

        $v = $this->appManager->getAppVersion('facerecognition');

        return version_compare($v, '0.9.10-beta.2', '>=');
    }

    /** Check if preview generator is installed */
    public function previewGeneratorIsEnabled(): bool
    {
        return $this->appManager->isEnabledForUser('previewgenerator');
    }

    /**
     * Check if any encryption is enabled that we can not cope with
     * such as end-to-end encryption.
     */
    public function isEncryptionEnabled(): bool
    {
        if ($this->encryptionManager->isEnabled()) {
            // Server-side encryption (OC_DEFAULT_MODULE) is okay, others like e2e are not
            return 'OC_DEFAULT_MODULE' !== $this->encryptionManager->getDefaultEncryptionModuleId();
        }

        return false;
    }

    /** Get the common content security policy */
    public function getCSP(): ContentSecurityPolicy
    {
        $policy = new ContentSecurityPolicy();

        // Image domains MUST be added to the connect domain list
        // because of the service worker fetch() call
        $addImageDomain = static function (string $url) use (&$policy): void {
            $policy->addAllowedImageDomain($url);
            $policy->addAllowedConnectDomain($url);
        };

        // Create base policy
        $policy->addAllowedWorkerSrcDomain("'self'");
        $policy->addAllowedScriptDomain("'self'");
        $policy->addAllowedFrameDomain("'self'");
        $policy->addAllowedImageDomain("'self'");
        $policy->addAllowedMediaDomain("'self'");
        $policy->addAllowedConnectDomain("'self'");

        // Video player
        $policy->addAllowedWorkerSrcDomain('blob:');
        $policy->addAllowedScriptDomain('blob:');
        $policy->addAllowedMediaDomain('blob:');

        // Image editor
        $policy->addAllowedConnectDomain('data:');

        // Allow CSP domains of configured map tile servers
        foreach ($this->get('memories.map.tile_servers') as $tile) {
            foreach ((array) ($tile['csp'] ?? []) as $csp) {
                $addImageDomain((string) $csp);
            }
        }

        // Native communication
        $addImageDomain('http://127.0.0.1');

        // Allow configured location search provider
        $searchHost = parse_url((string) $this->get('memories.places.search.url'), PHP_URL_HOST);
        if (\is_string($searchHost) && '' !== $searchHost) {
            $policy->addAllowedConnectDomain($searchHost);
        }

        return $policy;
    }
}

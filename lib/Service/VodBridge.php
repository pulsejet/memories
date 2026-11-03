<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Exceptions;
use OCA\Memories\HttpResponseException;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Persistent go-vod file reader.
 *
 * Dials back to go-vod and serves one read at a time over JSON lines until
 * go-vod closes the connection or the TTL runs out, so go-vod pays the
 * Nextcloud bootstrap once per worker instead of per read.
 *
 *   go-vod: {"fileid":7,"token":"…","offset":0,"length":262144}
 *   go-vod: {"close":[7]} closes a file; may also lead a read
 *   php:    {"size":2083898324,"length":262144} then length raw bytes
 *   php:    {"status":403,"error":"…"}
 */
final class VodBridge
{
    public const MAX_TTL = 300;

    private const MAX_READ = 16 << 20;
    private const MAX_LINE = 64 << 10;
    private const IO_TIMEOUT = 15;

    /** Idle read handles stay open this long (seconds); they hold a shared lock on the file. */
    private const HANDLE_IDLE = 60;

    /** @var ?resource */
    private $sock;

    /** @var array<string, array{file: File, h: ?resource, pos: int, used: float}> */
    private array $files = [];

    public function __construct(
        private ServiceManager $serviceManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Serve reads until go-vod disconnects or the TTL is reached.
     *
     * @return int number of reads served
     */
    public function serve(string $server, string $nonce, int $ttl): int
    {
        $deadline = microtime(true) + (float) ($ttl + 5);
        $reads = 0;

        // Nextcloud read handles left open at shutdown crash PHP, and finally
        // does not run on fatal errors (e.g. time or memory limits).
        register_shutdown_function($this->close(...));

        try {
            $sock = $this->connect($server, $nonce);

            while (($left = $deadline - microtime(true)) > 0) {
                $this->closeIdleHandles();

                $r = [$sock];
                $w = $e = null;
                $ready = stream_select($r, $w, $e, 0, (int) (min($left, 1.0) * 1e6));
                if (0 === $ready) {
                    continue;
                }

                $line = false === $ready ? false : fgets($sock, self::MAX_LINE);
                if (false === $line || !str_ends_with($line, "\n")) {
                    break; // closed by go-vod
                }

                $this->handle($line);
                ++$reads;
            }
        } finally {
            $this->close();
        }

        return $reads;
    }

    /** @return resource */
    private function connect(string $server, string $nonce)
    {
        $ctx = stream_context_create(['socket' => ['tcp_nodelay' => true]]);
        $sock = @stream_socket_client("tcp://{$server}", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx)
            ?: throw new \Exception("go-vod bridge connect to {$server} failed: {$errstr}");
        $this->sock = $sock;
        stream_set_timeout($sock, self::IO_TIMEOUT);

        $this->write(
            "GET /bridge HTTP/1.1\r\n"
            ."Host: {$server}\r\n"
            ."Connection: Upgrade\r\n"
            ."Upgrade: go-vod-bridge\r\n"
            ."X-Bridge-Nonce: {$nonce}\r\n"
            ."\r\n",
        );
        $status = (string) fgets($sock, 1024);
        if (!str_starts_with($status, 'HTTP/1.1 101')) {
            throw new \Exception('go-vod bridge upgrade refused: '.trim($status));
        }

        do {
            $line = fgets($sock, 1024);
        } while (false !== $line && "\r\n" !== $line);

        return $sock;
    }

    private function handle(string $line): void
    {
        $data = '';

        try {
            $req = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
            if (!\is_array($req)) {
                throw Exceptions::BadRequest('Invalid request');
            }
            foreach ((array) ($req['close'] ?? []) as $id) {
                $this->closeFile((int) $id);
            }

            $res = ['length' => 0];
            if (isset($req['fileid'])) {
                [$size, $data] = $this->read(
                    (int) $req['fileid'],
                    (string) ($req['token'] ?? ''),
                    max(0, (int) ($req['offset'] ?? 0)),
                    min(self::MAX_READ, max(0, (int) ($req['length'] ?? 0))),
                );
                $res = ['size' => $size, 'length' => \strlen($data)];
            }
        } catch (HttpResponseException $e) {
            $body = $e->response instanceof DataResponse ? $e->response->getData() : [];
            $res = [
                'status' => $e->response->getStatus(),
                'error' => (string) ($body['message'] ?? 'error'),
            ];
        } catch (\JsonException $e) {
            $res = [
                'status' => 400,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            $this->logger->warning('go-vod bridge read failed: '.$e->getMessage(), ['app' => 'memories']);
            $res = [
                'status' => 500,
                'error' => $e->getMessage(),
            ];
        }

        // Header and data written separately to avoid copying the data
        $this->write(json_encode($res, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
        $this->write($data);
    }

    /** @return array{0: int, 1: string} file size and data */
    private function read(int $fileid, string $token, int $offset, int $length): array
    {
        $key = $fileid.':'.hash('xxh128', $token);
        $f = $this->files[$key] ?? [
            'file' => $this->serviceManager->getServiceTokenFile($token, $fileid),
            'h' => null,
            'pos' => 0,
            'used' => 0.0,
        ];
        $f['used'] = microtime(true);
        $size = (int) $f['file']->getSize();
        $data = '';

        try {
            if ($length > 0 && $offset < $size) {
                // Much faster than an fread loop over Nextcloud's user-space wrappers
                $data = stream_get_contents($this->seek($f, $offset), $length);
                if (false === $data) {
                    $this->closeHandle($f); // position unknown

                    throw new \Exception("read at {$offset} failed");
                }
                $f['pos'] = $offset + \strlen($data);
            }
        } finally {
            $this->files[$key] = $f;
        }

        return [$size, $data];
    }

    /**
     * Position the handle at offset, opening it if needed.
     *
     * @param array{file: File, h: ?resource, pos: int, used: float} $f
     *
     * @return resource
     */
    private function seek(array &$f, int $offset)
    {
        $h = $f['h'] ??= $f['file']->fopen('rb') ?: throw new \Exception('fopen failed');
        if ($f['pos'] !== $offset && 0 !== fseek($h, $offset)) {
            $this->closeHandle($f); // a failed seek leaves the position unknown

            throw new \Exception("storage cannot seek to {$offset}");
        }
        $f['pos'] = $offset;

        return $h;
    }

    /** Forget a file, closing its handles to release the lock. */
    private function closeFile(int $fileid): void
    {
        foreach ($this->files as $key => $f) {
            if ($f['file']->getId() === $fileid) {
                $this->closeHandle($f);
                unset($this->files[$key]);
            }
        }
    }

    private function closeIdleHandles(): void
    {
        $now = microtime(true);
        foreach ($this->files as &$f) {
            if ($now - $f['used'] > self::HANDLE_IDLE) {
                $this->closeHandle($f);
            }
        }
        unset($f);
    }

    /** @param array{file: File, h: ?resource, pos: int, used: float} $f */
    private function closeHandle(array &$f): void
    {
        if (null !== $f['h']) {
            fclose($f['h']);
            $f['h'] = null;
            $f['pos'] = 0;
        }
    }

    private function close(): void
    {
        foreach ($this->files as &$f) {
            $this->closeHandle($f);
        }
        unset($f);
        $this->files = [];
        if (null !== ($sock = $this->sock)) {
            $this->sock = null;
            fclose($sock);
        }
    }

    private function write(string $data): void
    {
        $sock = $this->sock ?? throw new \Exception('go-vod bridge not connected');
        for ($off = 0, $len = \strlen($data); $off < $len; $off += $n) {
            $n = fwrite($sock, 0 === $off ? $data : substr($data, $off));
            if (false === $n || 0 === $n) {
                throw new \Exception('go-vod bridge write failed');
            }
        }
    }
}

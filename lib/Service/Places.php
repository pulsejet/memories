<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Settings\SystemConfig;
use OCP\IConfig;
use OCP\IDBConnection;

const GIS_TYPE_NONE = 0;
const GIS_TYPE_MYSQL = 1;
const GIS_TYPE_POSTGRES = 2;

const PLANET_MYSQL_URL = 'https://github.com/pulsejet/memories-assets/releases/download/geo-20260902/planet_mysql.zip';
const PLANET_MYSQL_CHECKSUM = '42ae55edc23fe79eaae04682e9aef99d03836bc9f794e868e38bf39c2d3a13e2';

const PLANET_POSTGRES_URL = 'https://github.com/pulsejet/memories-assets/releases/download/geo-20260902/planet_postgres.zip';
const PLANET_POSTGRES_CHECKSUM = '33b9516ea284a089189075f61195be54a20a6d494d1de73c62172a964a67c48f';

final class Places
{
    public function __construct(
        private IConfig $config,
        private IDBConnection $connection,
        private TimelineWrite $tw,
    ) {}

    /**
     * Make SQL query to detect GIS type.
     *
     * @psalm-return 0|1|2|3
     */
    public function detectGisType(): int
    {
        // Make sure database prefix is set
        $prefix = $this->config->getSystemValue('dbtableprefix', '') ?: '';
        if ('' === $prefix) {
            throw new \Exception('Database table prefix is not set. Cannot use database extensions (dbtableprefix).');
        }

        // Detect database type
        $provider = $this->connection->getDatabaseProvider(true);

        // Test MySQL-like support in databse
        if (IDBConnection::PLATFORM_MYSQL === $provider
        || IDBConnection::PLATFORM_MARIADB === $provider) {
            try {
                $res = $this->connection->executeQuery("SELECT ST_GeomFromText('POINT(1 1)', 4326)")->fetch();
                if (0 === \count($res)) {
                    throw new \Exception('Invalid result');
                }

                return GIS_TYPE_MYSQL;
            } catch (\Exception $e) {
                throw new \Exception('No MySQL-like geometry support detected');
            }
        }

        // Test Postgres native geometry like support in database
        if (IDBConnection::PLATFORM_POSTGRES === $provider) {
            try {
                $res = $this->connection->executeQuery("SELECT POINT('1,1')")->fetch();
                if (0 === \count($res)) {
                    throw new \Exception('Invalid result');
                }

                return GIS_TYPE_POSTGRES;
            } catch (\Exception $e) {
                throw new \Exception('No Postgres native geometry support detected');
            }
        }

        return GIS_TYPE_NONE;
    }

    /**
     * Check if DB is already setup and return number of entries.
     */
    public function geomCount(): int
    {
        try {
            return (int) $this->connection->executeQuery('SELECT COUNT(osm_id) as c FROM memories_planet_geometry')->fetchOne();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get list of osm IDs for a given point.
     */
    public function queryPoint(float $lat, float $lon): array
    {
        // Get GIS type
        $gisType = SystemConfig::gisType();

        // Construct WHERE clause depending on GIS type
        $where = null;
        if (GIS_TYPE_MYSQL === $gisType) {
            $where = "ST_Contains(geometry, ST_GeomFromText('POINT({$lat} {$lon})', 4326))";
        } elseif (GIS_TYPE_POSTGRES === $gisType) {
            // Postgres does not support using an index with POINT <@ POLYGON
            // https://www.postgresql.org/docs/current/gist-builtin-opclasses.html
            $where = "POLYGON('{$lat},{$lon}') <@ geometry";
        } else {
            return [];
        }

        // Make query to memories_planet table
        $query = $this->connection->getQueryBuilder();
        $query->select(SQL::distinct($query, 'osm_id'))
            ->from('memories_planet_geometry')
            ->where($query->createFunction($where))
        ;

        // Cancel out inner rings
        $query->groupBy('poly_id', 'osm_id');
        $query->having($query->createFunction('SUM(type_id) > 0'));

        // memories_planet_geometry has no *PREFIX*
        $sql = str_replace('*PREFIX*memories_planet_geometry', 'memories_planet_geometry', $query->getSQL());

        // Use as subquery to get admin level
        $query = $this->connection->getQueryBuilder();
        $query->select('sub.osm_id', 'mp.admin_level')
            ->from($query->createFunction("({$sql})"), 'sub')
            ->innerJoin('sub', 'memories_planet', 'mp', $query->expr()->eq('sub.osm_id', 'mp.osm_id'))
            ->addOrderBy('mp.admin_level', 'ASC')
        ;

        // Run query
        return $query->executeQuery()->fetchAll();
    }

    /**
     * Download and import planet database.
     */
    public function downloadImportPlanet(int $gis = GIS_TYPE_NONE, ?string $zipFile = null): void
    {
        $gis = GIS_TYPE_NONE !== $gis ? $gis : $this->detectGisType();
        if (GIS_TYPE_NONE === $gis) {
            throw new \Exception('No supported GIS type detected');
        }

        $startTime = microtime(true);
        $files = [];

        try {
            if (null !== $zipFile) {
                $files = $this->extractPlanet($zipFile);
            } else {
                $files = $this->downloadPlanet($gis);
            }
            [$planetFile, $geomFile] = $files;
            $this->importPlanetBulk($gis, $planetFile, $geomFile);

            $duration = round(microtime(true) - $startTime, 2);
            $this->logToStdout("Total time taken: {$duration}s");
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Download planet database file and return paths to unzipped files.
     *
     * @return array{0: string, 1: string}
     */
    public function downloadPlanet(int $gis): array
    {
        if (GIS_TYPE_MYSQL === $gis) {
            $url = PLANET_MYSQL_URL;
            $checksum = PLANET_MYSQL_CHECKSUM;
        } elseif (GIS_TYPE_POSTGRES === $gis) {
            $url = PLANET_POSTGRES_URL;
            $checksum = PLANET_POSTGRES_CHECKSUM;
        } else {
            throw new \Exception('No supported GIS type detected');
        }

        $this->logToStdout('Download planet data to temporary file...');

        $zipFile = BinExt::getTmpPath().'/planet_data.zip';
        if (file_exists($zipFile) && !unlink($zipFile)) {
            throw new \Exception("Failed to delete old planet zip file: {$zipFile}");
        }

        $fp = fopen($zipFile, 'w+');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
        curl_exec($ch);
        curl_close($ch);

        fclose($fp);

        $this->logToStdout('Verifying planet data checksum...');
        if ($checksum !== hash_file('sha256', $zipFile)) {
            @unlink($zipFile);

            throw new \Exception('Failed to verify checksum for planet data file');
        }
        $this->logToStdout('Planet data checksum verified successfully');

        try {
            return $this->extractPlanet($zipFile);
        } finally {
            // Delete downloaded zip file
            @unlink($zipFile);
        }
    }

    /**
     * Extract planet zip file and return paths to unzipped files.
     *
     * @return array{0: string, 1: string}
     */
    public function extractPlanet(string $zipFile): array
    {
        if (!file_exists($zipFile)) {
            throw new \Exception("Planet zip file not found: {$zipFile}");
        }

        $planetFile = BinExt::getTmpPath().'/planet.tsv';
        if (file_exists($planetFile) && !unlink($planetFile)) {
            throw new \Exception("Failed to delete old planet data file: {$planetFile}");
        }

        $geomFile = BinExt::getTmpPath().'/planet_geometry.tsv';
        if (file_exists($geomFile) && !unlink($geomFile)) {
            throw new \Exception("Failed to delete old planet geometry file: {$geomFile}");
        }

        $this->logToStdout('Extracting planet data...');
        $zip = new \ZipArchive();
        $res = $zip->open($zipFile);
        if (true === $res) {
            $zip->extractTo(BinExt::getTmpPath());
            $zip->close();
        } else {
            throw new \Exception("Failed to unzip planet data file: {$zipFile}");
        }
        $this->logToStdout('Planet data extracted successfully');

        // Check if files exist
        if (!file_exists($planetFile) || !file_exists($geomFile)) {
            throw new \Exception('Failed to find planet data files after unzip');
        }

        return [$planetFile, $geomFile];
    }

    /**
     * Insert planet into database from files using bulk loading.
     */
    public function importPlanetBulk(int $gis, string $planetFile, string $geomFile): void
    {
        $this->logToStdout('Preparing planet data for bulk import...');

        if (GIS_TYPE_NONE === $gis) {
            throw new \Exception('No supported GIS type detected');
        }

        // Setup the database tables
        // This drops and recreates memories_planet_geometry
        $this->setupTables();

        // Truncate planet table
        SQL::truncate($this->connection, 'memories_planet', false);

        // Table prefix
        $prefix = $this->config->getSystemValue('dbtableprefix', '') ?: '';

        if (!file_exists($planetFile) || !file_exists($geomFile)) {
            throw new \Exception('Failed to find unzipped planet data files for bulk import');
        }

        $this->logToStdout('Inserting bulk data into database...');

        try {
            if (GIS_TYPE_MYSQL === $gis) {
                $pdo = SQL::getMysqlPdo($this->connection, $this->config);
                SQL::mysqlCopyFromFile($pdo, $prefix.'memories_planet', $planetFile, 'osm_id, admin_level, name, other_names');
                SQL::mysqlCopyFromFile($pdo, 'memories_planet_geometry', $geomFile, 'id, poly_id, type_id, osm_id, @geom', 'geometry = ST_GeomFromText(@geom, 4326)');
            } elseif (GIS_TYPE_POSTGRES === $gis) {
                $pdo = SQL::getPgsqlPdo($this->connection, $this->config);
                SQL::pgsqlCopyFromFile($pdo, $prefix.'memories_planet', $planetFile, 'osm_id, admin_level, name, other_names');
                SQL::pgsqlCopyFromFile($pdo, 'memories_planet_geometry', $geomFile, 'id, poly_id, type_id, osm_id, geometry');
            }
        } catch (\Exception $e) {
            throw new \Exception('Bulk insert failed: '.$e->getMessage(), (int) $e->getCode(), $e);
        }

        $this->logToStdout('Creating database indices...');
        $this->createIndexes($gis);
        $this->logToStdout('Database indices created successfully!');

        // Mark success
        $this->logToStdout('Planet database imported successfully!');
        SystemConfig::set('memories.gis_type', $gis);
    }

    /**
     * Recalculate all places for all users.
     */
    public function recalculateAll(): void
    {
        $this->logToStdout('Recalculating places for all files (do not interrupt this process)...');

        $count = 0;
        $this->tw->orphanAndRun(['fileid', 'lat', 'lon'], 20, function (array $row) use (&$count) {
            ++$count;

            // Only proceed if we have a valid location
            $fileid = (int) $row['fileid'];
            $lat = (float) $row['lat'];
            $lon = (float) $row['lon'];

            // Update places
            if ($lat || $lon) {
                $this->tw->updatePlacesData($fileid, $lat, $lon);
            }

            // Print every 500 files
            if (0 === $count % 500) {
                $this->logToStdout("Updated places data for {$count} files");
            }
        });
    }

    /**
     * Create database tables.
     */
    private function setupTables(): void
    {
        try {
            // Drop the table if it exists
            $this->connection->executeStatement('DROP TABLE IF EXISTS memories_planet_geometry');

            // Detect database type to select the right syntax and geometry types.
            $platform = $this->connection->getDatabaseProvider(true);

            // MySQL requires an SRID definition
            // https://github.com/pulsejet/memories/issues/1067
            $srid = IDBConnection::PLATFORM_MYSQL === $platform ? 'SRID 4326' : '';

            // Create table
            $sql = "CREATE TABLE memories_planet_geometry (
                id varchar(32) NOT NULL PRIMARY KEY,
                poly_id varchar(32) NOT NULL,
                type_id int NOT NULL,
                osm_id int NOT NULL,
                geometry polygon NOT NULL {$srid}
            );";
            $this->connection->executeQuery($sql);
        } catch (\Exception $e) {
            throw new \Exception('Failed to create database tables: '.$e->getMessage());
        }
    }

    /**
     * Create database indices.
     */
    private function createIndexes(int $gis): void
    {
        try {
            // Add indexes
            $this->connection->executeQuery('CREATE INDEX planet_osm_id_idx ON memories_planet_geometry (osm_id);');

            // Add spatial index
            if (GIS_TYPE_MYSQL === $gis) {
                $this->connection->executeQuery('CREATE SPATIAL INDEX planet_osm_polygon_geometry_idx ON memories_planet_geometry (geometry);');
            } elseif (GIS_TYPE_POSTGRES === $gis) {
                // https://www.postgresql.org/docs/current/gist-builtin-opclasses.html
                $this->connection->executeQuery('CREATE INDEX planet_osm_polygon_geometry_idx ON memories_planet_geometry USING GIST (geometry poly_ops);');
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed to create database indices: '.$e->getMessage());
        }
    }

    /**
     * Log message to standard output.
     */
    private function logToStdout(string $message): void
    {
        $time = date('Y-m-d H:i:s');
        $text = rtrim($message, "\r\n");
        echo "[{$time}] {$text}\n";
        flush();
    }
}

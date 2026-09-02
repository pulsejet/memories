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

const PLANET_URL = 'https://github.com/pulsejet/memories-assets/releases/download/geo-0.0.4/planet_coarse_boundaries.zip';
const PLANET_CHECKSUM = 'b443fc32dfdd26dd27b3c2def96da865841b6210473e3360da191f725f14dc55';

/**
 * @psalm-suppress MissingConstructor
 */
final class PlanetPolygon
{
    public string $i;
    public int $t;
    public string $k;

    /** @var list<array{0: float, 1: float}> */
    public array $c;
}

/**
 * @psalm-suppress MissingConstructor
 */
final class PlanetPlace
{
    public int $osm_id;
    public int $admin_level;
    public string $name;

    /** @var null|array<string, string> */
    public ?array $other_names = null;

    /** @var list<PlanetPolygon> */
    public array $geometry;
}

final class Places
{
    /**
     * Number of places to process in a single transaction.
     */
    public int $txnSize = 50;

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
     * Download planet database file and return path to it.
     */
    public function downloadPlanet(): string
    {
        $this->logToStdout('Download planet data to temporary file...');

        $filename = BinExt::getTmpPath().'/planet_coarse_boundaries.zip';
        if (file_exists($filename) && !unlink($filename)) {
            throw new \Exception("Failed to delete old planet zip file: {$filename}");
        }

        $txtfile = BinExt::getTmpPath().'/planet_coarse_boundaries.txt';
        if (file_exists($txtfile) && !unlink($txtfile)) {
            throw new \Exception("Failed to delete old planet data file: {$txtfile}");
        }

        $fp = fopen($filename, 'w+');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, PLANET_URL);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
        curl_exec($ch);
        curl_close($ch);

        fclose($fp);

        $this->logToStdout('Verifying planet data checksum...');
        if (PLANET_CHECKSUM !== hash_file('sha256', $filename)) {
            @unlink($filename);

            throw new \Exception('Failed to verify checksum for planet data file');
        }
        $this->logToStdout('Planet data checksum verified successfully');

        // Unzip
        $this->logToStdout('Extracting planet data...');
        $zip = new \ZipArchive();
        $res = $zip->open($filename);
        if (true === $res) {
            $zip->extractTo(BinExt::getTmpPath());
            $zip->close();
        } else {
            throw new \Exception('Failed to unzip planet data file');
        }
        $this->logToStdout('Planet data extracted successfully');

        // Check if file exists
        if (!file_exists($txtfile)) {
            throw new \Exception('Failed to find planet data file after unzip');
        }

        // Delete zip file
        @unlink($filename);

        return $txtfile;
    }

    /**
     * Insert planet into database from file
     * using bulk loading (MySQL / MariaDB and PostgreSQL).
     */
    public function importPlanetBulk(string $datafile): void
    {
        $this->logToStdout('Preparing planet data for bulk import...');

        // Detect the GIS type
        $gis = $this->detectGisType();

        if (GIS_TYPE_NONE === $gis) {
            throw new \Exception('No GIS support detected');
        }

        // Setup the database tables
        // This drops and recreates memories_planet_geometry
        $this->setupTables();

        // Truncate planet table
        SQL::truncate($this->connection, 'memories_planet', false);

        // Table prefix
        $prefix = $this->config->getSystemValue('dbtableprefix', '') ?: '';

        // Create temporary files for bulk insertion
        $tmpPlanet = tempnam(BinExt::getTmpPath(), 'bulk_planet_');
        $tmpGeom = tempnam(BinExt::getTmpPath(), 'bulk_geom_');
        if (false === $tmpPlanet || false === $tmpGeom) {
            throw new \Exception('Failed to create temporary files for bulk import');
        }

        $fpPlanet = fopen($tmpPlanet, 'w');
        $fpGeom = fopen($tmpGeom, 'w');
        $handle = fopen($datafile, 'r');

        if (!$fpPlanet || !$fpGeom || !$handle) {
            $fpPlanet && fclose($fpPlanet);
            $fpGeom && fclose($fpGeom);
            $handle && fclose($handle);
            @unlink($tmpPlanet);
            @unlink($tmpGeom);

            throw new \Exception('Failed to open planet data file or temporary files for bulk import');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if ('' === trim($line)) {
                    continue;
                }

                /** @var null|PlanetPlace $data */
                $data = json_decode($line);
                if (null === $data) {
                    $this->logToStdout('ERROR: Failed to decode JSON');

                    continue;
                }

                $osmId = $data->osm_id;
                $adminLevel = $data->admin_level;
                $boundaries = $data->geometry;

                // Explicitly convert all names to UTF-8
                $name = mb_convert_encoding($data->name, 'UTF-8');

                $otherNames = [];
                foreach ($data->other_names ?? [] as $lang => $val) {
                    $otherNames[$lang] = mb_convert_encoding($val, 'UTF-8');
                }
                $otherNamesJson = (string) json_encode($otherNames, JSON_UNESCAPED_UNICODE);

                // Skip some places
                if ($adminLevel > -2 && ($adminLevel <= 1 || $adminLevel >= 10)) {
                    // <=1: These are too general, e.g. "Earth"? or invalid
                    // >=10: These are too specific, e.g. "Community Board"
                    // <-1: These are special, e.g. "Timezone" = -7
                    continue;
                }

                // Escape for MySQL / PostgreSQL TSV LOAD DATA
                $cleanName = SQL::escapeTsv($name);
                $cleanOther = SQL::escapeTsv($otherNamesJson);
                fwrite($fpPlanet, "{$osmId}\t{$adminLevel}\t{$cleanName}\t{$cleanOther}\n");

                // Write polygons
                foreach ($boundaries as $polygon) {
                    $polyid = $polygon->i;
                    $typeid = $polygon->t;
                    $pkey = $polygon->k;
                    $coords = $polygon->c;

                    // Every polygon must have at least 3 points
                    if (\count($coords) < 3) {
                        $this->logToStdout("ERROR: Invalid polygon {$polyid}");

                        continue;
                    }

                    // Check if coordinates are valid
                    $invalid = false;
                    $pointStrs = [];
                    foreach ($coords as [$lon, $lat]) {
                        if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                            $this->logToStdout("ERROR: Invalid coordinates for polygon {$polyid}");
                            $invalid = true;

                            break;
                        }
                        if (GIS_TYPE_MYSQL === $gis) {
                            $pointStrs[] = "{$lat} {$lon}";
                        } elseif (GIS_TYPE_POSTGRES === $gis) {
                            $pointStrs[] = "({$lat},{$lon})";
                        }
                    }
                    if ($invalid) {
                        continue;
                    }

                    if (GIS_TYPE_MYSQL === $gis) {
                        $geometry = 'POLYGON(('.implode(',', $pointStrs).'))';
                    } elseif (GIS_TYPE_POSTGRES === $gis) {
                        $geometry = '(('.implode(',', $pointStrs).'))';
                    } else {
                        continue;
                    }

                    fwrite($fpGeom, "{$pkey}\t{$polyid}\t{$typeid}\t{$osmId}\t{$geometry}\n");
                }
            }
        } finally {
            fclose($handle);
            fclose($fpPlanet);
            fclose($fpGeom);
        }

        $this->logToStdout('Inserting bulk data into database...');

        try {
            if (GIS_TYPE_MYSQL === $gis) {
                $pdo = SQL::getMysqlPdo($this->connection, $this->config);
                SQL::mysqlCopyFromFile($pdo, $prefix.'memories_planet', $tmpPlanet, 'osm_id, admin_level, name, other_names');
                SQL::mysqlCopyFromFile($pdo, 'memories_planet_geometry', $tmpGeom, 'id, poly_id, type_id, osm_id, @geom', 'geometry = ST_GeomFromText(@geom, 4326)');
            } elseif (GIS_TYPE_POSTGRES === $gis) {
                $pdo = SQL::getPgsqlPdo($this->connection, $this->config);
                SQL::pgsqlCopyFromFile($pdo, $prefix.'memories_planet', $tmpPlanet, 'osm_id, admin_level, name, other_names');
                SQL::pgsqlCopyFromFile($pdo, 'memories_planet_geometry', $tmpGeom, 'id, poly_id, type_id, osm_id, geometry');
            }
        } catch (\Exception $e) {
            throw new \Exception('Bulk insert failed: '.$e->getMessage(), (int) $e->getCode(), $e);
        } finally {
            @unlink($tmpPlanet);
            @unlink($tmpGeom);
        }

        $this->logToStdout('Creating database indices...');
        $this->createIndexes($gis);
        $this->logToStdout('Database indices created successfully!');

        // Mark success
        $this->logToStdout('Planet database imported successfully!');
        SystemConfig::set('memories.gis_type', $gis);

        // Delete data file
        @unlink($datafile);
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
        echo rtrim($message, "\r\n")."\n";
        flush();
    }
}

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
const APPROX_PLACES = 635189;

const PLANET_URL = 'https://github.com/pulsejet/memories-assets/releases/download/geo-0.0.3/planet_coarse_boundaries.zip';

class Places
{
    /**
     * Number of places to process in a single transaction.
     */
    public int $txnSize = 50;

    public function __construct(
        protected IConfig $config,
        protected IDBConnection $connection,
        protected TimelineWrite $tw,
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
        $platform = $this->connection->getDatabasePlatform();

        // Test MySQL-like support in databse
        if (preg_match('/mysql|mariadb/i', $platform::class)) {
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
        if (preg_match('/postgres/i', $platform::class)) {
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
        echo "Download planet data to temporary file...\n";
        flush();

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

        // Unzip
        $zip = new \ZipArchive();
        $res = $zip->open($filename);
        if (true === $res) {
            $zip->extractTo(BinExt::getTmpPath());
            $zip->close();
        } else {
            throw new \Exception('Failed to unzip planet data file');
        }

        // Check if file exists
        if (!file_exists($txtfile)) {
            throw new \Exception('Failed to find planet data file after unzip');
        }

        // Delete zip file
        @unlink($filename);

        return $txtfile;
    }

    /**
     * Insert planet into database from file.
     */
    public function importPlanet(string $datafile): void
    {
        echo "Inserting planet data into database...\n";
        flush();

        // Detect the GIS type
        $gis = $this->detectGisType();

        // Make sure we support something
        if (GIS_TYPE_NONE === $gis) {
            throw new \Exception('No GIS support detected');
        }

        // Setup the database
        $this->setupDatabase($gis);

        // Truncate tables
        $p = $this->connection->getDatabasePlatform();
        $this->connection->executeStatement($p->getTruncateTableSQL('*PREFIX*memories_planet', false));
        $this->connection->executeStatement($p->getTruncateTableSQL('memories_planet_geometry', false));

        // Create place insertion statement
        $query = $this->connection->getQueryBuilder();
        $query->insert('memories_planet')
            ->values([
                'osm_id' => $query->createParameter('osm_id'),
                'admin_level' => $query->createParameter('admin_level'),
                'name' => $query->createParameter('name'),
                'other_names' => $query->createParameter('other_names'),
            ])
        ;
        $insertPlace = $this->connection->prepare($query->getSQL());

        // Create geometry insertion statement
        $query = $this->connection->getQueryBuilder();
        $geomParam = (string) $query->createParameter('geometry');
        if (GIS_TYPE_MYSQL === $gis) {
            $geomParam = "ST_GeomFromText({$geomParam}, 4326)";
        } elseif (GIS_TYPE_POSTGRES === $gis) {
            $geomParam = "POLYGON({$geomParam}::text)";
        }
        $query->insert('memories_planet_geometry')
            ->values([
                'id' => $query->createParameter('id'),
                'poly_id' => $query->createParameter('poly_id'),
                'type_id' => $query->createParameter('type_id'),
                'osm_id' => $query->createParameter('osm_id'),
                'geometry' => $query->createFunction($geomParam),
            ])
        ;
        $sql = str_replace('*PREFIX*memories_planet_geometry', 'memories_planet_geometry', $query->getSQL());
        $insertGeometry = $this->connection->prepare($sql);

        // The number of places in the current transaction
        $txnCount = 0;

        // Function to commit the current transaction
        $transact = function () use (&$txnCount): void {
            if (++$txnCount >= $this->txnSize) {
                $this->connection->commit();
                $this->connection->beginTransaction();
                $txnCount = 0;
            }
        };

        // Start the first transaction
        $this->connection->beginTransaction();

        // Iterate over the data file
        $handle = fopen($datafile, 'r');
        if ($handle) {
            $count = 0;
            while (($line = fgets($handle)) !== false) {
                // Skip empty lines
                if ('' === trim($line)) {
                    continue;
                }

                ++$count;

                // Decode JSON
                $data = json_decode($line, true);
                if (null === $data) {
                    echo "ERROR: Failed to decode JSON\n";

                    continue;
                }

                // Extract data
                $osmId = $data['osm_id'];
                $adminLevel = $data['admin_level'];
                $boundaries = $data['geometry'];

                /** @var string $name */
                $name = $data['name'];

                /** @var array<string, string> $otherNames */
                $otherNames = $data['other_names'];

                // Explicitly convert all names to UTF-8
                $name = mb_convert_encoding($name, 'UTF-8');

                $otherNames = [];
                foreach (($data['other_names'] ?? []) as $lang => $val) {
                    $otherNames[$lang] = mb_convert_encoding($val, 'UTF-8');
                }
                $otherNames = json_encode($otherNames);

                // Skip some places
                if ($adminLevel > -2 && ($adminLevel <= 1 || $adminLevel >= 10)) {
                    // <=1: These are too general, e.g. "Earth"? or invalid
                    // >=10: These are too specific, e.g. "Community Board"
                    // <-1: These are special, e.g. "Timezone" = -7
                    continue;
                }

                // Insert place into database
                $insertPlace->bindValue('osm_id', $osmId);
                $insertPlace->bindValue('admin_level', $adminLevel);
                $insertPlace->bindValue('name', $name);
                $insertPlace->bindValue('other_names', $otherNames);
                $insertPlace->execute();
                $transact();

                // Insert polygons into database
                $idx = 0;
                foreach ($boundaries as &$polygon) {
                    // $polygon is a struct as
                    // [ "t" => "e", "c" => [lon, lat], [lon, lat], ... ] ]

                    $polyid = $polygon['i'];
                    $typeid = $polygon['t'];
                    $pkey = $polygon['k'];
                    $coords = $polygon['c'];

                    // Create parameters
                    ++$idx;
                    $geometry = '';

                    // Every polygon must have at least 3 points
                    if (\count($coords) < 3) {
                        echo "ERROR: Invalid polygon {$polyid}\n";

                        continue;
                    }

                    // Check if coordinates are valid
                    foreach ($coords as [$lon, $lat]) {
                        if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                            echo "ERROR: Invalid coordinates for polygon {$polyid}\n";

                            continue 2;
                        }
                    }

                    if (GIS_TYPE_MYSQL === $gis) {
                        $points = implode(',', array_map(static function (array $point) {
                            [$lon, $lat] = $point;

                            return "{$lat} {$lon}";
                        }, $coords));

                        $geometry = "POLYGON(({$points}))";
                    } elseif (GIS_TYPE_POSTGRES === $gis) {
                        $geometry = implode(',', array_map(static function (array $point) {
                            [$lon, $lat] = $point;

                            return "({$lat},{$lon})";
                        }, $coords));
                    }

                    try {
                        $insertGeometry->bindValue('id', $pkey);
                        $insertGeometry->bindValue('poly_id', $polyid);
                        $insertGeometry->bindValue('type_id', $typeid);
                        $insertGeometry->bindValue('osm_id', $osmId);
                        $insertGeometry->bindValue('geometry', $geometry);
                        $insertGeometry->execute();
                        $transact();
                    } catch (\Exception $e) {
                        echo "ERROR: Failed to insert polygon {$polyid} ({$e->getMessage()} \n";

                        continue;
                    }
                }

                if (0 === $count % 500) {
                    // Print progress
                    $total = APPROX_PLACES;
                    $pct = round($count / $total * 100, 1);
                    echo "Inserted {$count} / {$total} places ({$pct}%), Last: {$name}\n";
                    flush();
                }
            }

            fclose($handle);
        }

        // Commit final transaction
        $this->connection->commit();

        // Mark success
        echo "Planet database imported successfully!\n";
        flush();
        SystemConfig::set('memories.gis_type', $gis);

        // Delete data file
        @unlink($datafile);
    }

    /**
     * Recalculate all places for all users.
     */
    public function recalculateAll(): void
    {
        echo "Recalculating places for all files (do not interrupt this process)...\n";
        flush();

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
                echo "Updated places data for {$count} files\n";
                flush();
            }
        });
    }

    /**
     * Create database tables and indices.
     */
    protected function setupDatabase(int $gis): void
    {
        try {
            // Detect database type
            $platform = $this->connection->getDatabasePlatform();

            // Drop the table if it exists
            $this->connection->executeStatement('DROP TABLE IF EXISTS memories_planet_geometry');

            // MySQL requires an SRID definition
            // https://github.com/pulsejet/memories/issues/1067
            $srid = preg_match('/mysql/i', $platform::class) ? 'SRID 4326' : '';

            // Create table
            $sql = "CREATE TABLE memories_planet_geometry (
                id varchar(32) NOT NULL PRIMARY KEY,
                poly_id varchar(32) NOT NULL,
                type_id int NOT NULL,
                osm_id int NOT NULL,
                geometry polygon NOT NULL {$srid}
            );";
            $this->connection->executeQuery($sql);

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
            throw new \Exception('Failed to create database tables: '.$e->getMessage());
        }
    }
}

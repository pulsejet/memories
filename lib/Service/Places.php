<?php

namespace OCA\Memories\Service;

use OCP\IConfig;
use OCP\IDBConnection;

const GIS_TYPE_NONE = 0;
const GIS_TYPE_MYSQL = 1;
const GIS_TYPE_POSTGRES = 2;
const APPROX_PLACES = 635189;

const PLANET_URL = 'https://github.com/pulsejet/memories-assets/releases/download/geo-0.0.3/planet_coarse_boundaries.zip';

class Places
{
    protected IDBConnection $db;

    protected IConfig $config;
    protected IDBConnection $connection;

    public function __construct(
        IConfig $config,
        IDBConnection $connection
    ) {
        $this->config = $config;
        $this->connection = $connection;
    }

    /**
     * Make SQL query to detect GIS type.
     */
    public function detectGisType()
    {
        // Make sure database prefix is set
        $prefix = $this->config->getSystemValue('dbtableprefix', '') ?: '';
        if ('' === $prefix) {
            throw new \Exception('Database table prefix is not set. Cannot use database extensions (dbtableprefix).');
        }

        // Detect database type
        $platform = strtolower(\get_class($this->connection->getDatabasePlatform()));

        // Test MySQL-like support in databse
        if (str_contains($platform, 'mysql') || str_contains($platform, 'mariadb')) {
            try {
                $res = $this->connection->executeQuery("SELECT ST_GeomFromText('POINT(1 1)')")->fetch();
                if (0 === \count($res)) {
                    throw new \Exception('Invalid result');
                }

                return GIS_TYPE_MYSQL;
            } catch (\Exception $e) {
                throw new \Exception('No MySQL-like geometry support detected');
            }
        }

        // Test Postgres native geometry like support in database
        if (str_contains($platform, 'postgres')) {
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
            return $this->connection->executeQuery('SELECT COUNT(osm_id) as c FROM memories_planet_geometry')->fetchOne();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Download planet database file and return path to it.
     */
    public function downloadPlanet(): string
    {
        $filename = sys_get_temp_dir().'/planet_coarse_boundaries.zip';
        unlink($filename);

        $txtfile = sys_get_temp_dir().'/planet_coarse_boundaries.txt';
        unlink($txtfile);

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
            $zip->extractTo(sys_get_temp_dir());
            $zip->close();
        } else {
            throw new \Exception('Failed to unzip planet data file');
        }

        // Check if file exists
        if (!file_exists($txtfile)) {
            throw new \Exception('Failed to find planet data file after unzip');
        }

        // Delete zip file
        unlink($filename);

        return $txtfile;
    }

    /**
     * Insert planet into database from file.
     */
    public function importPlanet(string $datafile): void
    {
        echo "Inserting planet data into database...\n";

        // Detect the GIS type
        $gis = $this->detectGisType();

        // Make sure we support something
        if (GIS_TYPE_NONE === $gis) {
            throw new \Exception('No GIS support detected');
        }

        // Drop the table if it exists
        $p = $this->connection->getDatabasePlatform();
        if ($this->geomCount() > 0) {
            $this->connection->executeStatement($p->getDropTableSQL('memories_planet_geometry'));
        }

        // Setup the database
        $this->setupDatabase();

        // Truncate tables
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
        $geomParam = $query->createParameter('geometry');
        if (GIS_TYPE_MYSQL === $gis) {
            $geomParam = "ST_GeomFromText({$geomParam})";
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

        // Iterate over the data file
        $handle = fopen($datafile, 'r');
        if ($handle) {
            $count = 0;
            while (($line = fgets($handle)) !== false) {
                // Skip empty lines
                if ('' === trim($line)) {
                    continue;
                }

                // Begin transaction
                if (0 === $txnCount++) {
                    $this->connection->beginTransaction();
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
                $name = $data['name'];
                $boundaries = $data['geometry'];
                $otherNames = json_encode($data['other_names'] ?? []);

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

                    if (\count($coords) < 3) {
                        echo "ERROR: Invalid polygon {$polyid}\n";

                        continue;
                    }

                    if (GIS_TYPE_MYSQL === $gis) {
                        $points = implode(',', array_map(function ($point) {
                            $x = $point[0];
                            $y = $point[1];

                            return "{$x} {$y}";
                        }, $coords));

                        $geometry = "POLYGON(({$points}))";
                    } elseif (GIS_TYPE_POSTGRES === $gis) {
                        $geometry = implode(',', array_map(function ($point) {
                            $x = $point[0];
                            $y = $point[1];

                            return "({$x},{$y})";
                        }, $coords));
                    }

                    try {
                        $insertGeometry->bindValue('id', $pkey);
                        $insertGeometry->bindValue('poly_id', $polyid);
                        $insertGeometry->bindValue('type_id', $typeid);
                        $insertGeometry->bindValue('osm_id', $osmId);
                        $insertGeometry->bindValue('geometry', $geometry);
                        $insertGeometry->execute();
                    } catch (\Exception $e) {
                        echo "ERROR: Failed to insert polygon {$polyid} ({$e->getMessage()} \n";

                        continue;
                    }
                }

                // Commit transaction every once in a while
                if (0 === $count % 100) {
                    $this->connection->commit();
                    $txnCount = 0;

                    // Print progress
                    $total = APPROX_PLACES;
                    $pct = round($count / $total * 100, 1);
                }

                if (0 === $count % 500) {
                    echo "Inserted {$count} / {$total} places ({$pct}%), Last: {$name}\n";
                    flush();
                }
            }

            fclose($handle);
        }

        // Commit final transaction
        if ($txnCount > 0) {
            $this->connection->commit();
        }

        // Mark success
        echo "Planet database imported successfully!\n";
        echo "You should re-index your library now.\n";
        $this->config->setSystemValue('memories.gis_type', $gis);

        // Delete data file
        unlink($datafile);
    }

    /**
     * Create database tables and indices.
     */
    private function setupDatabase(): void
    {
        try {
            // Get Gis type
            $gis = $this->detectGisType();

            // Create table
            $sql = 'CREATE TABLE memories_planet_geometry (
                id varchar(255) NOT NULL PRIMARY KEY,
                poly_id varchar(255) NOT NULL,
                type_id int NOT NULL,
                osm_id int NOT NULL,
                geometry polygon NOT NULL
            );';
            $this->connection->executeQuery($sql);

            // Add indexes
            $this->connection->executeQuery('CREATE INDEX planet_osm_id_idx ON memories_planet_geometry (osm_id);');

            // Add spatial index
            if (GIS_TYPE_MYSQL === $gis) {
                $this->connection->executeQuery('CREATE SPATIAL INDEX planet_osm_polygon_geometry_idx ON memories_planet_geometry (geometry);');
            } elseif (GIS_TYPE_POSTGRES === $gis) {
                $this->connection->executeQuery('CREATE INDEX planet_osm_polygon_geometry_idx ON memories_planet_geometry USING GIST (geometry);');
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed to create database tables: '.$e->getMessage());
        }
    }
}

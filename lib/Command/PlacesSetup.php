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
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

const GIS_TYPE_NONE = 0;
const GIS_TYPE_MYSQL = 1;
const GIS_TYPE_POSTGRES = 2;
const APPROX_PLACES = 635189;

const PLANET_URL = 'https://github.com/pulsejet/memories-assets/releases/download/geo-0.0.2/planet_coarse_boundaries.zip';

class PlacesSetup extends Command
{
    protected IConfig $config;
    protected OutputInterface $output;
    protected IDBConnection $connection;

    protected int $gisType = GIS_TYPE_NONE;

    public function __construct(
        IConfig $config,
        IDBConnection $connection
    ) {
        parent::__construct();
        $this->config = $config;
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:places-setup')
            ->setDescription('Setup reverse geocoding')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $this->output->writeln('Attempting to set up reverse geocoding');

        // Detect the GIS type
        $this->detectGisType();
        $this->config->setSystemValue('memories.gis_type', $this->gisType);

        // Make sure we support something
        if (GIS_TYPE_NONE === $this->gisType) {
            $this->output->writeln('<error>No supported GIS type detected</error>');

            return 1;
        }

        // Check if the database is already set up
        $hasDb = false;

        try {
            $this->output->writeln('');
            $this->connection->executeQuery('SELECT osm_id FROM memories_planet_geometry LIMIT 1')->fetch();
            $this->output->writeln('<error>Database is already set up</error>');
            $this->output->writeln('<error>This will drop and re-download the planet database</error>');
            $this->output->writeln('<error>This is generally not necessary to do frequently </error>');
            $hasDb = true;
        } catch (\Exception $e) {
        }

        // Ask confirmation
        $tempdir = sys_get_temp_dir();
        $this->output->writeln('');
        $this->output->writeln('Are you sure you want to download the planet database?');
        $this->output->writeln("This will take a very long time and use some disk space in {$tempdir}");
        $this->output->write('Proceed? [y/N] ');
        $handle = fopen('php://stdin', 'r');
        $line = fgets($handle);
        if ('y' !== trim($line)) {
            $this->output->writeln('Aborting');

            return 1;
        }

        // Drop the table
        $p = $this->connection->getDatabasePlatform();
        if ($hasDb) {
            $this->output->writeln('');
            $this->output->write('Dropping table ... ');
            $this->connection->executeStatement($p->getDropTableSQL('memories_planet_geometry'));
            $this->output->writeln('OK');
        }

        // Setup the database
        $this->output->write('Setting up database ... ');
        $this->setupDatabase();
        $this->output->writeln('OK');

        // Truncate tables
        $this->output->write('Truncating tables ... ');
        $this->connection->executeStatement($p->getTruncateTableSQL('*PREFIX*memories_planet', false));
        $this->connection->executeStatement($p->getTruncateTableSQL('memories_planet_geometry', false));
        $this->output->writeln('OK');

        // Download the data
        $this->output->write('Downloading data ... ');
        $datafile = $this->downloadPlanet();
        $this->output->writeln('OK');

        // Start importing
        $this->output->writeln('');
        $this->output->writeln('Importing data (this will take a while) ...');

        // Start time
        $start = time();

        // Create place insertion statement
        $query = $this->connection->getQueryBuilder();
        $query->insert('memories_planet')
            ->values([
                'osm_id' => $query->createParameter('osm_id'),
                'admin_level' => $query->createParameter('admin_level'),
                'name' => $query->createParameter('name'),
            ])
        ;
        $insertPlace = $this->connection->prepare($query->getSQL());

        // Create geometry insertion statement
        $query = $this->connection->getQueryBuilder();
        $geomParam = $query->createParameter('geometry');
        if (GIS_TYPE_MYSQL === $this->gisType) {
            $geomParam = "ST_GeomFromText({$geomParam})";
        } elseif (GIS_TYPE_POSTGRES === $this->gisType) {
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
                    $this->output->writeln('<error>Failed to decode JSON</error>');

                    continue;
                }

                // Extract data
                $osmId = $data['osm_id'];
                $adminLevel = $data['admin_level'];
                $name = $data['name'];
                $boundaries = $data['geometry'];

                // Skip some places
                if ($adminLevel <= 1 || $adminLevel >= 10) {
                    // <=1: These are too general, e.g. "Earth"? or invalid
                    // >=10: These are too specific, e.g. "Community Board"
                    continue;
                }

                // Insert place into database
                $insertPlace->bindValue('osm_id', $osmId);
                $insertPlace->bindValue('admin_level', $adminLevel);
                $insertPlace->bindValue('name', $name);
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
                        $this->output->writeln('<error>Invalid polygon</error>');

                        continue;
                    }

                    if (GIS_TYPE_MYSQL === $this->gisType) {
                        $points = implode(',', array_map(function ($point) {
                            $x = $point[0];
                            $y = $point[1];

                            return "{$x} {$y}";
                        }, $coords));

                        $geometry = "POLYGON(({$points}))";
                    } elseif (GIS_TYPE_POSTGRES === $this->gisType) {
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
                        $this->output->writeln('<error>Failed to insert into database</error>');
                        $this->output->writeln($e->getMessage());

                        continue;
                    }
                }

                // Print progress
                if (0 === $count % 500) {
                    $end = time();
                    $elapsed = $end - $start;
                    $rate = $count / $elapsed;
                    $remaining = APPROX_PLACES - $count;
                    $eta = round($remaining / $rate);
                    $rate = round($rate, 1);
                    $this->output->writeln("Inserted {$count} places, {$rate}/s, ETA: {$eta}s, Last: {$name}");
                }
            }

            fclose($handle);
        }

        // Delete file
        unlink($datafile);

        // Done
        $this->output->writeln('');
        $this->output->writeln('Planet database imported successfully!');
        $this->output->writeln('If this is the first time you did this, you should now run:');
        $this->output->writeln('occ memories:index -f');

        // Mark success
        $this->config->setSystemValue('memories.gis_type', $this->gisType);

        return 0;
    }

    protected function detectGisType()
    {
        // Make sure database prefix is set
        $prefix = $this->config->getSystemValue('dbtableprefix', '') ?: '';
        if ('' === $prefix) {
            $this->output->writeln('<error>Database table prefix is not set</error>');
            $this->output->writeln('Custom database extensions cannot be used without a prefix');
            $this->output->writeln('Reverse geocoding will not work and is disabled');
            $this->gisType = GIS_TYPE_NONE;

            return;
        }

        // Warn the admin about the database prefix not being used
        $this->output->writeln('');
        $this->output->writeln("Database table prefix is set to '{$prefix}'");
        $this->output->writeln('If the planet can be imported, it will not use this prefix');
        $this->output->writeln('The table will be named "memories_planet_geometry"');
        $this->output->writeln('This is necessary for using custom database extensions');
        $this->output->writeln('');

        // Detect database type
        $platform = strtolower(\get_class($this->connection->getDatabasePlatform()));

        // Test MySQL-like support in databse
        if (str_contains($platform, 'mysql') || str_contains($platform, 'mariadb')) {
            try {
                $res = $this->connection->executeQuery("SELECT ST_GeomFromText('POINT(1 1)')")->fetch();
                if (0 === \count($res)) {
                    throw new \Exception('Invalid result');
                }
                $this->output->writeln('MySQL-like support detected!');
                $this->gisType = GIS_TYPE_MYSQL;

                return;
            } catch (\Exception $e) {
                $this->output->writeln('No MySQL-like support detected');
            }
        }

        // Test Postgres native geometry like support in database
        if (str_contains($platform, 'postgres')) {
            try {
                $res = $this->connection->executeQuery("SELECT POINT('1,1')")->fetch();
                if (0 === \count($res)) {
                    throw new \Exception('Invalid result');
                }
                $this->output->writeln('Postgres native geometry support detected!');
                $this->gisType = GIS_TYPE_POSTGRES;

                return;
            } catch (\Exception $e) {
                $this->output->writeln('No Postgres native geometry support detected');
            }
        }
    }

    protected function setupDatabase(): void
    {
        try {
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
            if (GIS_TYPE_MYSQL === $this->gisType) {
                $this->connection->executeQuery('CREATE SPATIAL INDEX planet_osm_polygon_geometry_idx ON memories_planet_geometry (geometry);');
            } elseif (GIS_TYPE_POSTGRES === $this->gisType) {
                $this->connection->executeQuery('CREATE INDEX planet_osm_polygon_geometry_idx ON memories_planet_geometry USING GIST (geometry);');
            }
        } catch (\Exception $e) {
            $this->output->writeln('Failed to create planet table');
            $this->output->writeln($e->getMessage());

            exit;
        }
    }

    protected function ensureDeleted(string $filename)
    {
        unlink($filename);
        if (file_exists($filename)) {
            $this->output->writeln('<error>Failed to delete data file</error>');
            $this->output->writeln("Please delete {$filename} manually");

            exit;
        }
    }

    protected function downloadPlanet(): string
    {
        $filename = sys_get_temp_dir().'/planet_coarse_boundaries.zip';
        $this->ensureDeleted($filename);

        $txtfile = sys_get_temp_dir().'/planet_coarse_boundaries.txt';
        $this->ensureDeleted($txtfile);

        $fp = fopen($filename, 'w+');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, PLANET_URL);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60000);
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
            $this->output->writeln('Failed to unzip planet file');

            exit;
        }

        // Check if file exists
        if (!file_exists($txtfile)) {
            $this->output->writeln('Failed to find planet data file after unzip');

            exit;
        }

        // Delete zip file
        unlink($filename);

        return $txtfile;
    }
}

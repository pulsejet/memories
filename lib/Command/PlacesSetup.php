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
const APPROX_PLACES = 600000;

const PLANET_URL = 'https://github.com/pulsejet/memories-assets/releases/download/geo-0.0.1/planet_coarse_boundaries.zip';

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
        try {
            $this->output->writeln('');
            $this->connection->executeQuery('SELECT osm_id FROM memories_planet_geometry LIMIT 1')->fetch();
            $this->output->writeln('<error>Database is already set up</error>');
            $this->output->writeln('<error>This will clear and re-download the planet database</error>');
            $this->output->writeln('<error>This is generally not necessary to do frequently </error>');
        } catch (\Exception $e) {
            $this->output->write('Setting up database ... ');
            $this->setupDatabase();
            $this->output->writeln('OK');
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

        // Download the data
        $this->output->write('Downloading data ... ');
        $datafile = $this->downloadPlanet();
        $this->output->writeln('OK');

        // Truncate tables
        $this->output->write('Truncating tables ... ');
        $p = $this->connection->getDatabasePlatform();
        $this->connection->executeStatement($p->getTruncateTableSQL('*PREFIX*memories_planet', false));
        $this->connection->executeStatement($p->getTruncateTableSQL('memories_planet_geometry', false));
        $this->output->writeln('OK');

        // Start importing
        $this->output->writeln('');
        $this->output->writeln('Importing data (this will take a while) ...');

        // Start time
        $start = time();

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
                if ($adminLevel >= 10) {
                    // These are too specific, e.g. "Community Board"
                    continue;
                }

                // Insert place into database
                $query = $this->connection->getQueryBuilder();
                $query->insert('memories_planet')
                    ->values([
                        'osm_id' => $query->createNamedParameter($osmId),
                        'admin_level' => $query->createNamedParameter($adminLevel),
                        'name' => $query->createNamedParameter($name),
                    ])
                ;
                $query->executeStatement();

                // Insert polygons into database
                $idx = 0;
                foreach ($boundaries as &$polygon) {
                    // $boundary is a list of points
                    // [ [lon, lat], [lon, lat], ... ]
                    ++$idx;
                    $pkey = $osmId.'-'.$idx;
                    $geometry = '';

                    if (\count($polygon) < 3) {
                        $this->output->writeln('<error>Invalid polygon</error>');

                        continue;
                    }

                    $query = $this->connection->getQueryBuilder();

                    if (GIS_TYPE_MYSQL === $this->gisType) {
                        $points = array_map(function ($point) {
                            return $point[0].' '.$point[1];
                        }, $polygon);
                        $geometry = implode(',', $points);

                        $geometry = 'POLYGON(('.$geometry.'))';
                        $geometry = 'ST_GeomFromText(\''.$geometry.'\')';
                    } elseif (GIS_TYPE_POSTGRES === $this->gisType) {
                        $points = array_map(function ($point) {
                            return '('.$point[0].','.$point[1].')';
                        }, $polygon);
                        $geometry = implode(',', $points);
                        $geometry = 'POLYGON(\''.$geometry.'\')';
                    }

                    try {
                        $query->insert('memories_planet_geometry')
                            ->values([
                                'id' => $query->createNamedParameter($pkey),
                                'osm_id' => $query->createNamedParameter($osmId),
                                'geometry' => $query->createFunction($geometry),
                            ])
                        ;
                        $sql = str_replace('*PREFIX*memories_planet_geometry', 'memories_planet_geometry', $query->getSQL());
                        $this->connection->executeQuery($sql, $query->getParameters());
                    } catch (\Exception $e) {
                        $this->output->writeln('<error>Failed to insert into database</error>');
                        $this->output->writeln($e->getMessage());

                        continue;
                    }

                    // Print progress
                    if (0 === $count % 1000) {
                        $end = time();
                        $elapsed = $end - $start;
                        $rate = $count / $elapsed;
                        $remaining = APPROX_PLACES - $count;
                        $eta = round($remaining / $rate);
                        $rate = round($rate, 1);
                        $this->output->writeln("Inserted {$count} places, {$rate}/s, ETA: {$eta}s, Last: {$name}");
                    }
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
        // Test MySQL-like support in databse
        try {
            $res = $this->connection->executeQuery("SELECT ST_GeomFromText('POINT(1 1)')")->fetch();
            if (0 === \count($res)) {
                throw new \Exception('Invalid result');
            }
            $this->output->writeln('MySQL-like support detected!');

            // Make sure this is actually MySQL
            $res = $this->connection->executeQuery('SELECT VERSION()')->fetch();
            if (0 === \count($res)) {
                throw new \Exception('Invalid result');
            }
            if (false === strpos($res['VERSION()'], 'MariaDB') && false === strpos($res['VERSION()'], 'MySQL')) {
                throw new \Exception('MySQL not detected');
            }

            $this->gisType = GIS_TYPE_MYSQL;
        } catch (\Exception $e) {
            $this->output->writeln('No MySQL-like support detected');
        }

        // Test Postgres native geometry like support in database
        if (GIS_TYPE_NONE === $this->gisType) {
            try {
                $res = $this->connection->executeQuery("SELECT POINT('1,1')")->fetch();
                if (0 === \count($res)) {
                    throw new \Exception('Invalid result');
                }
                $this->output->writeln('Postgres native geometry support detected!');
                $this->gisType = GIS_TYPE_POSTGRES;
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

    protected function downloadPlanet(): string
    {
        $txtfile = sys_get_temp_dir().'/planet_coarse_boundaries.txt';
        unlink($txtfile);

        $filename = sys_get_temp_dir().'/planet_coarse_boundaries.zip';

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

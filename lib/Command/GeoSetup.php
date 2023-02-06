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

class GeoSetup extends Command
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
            ->setName('memories:geo-setup')
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
            $this->connection->executeQuery('SELECT osm_id FROM memories_planet_geometry LIMIT 1')->fetch();
            $this->output->writeln('Database already set up ... skipping');
        } catch (\Exception $e) {
            $this->output->writeln('Setting up database ...');
            $this->setupDatabase();
            $this->output->writeln('Database set up');
        }

        // TODO: Download the data
        // TODO: Warn user and truncate all tables
        $datafile = '/tmp/planet_coarse_boundaries.txt';

        // Truncate tables
        $this->output->writeln('Truncating tables ...');
        $p = $this->connection->getDatabasePlatform();
        $this->connection->executeStatement($p->getTruncateTableSQL('*PREFIX*memories_planet', false));
        $this->connection->executeStatement($p->getTruncateTableSQL('memories_planet_geometry', false));

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

    protected function runSQL(string &$line)
    {
        try {
            $this->connection->executeStatement($line);
        } catch (\Exception $e) {
            $this->output->writeln(substr($line, 0, 100));
            $this->output->writeln($e->getMessage());
        }
    }
}

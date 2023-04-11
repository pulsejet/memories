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

use OCA\Memories\Service\Places;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

const GIS_TYPE_NONE = 0;
const GIS_TYPE_MYSQL = 1;
const GIS_TYPE_POSTGRES = 2;
const APPROX_PLACES = 635189;

const PLANET_URL = 'https://github.com/pulsejet/memories-assets/releases/download/geo-0.0.3/planet_coarse_boundaries.zip';

class PlacesSetup extends Command
{
    protected OutputInterface $output;
    protected Places $places;

    public function __construct(
        Places $places
    ) {
        parent::__construct();
        $this->places = $places;
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
        if ($this->places->detectGisType() <= 0) {
            $this->output->writeln('<error>No supported GIS type detected</error>');

            return 1;
        }
        $this->output->writeln('Database support was detected');

        // Check if database is already set up
        if ($this->places->geomCount() > 0) {
            $this->output->writeln('');
            $this->output->writeln('<error>Database is already set up</error>');
            $this->output->writeln('<error>This will drop and re-download the planet database</error>');
            $this->output->writeln('<error>This is generally not necessary to do frequently </error>');

            // Ask confirmation
            $this->output->writeln('');
            $this->output->writeln('Are you sure you want to download the planet database?');
            $this->output->write('Proceed? [y/N] ');
            $handle = fopen('php://stdin', 'r');
            $line = fgets($handle);
            if (false === $line) {
                $this->output->writeln('<error>You need an interactive terminal to run this command</error>');

                return 1;
            }
            if ('y' !== trim($line)) {
                $this->output->writeln('Aborting');

                return 1;
            }
        }

        // Download the planet database
        $this->output->writeln('Downloading planet database');
        $datafile = $this->places->downloadPlanet();

        // Import the planet database
        $this->places->importPlanet($datafile);
    }
}

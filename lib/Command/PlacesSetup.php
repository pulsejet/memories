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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PlacesSetup extends Command
{
    protected OutputInterface $output;

    public function __construct(protected Places $places)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:places-setup')
            ->setDescription('Setup reverse geocoding')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ignore existing setup and re-download planet')
            ->addOption('recalculate', 'r', InputOption::VALUE_NONE, 'Only recalculate places for existing files')
            ->addOption('transaction-size', null, InputOption::VALUE_REQUIRED, 'Reduce this value if your database crashes', 10)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $recalculate = (bool) $input->getOption('recalculate');
        $force = (bool) $input->getOption('force');

        if (($this->places->txnSize = (int) $input->getOption('transaction-size')) < 1) {
            $this->output->writeln('<error>Transaction size must be at least 1</error>');

            return 1;
        }

        $this->output->writeln('Attempting to set up reverse geocoding');

        // Detect the GIS type
        if ($this->places->detectGisType() <= 0) {
            $this->output->writeln('<error>No supported GIS type detected</error>');

            return 1;
        }
        $this->output->writeln('<info>Database support was detected</info>');

        // Check if database is already set up
        if (!$recalculate && !$force && $this->places->geomCount() > 0 && !$this->warnDownloaded()) {
            return 1;
        }

        // Check if we only need to recalculate
        if (!$recalculate) {
            // Download the planet database
            $datafile = $this->places->downloadPlanet();

            // Import the planet database
            $this->places->importPlanet($datafile);
        }

        // Recalculate all places
        $this->places->recalculateAll();

        $this->output->writeln('<info>Places set up successfully</info>');

        return 0;
    }

    protected function warnDownloaded(): bool
    {
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

            return false;
        }
        if ('y' !== trim($line)) {
            $this->output->writeln('Aborting');

            return false;
        }

        return true;
    }
}

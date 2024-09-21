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

use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Util;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

const API_IMAGES = '/images';
const API_TEXT = '/text';
const VECTOR_SIZE = 768;

class AIOpts
{
    public ?string $user = null;

    public function __construct(InputInterface $input)
    {
        $this->user = $input->getOption('user');
    }
}

class AI extends Command
{
    private InputInterface $input;
    private OutputInterface $output;
    private AIOpts $opts;

    private string $server = 'http://localhost:47789'; // AI server

    public function __construct(
        protected IConfig $config,
        protected IDBConnection $connection,
        protected IPreview $preview,
        protected IRootFolder $rootFolder,
        protected IUserManager $userManager,
        protected TimelineQuery $tq,
        protected FsManager $fs,
    ) {
        parent::__construct();
    }

    public function search(string $prompt): array
    {
        $response = $this->request($this->server.API_TEXT.'?q='.urlencode($prompt), null);

        if (!\is_array($response['embedding'] ?? null)) {
            throw new \Exception('Invalid response from AI server');
        }

        $query = $this->connection->getQueryBuilder();

        $classlist = array_map(static fn (array $class): int => $class['index'], $response['classes']);
        // $classlist = \array_slice($classlist, 0, 8);

        $classQuery = $this->connection->getQueryBuilder();
        $classQuery->select('c.word')
            ->from('memories_ss_class', 'c')
            ->where($classQuery->expr()->andX(
                $classQuery->expr()->eq('c.fileid', 'v.fileid'),
                $classQuery->expr()->in('c.class', array_map(static fn ($idx) => $classQuery->expr()->literal($idx), $classlist)),
            ))
        ;

        $subquery = $this->connection->getQueryBuilder();
        $subquery->select('v.fileid')
            ->from('memories_ss_vectors', 'v')
            ->where(SQL::exists($query, $classQuery))
            ->groupBy('v.fileid')
        ;

        // Take vector projection
        $components = [];
        foreach ($response['embedding'] as $i => $value) {
            $value = number_format($value, 6);
            $components[] = "(v.v{$i}*({$value}))";
        }

        // Divide the operators into chunks of 48 each
        $sums = array_chunk($components, 48);

        // Add the sum of each chunk
        for ($i = 0; $i < \count($sums); ++$i) {
            $sum = $subquery->createFunction(implode('+', $sums[$i]));
            $subquery->selectAlias($sum, "score{$i}");
        }

        // Create outer query
        $query->select('sq.fileid')
            ->from(SQL::subquery($query, $subquery, 'sq'))
        ;

        // Add all score sums together
        $finalSum = implode('+', array_map(static fn ($_, $i) => "score{$i}", $sums, array_keys($sums)));
        $finalSum = $query->createFunction("({$finalSum})");
        $query->selectAlias($finalSum, 'score');

        // Filter for scores less than 1
        $query = SQL::materialize($query, 'fsq');
        $query->andWhere($query->expr()->gt('fsq.score', $query->expr()->literal(0.04)));

        $query->orderBy('fsq.score', 'DESC');

        // $query->setMaxResults(8); // batch size

        // SQL::debugQuery($query);

        $t1 = microtime(true);

        $res = $query->executeQuery()->fetchAll();

        // print length and discard after 10
        echo '<h1>Results: '.\count($res).'</h1>';
        $res = \array_slice($res, 0, 10);

        $t2 = microtime(true);
        echo '<h1>Search took '.(($t2 - $t1) * 1000).' ms</h1>';
        echo 'class list: '.json_encode($response['classes']).'<br>';

        foreach ($res as &$row) {
            $fid = $row['fileid'] = (int) $row['fileid'];
            $row['score'] = (float) $row['score'];

            $row['score'] = 2 ** ($row['score'] * 40);

            $p = $this->preview->getPreview($this->fs->getUserFile($fid), 1024, 1024);
            $data = $p->getContent();

            // get classes for this file
            $q = $this->connection->getQueryBuilder();
            $w = $q->select('word')
                ->from('memories_ss_class', 'c')
                ->where($q->expr()->eq('c.fileid', $q->createNamedParameter($fid)))
                ->executeQuery()
                ->fetchAll(\PDO::FETCH_COLUMN)
            ;

            echo '<h2>Score: '.$row['score'].'</h2>';
            echo 'Row: '.json_encode($row).'<br>';
            echo 'Classes: '.json_encode($w).'</br>';
            echo "<img src='data:image/jpeg;base64,".base64_encode($data)."'>";
        }

        // exit;
        exit;
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:ai')
            ->setDescription('Index the metadata in files')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Index only the specified user')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Store input/output/opts for later use
        $this->input = $input;
        $this->output = $output;
        $this->opts = new AIOpts($input);

        try {
            $this->userManager->callForSeenUsers(function (IUser $user) {
                $this->indexUser($user);
            });

            return 0;
        } catch (\Exception $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>".PHP_EOL);

            return 1;
        }
    }

    private function indexUser(IUser $user): void
    {
        // Print statement
        $this->output->writeln("<info>Indexing user {$user->getUID()}</info>");

        // Get the user's folder
        $folder = $this->rootFolder->getUserFolder($user->getUID());

        // Filter by the files this user has
        // see defn of joinFilecache
        $root = new TimelineRoot();
        $root->addFolder($folder);
        $root->addMountPoints(); // recurse

        while (true) {
            // Get all indexed files that are not indexed by the AI
            $query = $this->connection->getQueryBuilder()
                ->select('m.fileid', 'm.mtime')
                ->from('memories', 'm')
            ;

            $query = $this->tq->filterFilecache($query, $root, true, false, true);

            // Filter by the files that are not indexed by the AI
            $vecSq = $this->connection->getQueryBuilder();
            $vecSq->select($vecSq->expr()->literal(1))
                ->from('memories_ss_vectors', 'v')
                ->where($vecSq->expr()->eq('m.fileid', 'v.fileid'))
            ;
            $query->andWhere(SQL::notExists($query, $vecSq));

            // Batch size
            $query->setMaxResults(16);

            // FileIds inside this folder that need indexing
            $objs = Util::transaction(fn () => $this->tq->executeQueryWithCTEs($query)->fetchAll());
            if (empty($objs)) {
                break;
            }

            // Index the files
            $this->indexSet($folder, $objs);
        }
    }

    private function indexSet(Folder $folder, array $objs): void
    {
        // Check we have something to work on
        if (empty($objs)) {
            $this->output->writeln('All files are already indexed');

            return;
        }

        // Get previews for all files
        foreach ($objs as &$obj) {
            $fileid = $obj['fileid'] = (int) $obj['fileid'];
            $obj['mtime'] = (int) $obj['mtime'];

            try {
                // Get file object
                $file = $folder->getById($obj['fileid']);
                if (empty($file)) {
                    $this->output->writeln("<error>File not found: {$fileid}</error>");

                    continue;
                }
                $file = $file[0];
                if (!$file instanceof File) {
                    $this->output->writeln("<error>Not a file: {$fileid}</error>");

                    continue;
                }

                // Get preview
                $preview = $this->preview->getPreview($file, 1024, 1024);
                $content = $preview->getContent();
                if (empty($content)) {
                    throw new \Exception("empty preview for {$file->getPath()}");
                }

                // Convert to base64 data URI
                $mime = $preview->getMimeType();
                $data = base64_encode($content);
                $obj['image'] = "data:{$mime};base64,{$data}";

                // Log
                $this->output->writeln("Indexing {$file->getPath()}");
            } catch (\Exception $e) {
                $obj['fileid'] = 0; // mark failure
                $this->output->writeln("<error>Failed to get preview: {$e->getMessage()}</error>");
            }
        }

        // Filter out failed files
        // TODO: store failure reason
        $objs = array_filter($objs, static fn ($obj) => $obj['fileid'] > 0);

        // Post to server
        try {
            $response = $this->request($this->server.API_IMAGES, json_encode([
                'pipelines' => ['search'],
                'images' => array_column($objs, 'image'),
            ]));

            // Store the result in the database
            if (!\is_array($response['search'] ?? null)) {
                throw new \Exception('Invalid response from AI server');
            }

            // Store the results
            $searchResult = $response['search'];
            for ($i = 0; $i < \count($objs); ++$i) {
                try {
                    Util::transaction(fn () => $this->ssStoreResult(
                        $searchResult[$i],
                        $objs[$i]['fileid'],
                        $objs[$i]['mtime'],
                    ));
                } catch (\Exception $e) {
                    $this->output->writeln("<error>Failed to store AI result: {$e->getMessage()}</error>".PHP_EOL);
                }
            }
        } catch (\Exception $e) {
            $this->output->writeln("<error>Failed to get AI index: {$e->getMessage()}</error>".PHP_EOL);
        }
    }

    private function ssStoreResult(array $result, int $fileid, int $mtime): void
    {
        // Check result
        if (VECTOR_SIZE !== \count($result['embedding'])) {
            throw new \Exception('Invalid embedding size');
        }

        if (0 === \count($result['classes'])) {
            throw new \Exception('No classes returned.');
        }

        // Store the result in the database
        $query = $this->connection->getQueryBuilder();

        // Static values
        $values = [
            'fileid' => $query->createNamedParameter($fileid, \PDO::PARAM_INT),
            'mtime' => $query->createNamedParameter($mtime, \PDO::PARAM_INT),
        ];

        // Store embedding
        for ($i = 0; $i < VECTOR_SIZE; ++$i) {
            $values["v{$i}"] = $query->expr()->literal($result['embedding'][$i]);
        }

        $query->insert('memories_ss_vectors')
            ->values($values)
            ->executeStatement()
        ;

        // Store classes
        foreach ($result['classes'] as $i => $class) {
            $classId = $class['index'];
            $score = $class['score'];
            $query->insert('memories_ss_class')
                ->values([
                    'fileid' => $query->createNamedParameter($fileid, \PDO::PARAM_INT),
                    'class' => $query->createNamedParameter($classId, \PDO::PARAM_INT),
                    'score' => $query->createNamedParameter($score, \PDO::PARAM_INT),
                    'word' => $query->createNamedParameter($class['word'], \PDO::PARAM_STR),
                ])
                ->executeStatement()
            ;
        }
    }

    /**
     * Make a POST request to the upstream server.
     *
     * @param string      $url  the URL to make the request to
     * @param null|string $blob the data to send [POST]
     */
    private function request(string $url, ?string $blob): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        if (\is_string($blob)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $blob);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: '.\strlen($blob),
            ]);
        }

        $response = curl_exec($ch);
        $returnCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!\is_string($response)) {
            throw new \Exception('Failed to connect to AI server');
        }

        if (200 !== $returnCode) {
            throw new \Exception("AI server returned an error: {$returnCode}");
        }

        return json_decode($response, true);
    }
}

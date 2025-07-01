<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Command;

use MHaasler\ShopwareVectorSearch\Service\VectorSearchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'shopware:vector-search:search',
    description: 'Test vector search with a query'
)]
class SearchCommand extends Command
{
    public function __construct(
        private readonly VectorSearchService $vectorSearchService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'query',
                InputArgument::REQUIRED,
                'Search query to test'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of results',
                10
            )
            ->addOption(
                'threshold',
                't',
                InputOption::VALUE_OPTIONAL,
                'Similarity threshold (0.0 - 1.0)',
                0.7
            )
            ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_NONE,
                'Show detailed similarity scores'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $query = $input->getArgument('query');
        $limit = (int) $input->getOption('limit');
        $threshold = (float) $input->getOption('threshold');
        $verbose = $input->getOption('verbose');

        $io->title('Shopware Vector Search - Search Test');
        $io->text(sprintf('Query: "%s"', $query));
        $io->text(sprintf('Limit: %d, Threshold: %.2f', $limit, $threshold));

        try {
            $results = $this->vectorSearchService->searchProducts($query, $limit, $threshold);

            if (empty($results)) {
                $io->warning('No results found for your query.');
                return Command::SUCCESS;
            }

            $io->success(sprintf('Found %d results:', count($results)));

            $tableRows = [];
            foreach ($results as $index => $result) {
                $row = [
                    $index + 1,
                    substr($result['product_id'], 0, 8) . '...',
                    substr($result['content'], 0, 60) . '...',
                ];

                if ($verbose) {
                    $row[] = sprintf('%.3f', $result['similarity']);
                    $row[] = sprintf('%.3f', $result['distance']);
                }

                $tableRows[] = $row;
            }

            $headers = ['#', 'Product ID', 'Content'];
            if ($verbose) {
                $headers[] = 'Similarity';
                $headers[] = 'Distance';
            }

            $io->table($headers, $tableRows);

            if ($verbose) {
                $io->text('Similarity: Higher is better (0.0 - 1.0)');
                $io->text('Distance: Lower is better (0.0 - 2.0)');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Search failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
} 
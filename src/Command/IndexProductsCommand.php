<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Command;

use MHaasler\ShopwareVectorSearch\Service\VectorSearchService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'shopware:vector-search:index',
    description: 'Index all products for vector search'
)]
class IndexProductsCommand extends Command
{
    public function __construct(
        private readonly VectorSearchService $vectorSearchService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Number of products to process in each batch',
                100
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force reindexing even if embeddings already exist'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $batchSize = (int) $input->getOption('batch-size');
        $force = $input->getOption('force');

        $io->title('Shopware Vector Search - Product Indexing');

        if ($force) {
            $io->warning('Force mode enabled - will reindex all products');
        }

        try {
            $io->text('Starting product indexing...');
            
            $result = $this->vectorSearchService->indexAllProducts($context, $batchSize, $force);
            
            if ($result['errors'] > 0) {
                $io->warning(sprintf(
                    'Indexing completed with %d errors. Indexed: %d/%d products',
                    $result['errors'],
                    $result['indexed'],
                    $result['total_products']
                ));
                return Command::FAILURE;
            }

            $io->success(sprintf(
                'Successfully indexed %d products in %d batches',
                $result['indexed'],
                ceil($result['indexed'] / $batchSize)
            ));

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Products', $result['total_products']],
                    ['Indexed Products', $result['indexed']],
                    ['Batch Size', $batchSize],
                    ['Errors', $result['errors']]
                ]
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Indexing failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
} 
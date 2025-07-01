<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Command;

use MHaasler\ShopwareVectorSearch\Service\VectorSearchService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use GuzzleHttp\Client;

#[AsCommand(
    name: 'shopware:vector-search:status',
    description: 'Show vector search status and configuration'
)]
class StatusCommand extends Command
{
    public function __construct(
        private readonly VectorSearchService $vectorSearchService,
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Shopware Vector Search - Status');

        try {
            // Configuration Status
            $this->showConfiguration($io);
            
            // Database Status
            $this->showDatabaseStatus($io);
            
            // Embedding Service Status
            $this->showEmbeddingServiceStatus($io);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to get status: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showConfiguration(SymfonyStyle $io): void
    {
        $io->section('Configuration');

        $config = [
            ['Setting', 'Value'],
            ['Embedding Mode', $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingMode') ?? 'embedding_service'],
            ['Embedding Service URL', $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001'],
            ['OpenAI API Key', !empty($this->systemConfigService->get('ShopwareVectorSearch.config.openAiApiKey')) ? '✓ Set' : '✗ Not set'],
            ['Vector Search Enabled', $this->systemConfigService->get('ShopwareVectorSearch.config.enableVectorSearch') ? '✓ Yes' : '✗ No'],
            ['Batch Size', $this->systemConfigService->get('ShopwareVectorSearch.config.batchSize') ?? 100],
            ['Similarity Threshold', $this->systemConfigService->get('ShopwareVectorSearch.config.defaultSimilarityThreshold') ?? 0.7],
            ['Max Results', $this->systemConfigService->get('ShopwareVectorSearch.config.maxSearchResults') ?? 20],
            ['Auto Reindex', $this->systemConfigService->get('ShopwareVectorSearch.config.autoReindex') ? '✓ Yes' : '✗ No'],
            ['Debug Logging', $this->systemConfigService->get('ShopwareVectorSearch.config.enableLogging') ? '✓ Yes' : '✗ No'],
        ];

        $io->table($config[0], array_slice($config, 1));
    }

    private function showDatabaseStatus(SymfonyStyle $io): void
    {
        $io->section('Database Status');

        try {
            // Check if table exists
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mh_product_embeddings'"
            );

            if (!$tableExists) {
                $io->error('Table "mh_product_embeddings" does not exist. Run migrations first.');
                return;
            }

            // Get statistics
            $totalProducts = $this->connection->fetchOne('SELECT COUNT(*) FROM product WHERE version_id = UNHEX(?)', ['0159E78AE2A2475E8F75C64B0D914516']);
            $indexedProducts = $this->connection->fetchOne('SELECT COUNT(*) FROM mh_product_embeddings');
            $indexingProgress = $totalProducts > 0 ? round(($indexedProducts / $totalProducts) * 100, 2) : 0;

            $stats = [
                ['Metric', 'Value'],
                ['Table Exists', '✓ Yes'],
                ['Total Products', $totalProducts],
                ['Indexed Products', $indexedProducts],
                ['Indexing Progress', $indexingProgress . '%'],
            ];

            $io->table($stats[0], array_slice($stats, 1));

            if ($indexingProgress < 100) {
                $io->note(sprintf('Only %.1f%% of products are indexed. Run "shopware:vector-search:index" to index all products.', $indexingProgress));
            }

        } catch (\Exception $e) {
            $io->error('Database check failed: ' . $e->getMessage());
        }
    }

    private function showEmbeddingServiceStatus(SymfonyStyle $io): void
    {
        $io->section('Embedding Service Status');

        $embeddingMode = $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingMode') ?? 'embedding_service';

        if ($embeddingMode === 'direct_openai') {
            $apiKey = $this->systemConfigService->get('ShopwareVectorSearch.config.openAiApiKey');
            $io->text('Mode: Direct OpenAI API');
            $io->text('API Key: ' . (!empty($apiKey) ? '✓ Configured' : '✗ Not configured'));
            return;
        }

        $embeddingUrl = $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
        $io->text('Mode: External Embedding Service');
        $io->text('URL: ' . $embeddingUrl);

        try {
            $client = new Client(['timeout' => 5]);
            $response = $client->get($embeddingUrl . '/health');

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                
                $serviceInfo = [
                    ['Property', 'Value'],
                    ['Status', '✓ Healthy'],
                    ['Model', $data['model'] ?? 'unknown'],
                    ['Dimensions', $data['dimensions'] ?? 'unknown'],
                    ['Ready', $data['ready'] ? '✓ Yes' : '✗ No'],
                ];

                $io->table($serviceInfo[0], array_slice($serviceInfo, 1));
            } else {
                $io->error('Service returned status: ' . $response->getStatusCode());
            }

        } catch (\Exception $e) {
            $io->error('Cannot connect to embedding service: ' . $e->getMessage());
            $io->note('Make sure the embedding service is running and accessible at: ' . $embeddingUrl);
        }
    }
} 
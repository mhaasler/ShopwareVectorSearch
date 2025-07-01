<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;
use OpenAI;

class VectorSearchService
{
    private Connection $connection;
    private EntityRepository $productRepository;
    private ClientInterface $httpClient;
    private LoggerInterface $logger;
    private SystemConfigService $systemConfigService;
    private bool $supportsVector;
    private ?OpenAI\Client $openAiClient = null;

    // OpenAI Constants
    private const OPENAI_MODEL = 'text-embedding-ada-002';
    private const EMBEDDING_DIMENSIONS = 1536;
    
    // Embedding Modes
    private const MODE_EMBEDDING_SERVICE = 'embedding_service';
    private const MODE_DIRECT_OPENAI = 'direct_openai';

    public function __construct(
        Connection $connection,
        EntityRepository $productRepository,
        ClientInterface $httpClient,
        LoggerInterface $logger,
        SystemConfigService $systemConfigService
    ) {
        $this->connection = $connection;
        $this->productRepository = $productRepository;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->systemConfigService = $systemConfigService;
        
        // Check MySQL Vector support
        $version = $this->connection->fetchOne('SELECT VERSION()');
        $this->supportsVector = version_compare($version, '8.0.28', '>=');
        
        $this->logger->info('VectorSearchService initialized', [
            'mysql_version' => $version,
            'vector_support' => $this->supportsVector,
            'embedding_mode' => $this->getEmbeddingMode()
        ]);
    }

    /**
     * Get OpenAI Client (lazy initialization)
     */
    private function getOpenAiClient(): OpenAI\Client
    {
        if ($this->openAiClient === null) {
            $apiKey = $this->getOpenAiApiKey();
            if (empty($apiKey)) {
                throw new \Exception('OpenAI API Key is not configured');
            }
            
            $this->openAiClient = OpenAI::client($apiKey);
            
            $this->logger->info('OpenAI client initialized', [
                'model' => self::OPENAI_MODEL,
                'dimensions' => self::EMBEDDING_DIMENSIONS
            ]);
        }
        
        return $this->openAiClient;
    }

    /**
     * Get configuration values
     */
    private function getEmbeddingMode(): string
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingMode') 
            ?? self::MODE_EMBEDDING_SERVICE;
    }

    private function getEmbeddingServiceUrl(): string
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? '';
    }

    private function getOpenAiApiKey(): string
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.openAiApiKey') ?? '';
    }

    private function isVectorSearchEnabled(): bool
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.enableVectorSearch') 
            ?? true;
    }

    private function getBatchSize(): int
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.batchSize') 
            ?? 100;
    }

    private function getDefaultSimilarityThreshold(): float
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.defaultSimilarityThreshold') 
            ?? 0.7;
    }

    private function getMaxSearchResults(): int
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.maxSearchResults') 
            ?? 20;
    }

    private function getEmbeddingTimeout(): int
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingTimeout') 
            ?? 30;
    }

    private function isLoggingEnabled(): bool
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.enableLogging') 
            ?? false;
    }

    private function isAutoReindexEnabled(): bool
    {
        return $this->systemConfigService->get('ShopwareVectorSearch.config.autoReindex') 
            ?? true;
    }

    /**
     * Index all products for vector search
     */
    public function indexAllProducts(Context $context): array
    {
        $this->logger->info('Starting product indexing for vector search');
        
        $criteria = new Criteria();
        $criteria->addAssociations(['categories', 'properties.group', 'manufacturer']);
        $criteria->setLimit($this->getBatchSize()); // Use configured batch size
        
        $products = $this->productRepository->search($criteria, $context);
        $indexed = 0;
        $errors = 0;
        
        $productTexts = [];
        $productIds = [];
        
        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $text = $this->buildProductText($product);
            $hash = hash('sha256', $text);
            
            // Check if already indexed with same content
            if ($this->isAlreadyIndexed($product->getId(), $hash)) {
                continue;
            }
            
            $productTexts[] = $text;
            $productIds[] = [
                'id' => $product->getId(),
                'versionId' => $product->getVersionId(),
                'text' => $text,
                'hash' => $hash
            ];
        }
        
        if (empty($productTexts)) {
            return ['indexed' => 0, 'errors' => 0, 'message' => 'No new products to index'];
        }
        
        // Get embeddings in batch
        try {
            $embeddings = $this->getEmbeddingsBatch($productTexts);
            
            // Store embeddings
            foreach ($productIds as $index => $productData) {
                try {
                    $this->storeEmbedding(
                        $productData['id'],
                        $productData['versionId'],
                        $embeddings[$index],
                        $productData['text'],
                        $productData['hash']
                    );
                    $indexed++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to store embedding', [
                        'product_id' => $productData['id'],
                        'error' => $e->getMessage()
                    ]);
                    $errors++;
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Batch embedding failed', ['error' => $e->getMessage()]);
            return ['indexed' => 0, 'errors' => count($productIds), 'message' => $e->getMessage()];
        }
        
        $this->logger->info('Product indexing completed', [
            'indexed' => $indexed,
            'errors' => $errors,
            'total_products' => $products->getTotal()
        ]);
        
        return [
            'indexed' => $indexed,
            'errors' => $errors,
            'total_products' => $products->getTotal(),
            'message' => "Successfully indexed {$indexed} products"
        ];
    }

    /**
     * Search products using vector similarity
     */
    public function searchProducts(string $query, ?int $limit = null, ?float $threshold = null): array
    {
        // Use configuration defaults if not provided
        $limit = $limit ?? $this->getMaxSearchResults();
        $threshold = $threshold ?? $this->getDefaultSimilarityThreshold();

        $this->logger->info('Vector search started', [
            'query' => $query, 
            'limit' => $limit, 
            'threshold' => $threshold,
            'config_driven' => true
        ]);
        
        try {
            // Get query embedding
            $queryEmbedding = $this->getEmbedding($query);
            
            if ($this->supportsVector) {
                return $this->searchWithVectorSupport($queryEmbedding, $limit, $threshold);
            } else {
                return $this->searchWithJsonFallback($queryEmbedding, $limit, $threshold);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Vector search failed', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Build searchable text from product
     */
    private function buildProductText(ProductEntity $product): string
    {
        $parts = [];
        
        // Basic product info
        if ($product->getName()) {
            $parts[] = $product->getName();
        }
        
        if ($product->getDescription()) {
            $parts[] = strip_tags($product->getDescription());
        }
        
        // Manufacturer
        if ($product->getManufacturer()) {
            $parts[] = $product->getManufacturer()->getName();
        }
        
        // Categories
        if ($product->getCategories()) {
            foreach ($product->getCategories() as $category) {
                $parts[] = $category->getName();
            }
        }
        
        // Properties
        if ($product->getProperties()) {
            foreach ($product->getProperties() as $property) {
                $parts[] = $property->getName();
                if ($property->getGroup()) {
                    $parts[] = $property->getGroup()->getName();
                }
            }
        }
        
        return implode(' ', array_filter($parts));
    }

    /**
     * Get single embedding (supports both embedding service and direct OpenAI)
     */
    private function getEmbedding(string $text): array
    {
        if (!$this->isVectorSearchEnabled()) {
            throw new \Exception('Vector search is disabled in configuration');
        }

        $mode = $this->getEmbeddingMode();

        if ($this->isLoggingEnabled()) {
            $this->logger->debug('Requesting embedding', [
                'text_length' => strlen($text),
                'mode' => $mode
            ]);
        }

        try {
            if ($mode === self::MODE_DIRECT_OPENAI) {
                return $this->getEmbeddingFromOpenAI($text);
            } else {
                return $this->getEmbeddingFromService($text);
            }
        } catch (\Exception $e) {
            $this->logger->error('Embedding request failed', [
                'error' => $e->getMessage(),
                'mode' => $mode,
                'text_length' => strlen($text)
            ]);
            throw $e;
        }
    }

    /**
     * Get single embedding directly from OpenAI
     */
    private function getEmbeddingFromOpenAI(string $text): array
    {
        $client = $this->getOpenAiClient();

        $response = $client->embeddings()->create([
            'model' => self::OPENAI_MODEL,
            'input' => $text,
        ]);
        
        return $response->embeddings[0]->embedding;
    }

    /**
     * Get single embedding from external embedding service
     */
    private function getEmbeddingFromService(string $text): array
    {
        $url = $this->getEmbeddingServiceUrl();
        if (empty($url)) {
            throw new \Exception('Embedding service URL is not configured');
        }

        $timeout = $this->getEmbeddingTimeout();

        $response = $this->httpClient->post($url . '/embed', [
            'json' => ['text' => $text],
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => $timeout
        ]);
        
        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Embedding service error: ' . $response->getStatusCode());
        }
        
        $data = json_decode($response->getBody()->getContents(), true);
        return $data['embedding'];
    }

    /**
     * Get batch embeddings (supports both embedding service and direct OpenAI)
     */
    private function getEmbeddingsBatch(array $texts): array
    {
        if (!$this->isVectorSearchEnabled()) {
            throw new \Exception('Vector search is disabled in configuration');
        }

        $mode = $this->getEmbeddingMode();

        if ($this->isLoggingEnabled()) {
            $this->logger->debug('Requesting batch embeddings', [
                'batch_size' => count($texts),
                'mode' => $mode
            ]);
        }

        try {
            if ($mode === self::MODE_DIRECT_OPENAI) {
                return $this->getEmbeddingsBatchFromOpenAI($texts);
            } else {
                return $this->getEmbeddingsBatchFromService($texts);
            }
        } catch (\Exception $e) {
            $this->logger->error('Batch embedding request failed', [
                'error' => $e->getMessage(),
                'mode' => $mode,
                'batch_size' => count($texts)
            ]);
            throw $e;
        }
    }

    /**
     * Get batch embeddings directly from OpenAI
     */
    private function getEmbeddingsBatchFromOpenAI(array $texts): array
    {
        $client = $this->getOpenAiClient();

        $response = $client->embeddings()->create([
            'model' => self::OPENAI_MODEL,
            'input' => $texts,
        ]);
        
        // Extract embeddings from response
        $embeddings = [];
        foreach ($response->embeddings as $embedding) {
            $embeddings[] = $embedding->embedding;
        }
        
        return $embeddings;
    }

    /**
     * Get batch embeddings from external embedding service
     */
    private function getEmbeddingsBatchFromService(array $texts): array
    {
        $url = $this->getEmbeddingServiceUrl();
        if (empty($url)) {
            throw new \Exception('Embedding service URL is not configured');
        }

        $timeout = $this->getEmbeddingTimeout() * 2; // Double timeout for batch operations

        $response = $this->httpClient->post($url . '/embed/batch', [
            'json' => ['texts' => $texts],
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => $timeout
        ]);
        
        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Batch embedding service error: ' . $response->getStatusCode());
        }
        
        $data = json_decode($response->getBody()->getContents(), true);
        return $data['embeddings'];
    }

    /**
     * Check if product is already indexed
     */
    private function isAlreadyIndexed(string $productId, string $contentHash): bool
    {
        $result = $this->connection->fetchOne(
            'SELECT id FROM mh_product_embeddings WHERE product_id = :productId AND content_hash = :hash',
            [
                'productId' => Uuid::fromHexToBytes($productId),
                'hash' => $contentHash
            ]
        );
        
        return $result !== false;
    }

    /**
     * Store embedding in database
     */
    private function storeEmbedding(string $productId, string $versionId, array $embedding, string $text, string $hash): void
    {
        $embeddingData = $this->supportsVector ? 
            $this->vectorToString($embedding) : 
            json_encode($embedding);
        
        $this->connection->executeStatement(
            'REPLACE INTO mh_product_embeddings 
             (id, product_id, product_version_id, embedding, content_text, content_hash) 
             VALUES (:id, :productId, :versionId, :embedding, :text, :hash)',
            [
                'id' => Uuid::randomBytes(),
                'productId' => Uuid::fromHexToBytes($productId),
                'versionId' => Uuid::fromHexToBytes($versionId),
                'embedding' => $embeddingData,
                'text' => $text,
                'hash' => $hash
            ]
        );
    }

    /**
     * Search with MySQL 8.0+ Vector support
     */
    private function searchWithVectorSupport(array $queryEmbedding, int $limit, float $threshold): array
    {
        $vectorString = $this->vectorToString($queryEmbedding);
        
        $sql = '
            SELECT 
                HEX(product_id) as product_id,
                content_text,
                VECTOR_DISTANCE(embedding, :queryVector) as distance,
                (1 - VECTOR_DISTANCE(embedding, :queryVector)) as similarity
            FROM mh_product_embeddings 
            WHERE VECTOR_DISTANCE(embedding, :queryVector) < :threshold
            ORDER BY distance ASC 
            LIMIT :limit
        ';
        
        $results = $this->connection->fetchAllAssociative($sql, [
            'queryVector' => $vectorString,
            'threshold' => 1 - $threshold,
            'limit' => $limit
        ]);
        
        return $this->formatSearchResults($results);
    }

    /**
     * Search with JSON fallback for older MySQL
     */
    private function searchWithJsonFallback(array $queryEmbedding, int $limit, float $threshold): array
    {
        // This is more complex and slower - would need custom similarity calculation
        // For now, return empty results
        $this->logger->warning('JSON fallback search not yet implemented');
        return [];
    }

    /**
     * Convert embedding array to MySQL Vector string format
     */
    private function vectorToString(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }

    /**
     * Format search results
     */
    private function formatSearchResults(array $results): array
    {
        return array_map(function ($result) {
            return [
                'product_id' => $result['product_id'],
                'similarity' => (float) $result['similarity'],
                'distance' => (float) $result['distance'],
                'content' => $result['content_text']
            ];
        }, $results);
    }
} 
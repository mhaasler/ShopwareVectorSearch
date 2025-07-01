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
    public function indexAllProducts(Context $context, ?int $batchSize = null, bool $force = false): array
    {
        $batchSize = $batchSize ?? $this->getBatchSize();
        $this->logger->info('Starting product indexing for vector search', [
            'batch_size' => $batchSize,
            'force' => $force
        ]);
        
        // Get total count first
        $totalCriteria = new Criteria();
        $totalProducts = $this->productRepository->search($totalCriteria, $context)->getTotal();
        
        $totalIndexed = 0;
        $totalErrors = 0;
        $offset = 0;
        
        while ($offset < $totalProducts) {
            $criteria = new Criteria();
            $criteria->addAssociations(['categories', 'properties.group', 'manufacturer']);
            $criteria->setLimit($batchSize);
            $criteria->setOffset($offset);
            
            $products = $this->productRepository->search($criteria, $context);
            
            if ($products->count() === 0) {
                break;
            }
            
            $batchIndexed = 0;
            $batchErrors = 0;
            
            $productTexts = [];
            $productIds = [];
            
            /** @var ProductEntity $product */
            foreach ($products as $product) {
                $text = $this->buildProductText($product);
                $hash = hash('sha256', $text);
                
                // Check if already indexed with same content (skip if not force mode)
                if (!$force && $this->isAlreadyIndexed($product->getId(), $hash)) {
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
            
            if (!empty($productTexts)) {
                // Get embeddings in batch
                try {
                    $embeddings = $this->getEmbeddingsBatch($productTexts);
                    
                    // Store embeddings
                    foreach ($productIds as $index => $productData) {
                        try {
                            if ($force) {
                                // Delete existing embedding first
                                $this->connection->executeStatement(
                                    'DELETE FROM mh_product_embeddings WHERE product_id = ? AND product_version_id = ?',
                                    [$productData['id'], $productData['versionId']]
                                );
                            }
                            
                            $this->storeEmbedding(
                                $productData['id'],
                                $productData['versionId'],
                                $embeddings[$index],
                                $productData['text'],
                                $productData['hash']
                            );
                            $batchIndexed++;
                        } catch (\Exception $e) {
                            $this->logger->error('Failed to store embedding', [
                                'product_id' => $productData['id'],
                                'error' => $e->getMessage()
                            ]);
                            $batchErrors++;
                        }
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->error('Batch embedding failed', ['error' => $e->getMessage()]);
                    $batchErrors += count($productIds);
                }
            }
            
            $totalIndexed += $batchIndexed;
            $totalErrors += $batchErrors;
            $offset += $batchSize;
            
            $this->logger->info('Processed batch', [
                'offset' => $offset,
                'batch_indexed' => $batchIndexed,
                'batch_errors' => $batchErrors,
                'total_indexed' => $totalIndexed,
                'total_errors' => $totalErrors
            ]);
        }
        
        $this->logger->info('Product indexing completed', [
            'indexed' => $totalIndexed,
            'errors' => $totalErrors,
            'total_products' => $totalProducts,
            'force' => $force
        ]);
        
        return [
            'indexed' => $totalIndexed,
            'errors' => $totalErrors,
            'total_products' => $totalProducts,
            'message' => "Successfully indexed {$totalIndexed} products" . ($force ? ' (force mode)' : '')
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
            $parts[] = trim($product->getName());
        }
        
        if ($product->getDescription()) {
            $description = trim(strip_tags($product->getDescription()));
            if (!empty($description)) {
                $parts[] = $description;
            }
        }
        
        // Manufacturer
        if ($product->getManufacturer() && $product->getManufacturer()->getName()) {
            $parts[] = trim($product->getManufacturer()->getName());
        }
        
        // Categories
        if ($product->getCategories()) {
            foreach ($product->getCategories() as $category) {
                if ($category->getName()) {
                    $parts[] = trim($category->getName());
                }
            }
        }
        
        // Properties
        if ($product->getProperties()) {
            foreach ($product->getProperties() as $property) {
                if ($property->getName()) {
                    $parts[] = trim($property->getName());
                }
                if ($property->getGroup() && $property->getGroup()->getName()) {
                    $parts[] = trim($property->getGroup()->getName());
                }
            }
        }
        
        // Filter out empty parts and join
        $text = implode(' ', array_filter($parts, function($part) {
            return !empty(trim($part));
        }));
        
        // Fallback to product ID if no meaningful text found
        if (empty($text)) {
            $text = 'Product ID: ' . $product->getId();
        }
        
        return $text;
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

        $cleanText = $this->cleanTextForEmbedding($text);
        
        if (empty($cleanText)) {
            throw new \Exception('Invalid or empty text provided for embedding');
        }

        $response = $client->embeddings()->create([
            'model' => self::OPENAI_MODEL,
            'input' => $cleanText,
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

        // Clean and validate texts for OpenAI API
        $cleanTexts = [];
        $originalIndexes = [];
        
        foreach ($texts as $index => $text) {
            $cleanText = $this->cleanTextForEmbedding($text);
            
            if (!empty($cleanText)) {
                $cleanTexts[] = $cleanText;
                $originalIndexes[] = $index;
            }
        }
        
        if (empty($cleanTexts)) {
            throw new \Exception('No valid texts provided for embedding');
        }

        // OpenAI has a limit of ~8192 tokens per input, split if too large
        $batches = $this->splitTextsIntoApiCompatibleBatches($cleanTexts);
        $allEmbeddings = [];
        
        foreach ($batches as $batch) {
            $response = $client->embeddings()->create([
                'model' => self::OPENAI_MODEL,
                'input' => $batch,
            ]);
            
            // Extract embeddings from response
            foreach ($response->embeddings as $embedding) {
                $allEmbeddings[] = $embedding->embedding;
            }
        }
        
        // Restore original order and fill missing embeddings with zeros
        $finalEmbeddings = [];
        $embeddingIndex = 0;
        
        for ($i = 0; $i < count($texts); $i++) {
            if (in_array($i, $originalIndexes)) {
                $finalEmbeddings[] = $allEmbeddings[$embeddingIndex++];
            } else {
                // Create a zero vector for invalid texts
                $finalEmbeddings[] = array_fill(0, self::EMBEDDING_DIMENSIONS, 0.0);
            }
        }
        
        return $finalEmbeddings;
    }

    /**
     * Clean text for embedding API
     */
    private function cleanTextForEmbedding(string $text): string
    {
        // Remove extra whitespace and trim
        $text = trim(preg_replace('/\s+/', ' ', $text));
        
        // Remove control characters and invalid UTF-8
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Ensure valid UTF-8
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Limit length to avoid token limits (roughly 6000 characters = ~1500 tokens)
        if (strlen($text) > 6000) {
            $text = substr($text, 0, 6000);
        }
        
        return $text;
    }

    /**
     * Split texts into API-compatible batches
     */
    private function splitTextsIntoApiCompatibleBatches(array $texts): array
    {
        // OpenAI allows up to 2048 inputs per request, but we use smaller batches for safety
        $maxBatchSize = 100;
        $batches = [];
        
        for ($i = 0; $i < count($texts); $i += $maxBatchSize) {
            $batches[] = array_slice($texts, $i, $maxBatchSize);
        }
        
        return $batches;
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
        $this->logger->info('Using JSON fallback search for older MySQL');
        
        // Fetch all embeddings from database
        $sql = '
            SELECT 
                HEX(product_id) as product_id,
                content_text,
                embedding
            FROM mh_product_embeddings
        ';
        
        $allEmbeddings = $this->connection->fetchAllAssociative($sql);
        
        if (empty($allEmbeddings)) {
            $this->logger->warning('No embeddings found in database');
            return [];
        }
        
        $similarities = [];
        
        foreach ($allEmbeddings as $row) {
            $storedEmbedding = json_decode($row['embedding'], true);
            
            if (!is_array($storedEmbedding)) {
                continue; // Skip invalid embeddings
            }
            
            // Calculate cosine similarity
            $similarity = $this->calculateCosineSimilarity($queryEmbedding, $storedEmbedding);
            
            if ($similarity >= $threshold) {
                $similarities[] = [
                    'product_id' => $row['product_id'],
                    'similarity' => $similarity,
                    'distance' => 1 - $similarity,
                    'content' => $row['content_text']
                ];
            }
        }
        
        // Sort by similarity (highest first)
        usort($similarities, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        // Return top results
        return array_slice($similarities, 0, $limit);
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    private function calculateCosineSimilarity(array $vectorA, array $vectorB): float
    {
        if (count($vectorA) !== count($vectorB)) {
            return 0.0;
        }
        
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;
        
        for ($i = 0; $i < count($vectorA); $i++) {
            $dotProduct += $vectorA[$i] * $vectorB[$i];
            $magnitudeA += $vectorA[$i] * $vectorA[$i];
            $magnitudeB += $vectorB[$i] * $vectorB[$i];
        }
        
        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);
        
        if ($magnitudeA == 0.0 || $magnitudeB == 0.0) {
            return 0.0;
        }
        
        return $dotProduct / ($magnitudeA * $magnitudeB);
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
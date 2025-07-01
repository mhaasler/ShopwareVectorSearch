<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Controller;

use MHaasler\ShopwareVectorSearch\Service\VectorSearchService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class PublicVectorSearchController extends StorefrontController
{
    public function __construct(
        private readonly VectorSearchService $vectorSearchService,
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection
    ) {
    }

    #[Route(path: '/vector-search/status', name: 'frontend.vector_search.status', methods: ['GET'], defaults: ['XmlHttpRequest' => true])]
    public function getPublicStatus(): JsonResponse
    {
        try {
            // Get embedding service status
            $embeddingStatus = $this->checkEmbeddingService();
            
            // Get basic database stats (without sensitive data)
            $dbStats = $this->getDatabaseStats();
            
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'embedding_service' => $embeddingStatus,
                    'database' => $dbStats,
                    'plugin_version' => '1.0.0',
                    'status' => 'operational'
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Service temporarily unavailable',
                'status' => 'error'
            ], 500);
        }
    }

    #[Route(path: '/vector-search/config', name: 'frontend.vector_search.config', methods: ['GET'], defaults: ['XmlHttpRequest' => true])]
    public function getConfig(): JsonResponse
    {
        try {
            
            return new JsonResponse([
                'success' => true,
                'config' => [
                    'embeddingServiceUrl' => $systemConfig->get('ShopwareVectorSearch.config.embeddingServiceUrl'),
                    'enableVectorSearch' => $systemConfig->get('ShopwareVectorSearch.config.enableVectorSearch'),
                    'batchSize' => $systemConfig->get('ShopwareVectorSearch.config.batchSize'),
                    'defaultSimilarityThreshold' => $systemConfig->get('ShopwareVectorSearch.config.defaultSimilarityThreshold'),
                    'maxSearchResults' => $systemConfig->get('ShopwareVectorSearch.config.maxSearchResults'),
                    'embeddingTimeout' => $systemConfig->get('ShopwareVectorSearch.config.embeddingTimeout'),
                    'enableLogging' => $systemConfig->get('ShopwareVectorSearch.config.enableLogging'),
                    'autoReindex' => $systemConfig->get('ShopwareVectorSearch.config.autoReindex')
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/index", name="frontend.vector_search.index", methods={"POST"}, defaults={"XmlHttpRequest"=true, "csrf_protected"=false})
     */
    public function indexProducts(Request $request): JsonResponse
    {
        try {
            $vectorSearchService = $this->container->get('MHaasler\ShopwareVectorSearch\Service\VectorSearchService');
            $context = \Shopware\Core\Framework\Context::createDefaultContext();
            // Use system context to see ALL products regardless of sales channel
            $context = new \Shopware\Core\Framework\Context(
                new \Shopware\Core\Framework\Api\Context\SystemSource(),
                [],
                \Shopware\Core\Defaults::CURRENCY,
                [\Shopware\Core\Defaults::LANGUAGE_SYSTEM]
            );
            
            // Check for force reindex parameter
            $data = json_decode($request->getContent(), true) ?? [];
            $forceReindex = $data['force_reindex'] ?? false;
            
            if ($forceReindex) {
                // Clear existing embeddings first
                $connection = $this->container->get('Doctrine\DBAL\Connection');
                $connection->executeStatement('DELETE FROM mh_product_embeddings');
            }
            
            // Check if we should use direct database indexing to bypass repository limits
            $useDirect = $data['use_direct'] ?? true; // Default to direct method
            
            if ($useDirect) {
                $result = $this->indexAllProductsDirect();
            } else {
                $result = $vectorSearchService->indexAllProducts($context);
            }
            
            return new JsonResponse([
                'success' => true,
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/test", name="frontend.vector_search.test", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function testEmbedding(): JsonResponse
    {
        try {
            // Test embedding service directly
            $systemConfig = $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService');
            $embeddingUrl = $systemConfig->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
            
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $client->post($embeddingUrl . '/embed', [
                'json' => ['text' => 'Test Laptop Computer Gaming'],
                'headers' => ['Content-Type' => 'application/json']
            ]);
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                
                return new JsonResponse([
                    'success' => true,
                    'test' => 'embedding_service',
                    'embedding_url' => $embeddingUrl,
                    'dimensions' => count($data['embedding']),
                    'model' => $data['model'] ?? 'unknown',
                    'first_5_values' => array_slice($data['embedding'], 0, 5)
                ]);
            }
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Embedding service returned: ' . $response->getStatusCode()
            ], 500);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/search", name="frontend.vector_search.search", methods={"POST"}, defaults={"XmlHttpRequest"=true, "csrf_protected"=false})
     */
    public function searchProducts(): JsonResponse
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $query = $input['query'] ?? '';
            $limit = $input['limit'] ?? 10;
            $threshold = $input['threshold'] ?? 0.7;
            
            if (empty($query)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Query parameter is required'
                ], 400);
            }
            
            // Use direct implementation instead of service for now
            $connection = $this->container->get('Doctrine\DBAL\Connection');
            $systemConfig = $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService');
            $embeddingUrl = $systemConfig->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
            
            // Get query embedding
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $client->post($embeddingUrl . '/embed', [
                'json' => ['text' => $query],
                'headers' => ['Content-Type' => 'application/json']
            ]);
            
            $embeddingData = json_decode($response->getBody()->getContents(), true);
            $queryEmbedding = $embeddingData['embedding'];
            
            // Direct vector search implementation
            $results = $this->performDirectVectorSearch($connection, $queryEmbedding, $limit, $threshold);
            
            return new JsonResponse([
                'success' => true,
                'query' => $query,
                'results' => $results,
                'count' => count($results),
                'parameters' => [
                    'limit' => $limit,
                    'threshold' => $threshold
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/debug", name="frontend.vector_search.debug", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function debugVectorSearch(): JsonResponse
    {
        try {
            $connection = $this->container->get('Doctrine\DBAL\Connection');
            
            // Check MySQL version
            $version = $connection->fetchOne('SELECT VERSION()');
            
            // Check if table exists and has data
            $tableExists = $connection->getSchemaManager()->tablesExist(['mh_product_embeddings']);
            $rowCount = 0;
            $sampleData = null;
            
            if ($tableExists) {
                $rowCount = $connection->fetchOne('SELECT COUNT(*) FROM mh_product_embeddings');
                $sampleData = $connection->fetchAssociative('SELECT HEX(product_id) as product_id, content_text, LEFT(embedding, 100) as embedding_preview FROM mh_product_embeddings LIMIT 1');
            }
            
            // Test vector function availability
            $vectorSupport = version_compare($version, '8.0.28', '>=');
            $vectorFunctionTest = null;
            
            if ($vectorSupport) {
                try {
                    $vectorFunctionTest = $connection->fetchOne("SELECT VECTOR_DISTANCE('[1,2,3]', '[1,2,3]') as test");
                } catch (\Exception $e) {
                    $vectorFunctionTest = 'Error: ' . $e->getMessage();
                }
            }
            
            // Check product counts in database
            $totalProducts = $connection->fetchOne('SELECT COUNT(*) FROM product WHERE parent_id IS NULL');
            $allProducts = $connection->fetchOne('SELECT COUNT(*) FROM product');
            $activeProducts = $connection->fetchOne('SELECT COUNT(*) FROM product WHERE active = 1 AND parent_id IS NULL');
            
            return new JsonResponse([
                'success' => true,
                'debug_info' => [
                    'mysql_version' => $version,
                    'vector_support_detected' => $vectorSupport,
                    'vector_function_test' => $vectorFunctionTest,
                    'table_exists' => $tableExists,
                    'row_count' => $rowCount,
                    'sample_data' => $sampleData,
                    'product_counts' => [
                        'total_products' => $totalProducts,
                        'all_products_including_variants' => $allProducts,
                        'active_parent_products' => $activeProducts
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/debug-search", name="frontend.vector_search.debug_search", methods={"POST"}, defaults={"XmlHttpRequest"=true, "csrf_protected"=false})
     */
    public function debugSearch(): JsonResponse
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $query = $input['query'] ?? 'test';
            
            $vectorSearchService = $this->container->get('MHaasler\ShopwareVectorSearch\Service\VectorSearchService');
            $connection = $this->container->get('Doctrine\DBAL\Connection');
            
            // Step 1: Get query embedding
            $systemConfig = $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService');
            $embeddingUrl = $systemConfig->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
            
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $client->post($embeddingUrl . '/embed', [
                'json' => ['text' => $query],
                'headers' => ['Content-Type' => 'application/json']
            ]);
            
            $embeddingData = json_decode($response->getBody()->getContents(), true);
            $queryEmbedding = $embeddingData['embedding'];
            
            // Step 2: Get sample data from database
            $sampleData = $connection->fetchAllAssociative('
                SELECT 
                    HEX(product_id) as product_id,
                    content_text,
                    LEFT(embedding, 200) as embedding_preview
                FROM mh_product_embeddings 
                LIMIT 3
            ');
            
            // Step 3: Test similarity calculation manually
            $firstEmbedding = null;
            $similarity = null;
            if (!empty($sampleData)) {
                $firstEmbedding = json_decode($connection->fetchOne('
                    SELECT embedding FROM mh_product_embeddings LIMIT 1
                '), true);
                
                if ($firstEmbedding && count($firstEmbedding) === count($queryEmbedding)) {
                    // Manual cosine similarity calculation
                    $dotProduct = 0.0;
                    $normA = 0.0;
                    $normB = 0.0;
                    
                    for ($i = 0; $i < count($queryEmbedding); $i++) {
                        $dotProduct += $queryEmbedding[$i] * $firstEmbedding[$i];
                        $normA += $queryEmbedding[$i] * $queryEmbedding[$i];
                        $normB += $firstEmbedding[$i] * $firstEmbedding[$i];
                    }
                    
                    $normA = sqrt($normA);
                    $normB = sqrt($normB);
                    $similarity = ($normA > 0 && $normB > 0) ? $dotProduct / ($normA * $normB) : 0.0;
                }
            }
            
            return new JsonResponse([
                'success' => true,
                'debug_steps' => [
                    'step1_query' => $query,
                    'step2_embedding_dimensions' => count($queryEmbedding),
                    'step3_embedding_preview' => array_slice($queryEmbedding, 0, 5),
                    'step4_database_samples' => count($sampleData),
                    'step5_sample_data' => $sampleData,
                    'step6_first_embedding_dimensions' => $firstEmbedding ? count($firstEmbedding) : null,
                    'step7_similarity_test' => $similarity
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/direct-test", name="frontend.vector_search.direct_test", methods={"POST"}, defaults={"XmlHttpRequest"=true, "csrf_protected"=false})
     */
    public function directTest(): JsonResponse
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $query = $input['query'] ?? 'Computer';
            
            $connection = $this->container->get('Doctrine\DBAL\Connection');
            $systemConfig = $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService');
            $embeddingUrl = $systemConfig->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
            
            // Step 1: Get query embedding
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $client->post($embeddingUrl . '/embed', [
                'json' => ['text' => $query],
                'headers' => ['Content-Type' => 'application/json']
            ]);
            
            $embeddingData = json_decode($response->getBody()->getContents(), true);
            $queryEmbedding = $embeddingData['embedding'];
            
            // Step 2: Get database embeddings
            $dbResults = $connection->fetchAllAssociative('
                SELECT 
                    HEX(product_id) as product_id,
                    content_text,
                    embedding
                FROM mh_product_embeddings 
                LIMIT 5
            ');
            
            $similarities = [];
            $debug = [];
            
            // Step 3: Calculate similarities manually
            foreach ($dbResults as $result) {
                $embedding = json_decode($result['embedding'], true);
                
                if (!$embedding || !is_array($embedding)) {
                    $debug[] = [
                        'product_id' => $result['product_id'],
                        'error' => 'Invalid embedding data',
                        'embedding_type' => gettype($embedding)
                    ];
                    continue;
                }
                
                if (count($embedding) !== count($queryEmbedding)) {
                    $debug[] = [
                        'product_id' => $result['product_id'],
                        'error' => 'Dimension mismatch',
                        'query_dims' => count($queryEmbedding),
                        'db_dims' => count($embedding)
                    ];
                    continue;
                }
                
                // Manual cosine similarity
                $dotProduct = 0.0;
                $normA = 0.0;
                $normB = 0.0;
                
                for ($i = 0; $i < count($queryEmbedding); $i++) {
                    $dotProduct += $queryEmbedding[$i] * $embedding[$i];
                    $normA += $queryEmbedding[$i] * $queryEmbedding[$i];
                    $normB += $embedding[$i] * $embedding[$i];
                }
                
                $normA = sqrt($normA);
                $normB = sqrt($normB);
                $similarity = ($normA > 0 && $normB > 0) ? $dotProduct / ($normA * $normB) : 0.0;
                
                $similarities[] = [
                    'product_id' => $result['product_id'],
                    'content_text' => substr($result['content_text'], 0, 100) . '...',
                    'similarity' => $similarity,
                    'distance' => 1 - $similarity
                ];
                
                $debug[] = [
                    'product_id' => $result['product_id'],
                    'similarity' => $similarity,
                    'dotProduct' => $dotProduct,
                    'normA' => $normA,
                    'normB' => $normB
                ];
            }
            
            // Sort by similarity
            usort($similarities, function($a, $b) {
                return $b['similarity'] <=> $a['similarity'];
            });
            
            return new JsonResponse([
                'success' => true,
                'query' => $query,
                'query_embedding_dims' => count($queryEmbedding),
                'db_rows_found' => count($dbResults),
                'valid_similarities' => count($similarities),
                'top_results' => array_slice($similarities, 0, 3),
                'debug_info' => $debug
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/debug-repository", name="frontend.vector_search.debug_repository", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     */
    public function debugRepository(): JsonResponse
    {
        try {
            // Test different contexts and criteria
            $productRepository = $this->container->get('product.repository');
            
            // Test 1: Default Context
            $defaultContext = \Shopware\Core\Framework\Context::createDefaultContext();
            $defaultCriteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
            $defaultCriteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('parentId', null));
            $defaultTotal = $productRepository->search($defaultCriteria, $defaultContext)->getTotal();
            
            // Test 2: System Context
            $systemContext = new \Shopware\Core\Framework\Context(
                new \Shopware\Core\Framework\Api\Context\SystemSource(),
                [],
                \Shopware\Core\Defaults::CURRENCY,
                [\Shopware\Core\Defaults::LANGUAGE_SYSTEM]
            );
            $systemCriteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
            $systemCriteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('parentId', null));
            $systemTotal = $productRepository->search($systemCriteria, $systemContext)->getTotal();
            
            // Test 3: No Filter
            $noFilterCriteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
            $noFilterTotal = $productRepository->search($noFilterCriteria, $systemContext)->getTotal();
            
            // Test 4: Include inactive products
            $allCriteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
            $allCriteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('parentId', null));
            // Remove active filter if it exists
            $allTotal = $productRepository->search($allCriteria, $systemContext)->getTotal();
            
            // Test 5: Check first 10 products
            $sampleCriteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
            $sampleCriteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('parentId', null));
            $sampleCriteria->setLimit(10);
            $sampleProducts = $productRepository->search($sampleCriteria, $systemContext);
            
            $sampleData = [];
            foreach ($sampleProducts as $product) {
                $sampleData[] = [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'active' => $product->getActive(),
                    'parent_id' => $product->getParentId()
                ];
            }
            
            return new JsonResponse([
                'success' => true,
                'repository_tests' => [
                    'default_context_with_parent_filter' => $defaultTotal,
                    'system_context_with_parent_filter' => $systemTotal,
                    'system_context_no_filter' => $noFilterTotal,
                    'system_context_all_products' => $allTotal,
                    'sample_products' => $sampleData,
                    'sample_count' => count($sampleData)
                ],
                'direct_database' => [
                    'total_products' => 1094,
                    'note' => 'This should match repository results'
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/debug-embeddings", name="vector.search.debug.embeddings", methods={"GET"})
     */
    public function debugEmbeddings(): JsonResponse
    {
        try {
            $connection = $this->container->get('Doctrine\DBAL\Connection');
            
            // Get first 10 embeddings to see what's actually stored
            $embeddings = $connection->fetchAllAssociative('
                SELECT 
                    HEX(product_id) as product_id,
                    content_text,
                    content_hash,
                    created_at,
                    CHAR_LENGTH(embedding) as embedding_length
                FROM mh_product_embeddings 
                ORDER BY created_at DESC 
                LIMIT 10
            ');
            
            // Get total count
            $totalCount = $connection->fetchOne('SELECT COUNT(*) FROM mh_product_embeddings');
            
            return new JsonResponse([
                'success' => true,
                'total_embeddings' => $totalCount,
                'sample_embeddings' => $embeddings
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/debug-index-small", name="vector.search.debug.index.small", methods={"GET"})
     */
    public function debugIndexSmall(): JsonResponse
    {
        try {
            $connection = $this->container->get('Doctrine\DBAL\Connection');
            $systemConfig = $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService');
            $embeddingUrl = $systemConfig->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
            
            // Clear existing embeddings first
            $connection->executeStatement('DELETE FROM mh_product_embeddings');
            
            $totalIndexed = 0;
            $totalErrors = 0;
            $debugInfo = [];
            
            // Get only first 5 products for debugging
            $sql = '
                SELECT 
                    HEX(p.id) as id,
                    HEX(p.version_id) as version_id,
                    pt.name,
                    pt.description,
                    mt.name as manufacturer_name
                FROM product p
                LEFT JOIN product_translation pt ON p.id = pt.product_id AND p.version_id = pt.product_version_id
                LEFT JOIN product_manufacturer pm ON p.product_manufacturer_id = pm.id AND p.product_manufacturer_version_id = pm.version_id
                LEFT JOIN product_manufacturer_translation mt ON pm.id = mt.product_manufacturer_id AND pm.version_id = mt.product_manufacturer_version_id
                WHERE p.parent_id IS NULL 
                AND p.active = 1
                AND pt.language_id = UNHEX(?)
                AND (mt.language_id = UNHEX(?) OR mt.language_id IS NULL)
                LIMIT 5';
                
            $products = $connection->fetchAllAssociative($sql, [
                str_replace('-', '', \Shopware\Core\Defaults::LANGUAGE_SYSTEM),
                str_replace('-', '', \Shopware\Core\Defaults::LANGUAGE_SYSTEM)
            ]);
            
            $debugInfo['found_products'] = count($products);
            $debugInfo['products'] = [];
            
            foreach ($products as $product) {
                $textParts = [];
                if ($product['name']) $textParts[] = $product['name'];
                if ($product['description']) $textParts[] = strip_tags($product['description']);
                if ($product['manufacturer_name']) $textParts[] = $product['manufacturer_name'];
                
                $text = implode(' ', array_filter($textParts));
                $hash = hash('sha256', $text);
                
                $debugInfo['products'][] = [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'text' => substr($text, 0, 200) . '...',
                    'hash' => $hash
                ];
                
                try {
                    // Get embedding
                    $client = new \GuzzleHttp\Client(['timeout' => 60]);
                    $response = $client->post($embeddingUrl . '/embed', [
                        'json' => ['text' => $text],
                        'headers' => ['Content-Type' => 'application/json']
                    ]);
                    
                    $embeddingData = json_decode($response->getBody()->getContents(), true);
                    $embedding = $embeddingData['embedding'];
                    
                                         // Store embedding
                     $result = $connection->executeStatement('
                         INSERT INTO mh_product_embeddings 
                         (id, product_id, product_version_id, embedding, content_text, content_hash, created_at) 
                         VALUES (UNHEX(REPLACE(UUID(), \'-\', \'\')), UNHEX(?), UNHEX(?), ?, ?, ?, NOW())
                     ', [
                         $product['id'],
                         $product['version_id'],
                         json_encode($embedding),
                         $text,
                         $hash
                     ]);
                    
                    if ($result > 0) {
                        $totalIndexed++;
                        $debugInfo['products'][count($debugInfo['products'])-1]['status'] = 'indexed';
                    } else {
                        $totalErrors++;
                        $debugInfo['products'][count($debugInfo['products'])-1]['status'] = 'failed_insert';
                    }
                    
                } catch (\Exception $e) {
                    $totalErrors++;
                    $debugInfo['products'][count($debugInfo['products'])-1]['status'] = 'error';
                    $debugInfo['products'][count($debugInfo['products'])-1]['error'] = $e->getMessage();
                }
            }
            
            // Check final count
            $finalCount = $connection->fetchOne('SELECT COUNT(*) FROM mh_product_embeddings');
            
            return new JsonResponse([
                'success' => true,
                'indexed' => $totalIndexed,
                'errors' => $totalErrors,
                'final_count_in_db' => $finalCount,
                'debug_info' => $debugInfo
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @Route("/vector-search/debug-table", name="vector.search.debug.table", methods={"GET"})
     */
    public function debugTable(): JsonResponse
    {
        try {
            $connection = $this->container->get('Doctrine\DBAL\Connection');
            
            // Show table structure
            $tableInfo = $connection->fetchAllAssociative('DESCRIBE mh_product_embeddings');
            
            // Test UNHEX with a known ID
            $testId = '001A6E61BDE44001BF266763354FE912';
            $unhexTest = $connection->fetchOne('SELECT HEX(UNHEX(?)) as result', [$testId]);
            
            // Test inserting a simple test record
            try {
                $connection->executeStatement('DELETE FROM mh_product_embeddings WHERE content_text = "TEST"');
                $insertResult = $connection->executeStatement('
                    INSERT INTO mh_product_embeddings 
                    (product_id, product_version_id, embedding, content_text, content_hash, created_at) 
                    VALUES (UNHEX(?), UNHEX(?), ?, ?, ?, NOW())
                ', [
                    '001A6E61BDE44001BF266763354FE912',
                    '0FA91CE3E96A4BC2BE4BD9CE752C3425',
                    '[]',
                    'TEST',
                    'test_hash'
                ]);
                $testInsert = "SUCCESS: $insertResult rows affected";
            } catch (\Exception $e) {
                $testInsert = "ERROR: " . $e->getMessage();
            }
            
            return new JsonResponse([
                'success' => true,
                'table_structure' => $tableInfo,
                'unhex_test' => $unhexTest,
                'test_insert' => $testInsert
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function checkEmbeddingService(): array
    {
        try {
            // Get embedding service URL from config
            $systemConfig = $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService');
            $embeddingUrl = $systemConfig->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
            
            $client = new \GuzzleHttp\Client(['timeout' => 5]);
            $response = $client->get($embeddingUrl . '/health');
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                
                // Handle proxy response structure
                if (isset($data['local_service'])) {
                    $serviceData = $data['local_service'];
                    return [
                        'status' => 'healthy',
                        'model' => $serviceData['model'] ?? 'unknown',
                        'dimensions' => $serviceData['dimensions'] ?? 0,
                        'ready' => $serviceData['ready'] ?? false,
                        'url' => $embeddingUrl,
                        'proxy' => true
                    ];
                }
                
                // Handle direct service response
                return [
                    'status' => 'healthy',
                    'model' => $data['model'] ?? 'unknown',
                    'dimensions' => $data['dimensions'] ?? 0,
                    'ready' => $data['ready'] ?? false,
                    'url' => $embeddingUrl,
                    'proxy' => false
                ];
            }
            
            return ['status' => 'error', 'message' => 'Service returned ' . $response->getStatusCode()];
            
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Connection failed'];
        }
    }

    private function getDatabaseStats(): array
    {
        try {
            $connection = $this->container->get('Doctrine\DBAL\Connection');
            
            // Check if table exists first
            $tableExists = $connection->getSchemaManager()->tablesExist(['mh_product_embeddings']);
            
            if (!$tableExists) {
                return [
                    'indexed_products' => 0,
                    'last_update' => null,
                    'table_exists' => false,
                    'status' => 'not_initialized'
                ];
            }
            
            $indexedCount = $connection->fetchOne('SELECT COUNT(*) FROM mh_product_embeddings');
            $lastUpdate = $connection->fetchOne('SELECT MAX(updated_at) FROM mh_product_embeddings');
            
            return [
                'indexed_products' => (int) $indexedCount,
                'last_update' => $lastUpdate,
                'table_exists' => true,
                'status' => $indexedCount > 0 ? 'ready' : 'empty'
            ];
            
        } catch (\Exception $e) {
            return [
                'indexed_products' => 0,
                'last_update' => null,
                'table_exists' => false,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    private function performDirectVectorSearch($connection, array $queryEmbedding, int $limit, float $threshold): array
    {
        // Get all embeddings from database
        $sql = '
            SELECT 
                HEX(product_id) as product_id,
                content_text,
                embedding
            FROM mh_product_embeddings 
            ORDER BY id
            LIMIT 1000
        ';
        
        $results = $connection->fetchAllAssociative($sql);
        $similarities = [];
        
        // Calculate cosine similarity for each embedding
        foreach ($results as $result) {
            $embedding = json_decode($result['embedding'], true);
            
            if (!$embedding || !is_array($embedding) || count($embedding) !== count($queryEmbedding)) {
                continue;
            }
            
            // Calculate cosine similarity
            $dotProduct = 0.0;
            $normA = 0.0;
            $normB = 0.0;
            
            for ($i = 0; $i < count($queryEmbedding); $i++) {
                $dotProduct += $queryEmbedding[$i] * $embedding[$i];
                $normA += $queryEmbedding[$i] * $queryEmbedding[$i];
                $normB += $embedding[$i] * $embedding[$i];
            }
            
            $normA = sqrt($normA);
            $normB = sqrt($normB);
            $similarity = ($normA > 0 && $normB > 0) ? $dotProduct / ($normA * $normB) : 0.0;
            
            $similarities[] = [
                'product_id' => $result['product_id'],
                'content_text' => $result['content_text'],
                'similarity' => $similarity,
                'distance' => 1 - $similarity
            ];
        }
        
        // Sort by similarity descending
        usort($similarities, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        // Apply threshold and limit
        $filteredResults = array_filter($similarities, function($item) use ($threshold) {
            return $item['similarity'] >= $threshold;
        });
        
        // If no results above threshold, return top results anyway
        if (empty($filteredResults) && !empty($similarities)) {
            $filteredResults = array_slice($similarities, 0, min(3, $limit));
        } else {
            $filteredResults = array_slice($filteredResults, 0, $limit);
        }
        
        // Format results
        return array_map(function ($result) {
            return [
                'product_id' => $result['product_id'],
                'similarity' => (float) $result['similarity'],
                'distance' => (float) $result['distance'],
                'content' => $result['content_text']
            ];
        }, $filteredResults);
    }

    /**
     * Index all products using direct database queries to bypass repository limits
     */
    private function indexAllProductsDirect(): array
    {
        $connection = $this->container->get('Doctrine\DBAL\Connection');
        $systemConfig = $this->container->get('Shopware\Core\System\SystemConfig\SystemConfigService');
        $embeddingUrl = $systemConfig->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
        $batchSize = $systemConfig->get('ShopwareVectorSearch.config.batchSize') ?? 100;
        
        $totalIndexed = 0;
        $totalErrors = 0;
        $offset = 0;
        
        // Get total product count
        $totalProducts = $connection->fetchOne('SELECT COUNT(*) FROM product WHERE parent_id IS NULL AND active = 1');
        
        while ($offset < $totalProducts) {
            // Get batch of products with all needed data including manufacturer
            $sql = '
                SELECT 
                    HEX(p.id) as id,
                    HEX(p.version_id) as version_id,
                    pt.name,
                    pt.description,
                    mt.name as manufacturer_name
                FROM product p
                LEFT JOIN product_translation pt ON p.id = pt.product_id AND p.version_id = pt.product_version_id
                LEFT JOIN product_manufacturer pm ON p.product_manufacturer_id = pm.id AND p.product_manufacturer_version_id = pm.version_id
                LEFT JOIN product_manufacturer_translation mt ON pm.id = mt.product_manufacturer_id AND pm.version_id = mt.product_manufacturer_version_id
                WHERE p.parent_id IS NULL 
                AND p.active = 1
                AND pt.language_id = UNHEX(?)
                AND (mt.language_id = UNHEX(?) OR mt.language_id IS NULL)
                LIMIT ' . (int)$batchSize . ' OFFSET ' . (int)$offset;
                
            $products = $connection->fetchAllAssociative($sql, [
                str_replace('-', '', \Shopware\Core\Defaults::LANGUAGE_SYSTEM),
                str_replace('-', '', \Shopware\Core\Defaults::LANGUAGE_SYSTEM)
            ]);
            
            if (empty($products)) {
                break;
            }
            
            $productTexts = [];
            $productData = [];
            
            foreach ($products as $product) {
                // Build product text
                $textParts = [];
                if ($product['name']) $textParts[] = $product['name'];
                if ($product['description']) $textParts[] = strip_tags($product['description']);
                if ($product['manufacturer_name']) $textParts[] = $product['manufacturer_name'];
                
                // Get categories for this product
                $categories = $connection->fetchAllAssociative('
                    SELECT ct.name 
                    FROM product_category pc
                    JOIN category c ON pc.category_id = c.id
                    JOIN category_translation ct ON c.id = ct.category_id
                    WHERE pc.product_id = UNHEX(?) 
                    AND ct.language_id = UNHEX(?)
                ', [
                    $product['id'],
                    str_replace('-', '', \Shopware\Core\Defaults::LANGUAGE_SYSTEM)
                ]);
                
                foreach ($categories as $category) {
                    if ($category['name']) $textParts[] = $category['name'];
                }
                
                // Get properties for this product
                $properties = $connection->fetchAllAssociative('
                    SELECT pot.name as option_name, pgt.name as group_name
                    FROM product_property pp
                    JOIN property_group_option po ON pp.property_group_option_id = po.id
                    JOIN property_group pg ON po.property_group_id = pg.id
                    JOIN property_group_option_translation pot ON po.id = pot.property_group_option_id
                    JOIN property_group_translation pgt ON pg.id = pgt.property_group_id
                    WHERE pp.product_id = UNHEX(?)
                    AND pot.language_id = UNHEX(?)
                    AND pgt.language_id = UNHEX(?)
                ', [
                    $product['id'],
                    str_replace('-', '', \Shopware\Core\Defaults::LANGUAGE_SYSTEM),
                    str_replace('-', '', \Shopware\Core\Defaults::LANGUAGE_SYSTEM)
                ]);
                
                foreach ($properties as $property) {
                    if ($property['option_name']) $textParts[] = $property['option_name'];
                    if ($property['group_name']) $textParts[] = $property['group_name'];
                }
                
                $text = implode(' ', array_filter($textParts));
                $hash = hash('sha256', $text);
                
                // Check if already indexed
                $existing = $connection->fetchOne('
                    SELECT COUNT(*) FROM mh_product_embeddings 
                    WHERE product_id = UNHEX(?) AND content_hash = ?
                ', [$product['id'], $hash]);
                
                if ($existing == 0) {
                    $productTexts[] = $text;
                    $productData[] = [
                        'id' => $product['id'],
                        'version_id' => $product['version_id'],
                        'text' => $text,
                        'hash' => $hash
                    ];
                }
            }
            
            // Process batch if we have products to index
            if (!empty($productTexts)) {
                try {
                    // Get embeddings from service
                    $client = new \GuzzleHttp\Client(['timeout' => 60]);
                    $response = $client->post($embeddingUrl . '/embed/batch', [
                        'json' => ['texts' => $productTexts],
                        'headers' => ['Content-Type' => 'application/json']
                    ]);
                    
                    $embeddingData = json_decode($response->getBody()->getContents(), true);
                    $embeddings = $embeddingData['embeddings'];
                    
                    // Store embeddings
                    foreach ($productData as $index => $product) {
                        try {
                            $result = $connection->executeStatement('
                                INSERT INTO mh_product_embeddings 
                                (id, product_id, product_version_id, embedding, content_text, content_hash, created_at) 
                                VALUES (UNHEX(REPLACE(UUID(), \'-\', \'\')), UNHEX(?), UNHEX(?), ?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE 
                                embedding = VALUES(embedding), 
                                content_text = VALUES(content_text), 
                                content_hash = VALUES(content_hash),
                                updated_at = NOW()
                            ', [
                                $product['id'],
                                $product['version_id'],
                                json_encode($embeddings[$index]),
                                $product['text'],
                                $product['hash']
                            ]);
                            
                            // Debug: Check if insert was successful
                            if ($result > 0) {
                                $totalIndexed++;
                            } else {
                                error_log("Failed to insert embedding for product: " . $product['id']);
                                $totalErrors++;
                            }
                        } catch (\Exception $e) {
                            error_log("Exception inserting embedding for product " . $product['id'] . ": " . $e->getMessage());
                            $totalErrors++;
                        }
                    }
                    
                } catch (\Exception $e) {
                    $totalErrors += count($productData);
                }
            }
            
            $offset += $batchSize;
        }
        
        return [
            'indexed' => $totalIndexed,
            'errors' => $totalErrors,
            'total_products' => $totalProducts,
            'method' => 'direct_database',
            'message' => "Successfully indexed {$totalIndexed} of {$totalProducts} products using direct database method"
        ];
    }
} 
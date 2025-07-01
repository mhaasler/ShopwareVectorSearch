<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Controller;

use MHaasler\ShopwareVectorSearch\Service\VectorSearchService;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class VectorSearchController extends AbstractController
{
    public function __construct(
        private readonly VectorSearchService $vectorSearchService,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    #[Route(path: '/api/vector-search/index', name: 'api.vector_search.index', methods: ['POST'])]
    public function indexProducts(Context $context): JsonResponse
    {
        try {
            $result = $this->vectorSearchService->indexAllProducts($context);
            
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

    #[Route(path: '/api/vector-search/search', name: 'api.vector_search.search', methods: ['POST'])]
    public function search(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['query']) || empty($data['query'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Query parameter is required'
                ], 400);
            }
            
            $query = $data['query'];
            $limit = $data['limit'] ?? 20;
            $threshold = $data['threshold'] ?? 0.7;
            
            $results = $this->vectorSearchService->searchProducts($query, $limit, $threshold);
            
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'query' => $query,
                    'results' => $results,
                    'count' => count($results),
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

    #[Route(path: '/api/vector-search/status', name: 'api.vector_search.status', methods: ['GET'])]
    public function getStatus(): JsonResponse
    {
        try {
            // Get embedding service status
            $embeddingStatus = $this->checkEmbeddingService();
            
            // Get database stats
            $dbStats = $this->getDatabaseStats();
            
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'embedding_service' => $embeddingStatus,
                    'database' => $dbStats,
                    'plugin_version' => '1.0.0'
                ]
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route(path: '/api/vector-search/config', name: 'api.vector_search.config', methods: ['GET'])]
    public function getConfig(): JsonResponse
    {
        try {
            $config = [
                'embeddingMode' => $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingMode') ?? 'embedding_service',
                'embeddingServiceUrl' => $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001',
                'openAiApiKey' => !empty($this->systemConfigService->get('ShopwareVectorSearch.config.openAiApiKey')),
                'enableVectorSearch' => $this->systemConfigService->get('ShopwareVectorSearch.config.enableVectorSearch') ?? true,
                'batchSize' => $this->systemConfigService->get('ShopwareVectorSearch.config.batchSize') ?? 100,
                'defaultSimilarityThreshold' => $this->systemConfigService->get('ShopwareVectorSearch.config.defaultSimilarityThreshold') ?? 0.7,
                'maxSearchResults' => $this->systemConfigService->get('ShopwareVectorSearch.config.maxSearchResults') ?? 20,
                'embeddingTimeout' => $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingTimeout') ?? 30,
                'enableLogging' => $this->systemConfigService->get('ShopwareVectorSearch.config.enableLogging') ?? false,
                'autoReindex' => $this->systemConfigService->get('ShopwareVectorSearch.config.autoReindex') ?? true,
            ];
            
            return new JsonResponse([
                'success' => true,
                'data' => $config
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route(path: '/api/vector-search/test-connection', name: 'api.vector_search.test_connection', methods: ['GET'])]
    public function testConnection(): JsonResponse
    {
        try {
            $embeddingUrl = $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
            
            $client = new \GuzzleHttp\Client(['timeout' => 10]);
            $response = $client->get($embeddingUrl . '/health');
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'embedding_service' => [
                            'status' => 'healthy',
                            'url' => $embeddingUrl,
                            'model' => $data['model'] ?? 'unknown',
                            'dimensions' => $data['dimensions'] ?? 0,
                            'ready' => $data['ready'] ?? false
                        ],
                        'connection_test' => 'passed'
                    ]
                ]);
            }
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Embedding service returned status ' . $response->getStatusCode(),
                'data' => [
                    'embedding_service' => [
                        'status' => 'error',
                        'url' => $embeddingUrl
                    ]
                ]
            ], 500);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Connection test failed: ' . $e->getMessage(),
                'data' => [
                    'embedding_service' => [
                        'status' => 'error',
                        'url' => $embeddingUrl ?? 'unknown'
                    ]
                ]
            ], 500);
        }
    }

    private function checkEmbeddingService(): array
    {
        try {
            $embeddingUrl = $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
            
            $client = new \GuzzleHttp\Client(['timeout' => 5]);
            $response = $client->get($embeddingUrl . '/health');
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                
                return [
                    'status' => 'healthy',
                    'url' => $embeddingUrl,
                    'model' => $data['model'] ?? 'unknown',
                    'dimensions' => $data['dimensions'] ?? 0,
                    'ready' => $data['ready'] ?? false
                ];
            }
            
            return [
                'status' => 'error',
                'url' => $embeddingUrl,
                'error' => 'HTTP ' . $response->getStatusCode()
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'url' => $embeddingUrl ?? 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }

    private function getDatabaseStats(): array
    {
        // Implementation would require DBAL connection injection
        return [
            'status' => 'available',
            'total_embeddings' => 0, // Would be calculated from database
            'last_indexed' => null
        ];
    }
} 
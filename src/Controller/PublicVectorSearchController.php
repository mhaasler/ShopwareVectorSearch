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

    #[Route(path: '/vector-search/search', name: 'frontend.vector_search.search', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function search(Request $request): JsonResponse
    {
        // Authentifizierung prüfen
        $accessKey = $request->headers->get('sw-access-key');
        if (!$this->isValidAccessKey($accessKey)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid or missing sw-access-key'
            ], 401);
        }

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
                'error' => 'Search failed: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route(path: '/vector-search/health', name: 'frontend.vector_search.health', methods: ['GET'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function health(): JsonResponse
    {
        try {
            // Basis-Gesundheitsprüfung
            $embeddingStatus = $this->checkEmbeddingService();
            $dbStatus = $this->checkDatabaseStatus();
            
            $isHealthy = $embeddingStatus['status'] === 'healthy' && $dbStatus['status'] === 'healthy';
            
            return new JsonResponse([
                'success' => true,
                'status' => $isHealthy ? 'healthy' : 'degraded',
                'data' => [
                    'embedding_service' => $embeddingStatus,
                    'database' => $dbStatus,
                    'plugin_version' => '1.0.0'
                ]
            ], $isHealthy ? 200 : 503);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'status' => 'error',
                'error' => 'Health check failed'
            ], 503);
        }
    }

    private function isValidAccessKey(?string $accessKey): bool
    {
        if (empty($accessKey)) {
            return false;
        }

        try {
            // Prüfe gegen konfigurierte Sales Channel Access Keys
            $sql = 'SELECT COUNT(*) FROM sales_channel WHERE access_key = :accessKey AND active = 1';
            $count = $this->connection->fetchOne($sql, ['accessKey' => $accessKey]);
            
            return $count > 0;
        } catch (\Exception $e) {
            // Fallback: Prüfe gegen statischen Key (für Tests)
            return $accessKey === 'SWSCMEZTEUJYNMY0WDI2TXC4YQ';
        }
    }

    private function checkEmbeddingService(): array
    {
        try {
            $embeddingMode = $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingMode') ?? 'embedding_service';
            
            if ($embeddingMode === 'openai_direct') {
                // OpenAI Direct Mode - prüfe nur ob API Key konfiguriert ist
                $apiKey = $this->systemConfigService->get('ShopwareVectorSearch.config.openAiApiKey');
                return [
                    'status' => !empty($apiKey) ? 'healthy' : 'error',
                    'mode' => 'openai_direct',
                    'configured' => !empty($apiKey)
                ];
            }
            
            // Embedding Service Mode
            $embeddingUrl = $this->systemConfigService->get('ShopwareVectorSearch.config.embeddingServiceUrl') ?? 'http://localhost:8001';
            
            $client = new \GuzzleHttp\Client(['timeout' => 5]);
            $response = $client->get($embeddingUrl . '/health');
            
            if ($response->getStatusCode() === 200) {
                return [
                    'status' => 'healthy',
                    'mode' => 'embedding_service',
                    'url' => $embeddingUrl
                ];
            }
            
            return [
                'status' => 'error',
                'mode' => 'embedding_service',
                'url' => $embeddingUrl,
                'error' => 'Service unavailable'
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'mode' => $embeddingMode ?? 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }

    private function checkDatabaseStatus(): array
    {
        try {
            // Prüfe ob Tabelle existiert und Daten enthält
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mh_product_embeddings'"
            );
            
            if (!$tableExists) {
                return [
                    'status' => 'error',
                    'error' => 'Embeddings table does not exist'
                ];
            }
            
            $embeddingCount = $this->connection->fetchOne('SELECT COUNT(*) FROM mh_product_embeddings');
            
            return [
                'status' => 'healthy',
                'embeddings_count' => (int)$embeddingCount,
                'table_exists' => true
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
} 
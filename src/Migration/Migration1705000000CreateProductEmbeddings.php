<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1705000000CreateProductEmbeddings extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1705000000;
    }

    public function update(Connection $connection): void
    {
        // Check MySQL version for Vector support
        $version = $connection->fetchOne('SELECT VERSION()');
        $supportsVector = version_compare($version, '8.0.28', '>=');
        
        if ($supportsVector) {
            $this->createVectorTable($connection);
        } else {
            $this->createJsonTable($connection);
        }
        
        // Create index table for fast lookups
        $this->createIndexTable($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Drop tables on uninstall
        $connection->executeStatement('DROP TABLE IF EXISTS `mh_product_embeddings`');
        $connection->executeStatement('DROP TABLE IF EXISTS `mh_embedding_index`');
    }

    private function createVectorTable(Connection $connection): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `mh_product_embeddings` (
    `id` BINARY(16) NOT NULL PRIMARY KEY,
    `product_id` BINARY(16) NOT NULL,
    `product_version_id` BINARY(16) NOT NULL,
    `embedding` VECTOR(1536) NOT NULL COMMENT 'Vector embedding (1536 dimensions for text-embedding-ada-002)',
    `content_hash` VARCHAR(64) NOT NULL COMMENT 'SHA256 hash of content for change detection',
    `content_text` TEXT NOT NULL COMMENT 'Original text that was embedded',
    `embedding_model` VARCHAR(100) NOT NULL DEFAULT 'text-embedding-ada-002',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    UNIQUE KEY `uniq_product_version` (`product_id`, `product_version_id`),
    INDEX `idx_content_hash` (`content_hash`),
    INDEX `idx_model` (`embedding_model`),
    INDEX `idx_updated` (`updated_at`),
    
    -- Vector Index (MySQL 8.0.28+)
    VECTOR INDEX `vec_idx_embedding` (`embedding`),
    
    -- Foreign Key
    CONSTRAINT `fk_product_embeddings_product` 
        FOREIGN KEY (`product_id`, `product_version_id`) 
        REFERENCES `product` (`id`, `version_id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Product embeddings with MySQL 8.0+ Vector support';
SQL;
        
        $connection->executeStatement($sql);
    }

    private function createJsonTable(Connection $connection): void
    {
        // Fallback for older MySQL versions
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `mh_product_embeddings` (
    `id` BINARY(16) NOT NULL PRIMARY KEY,
    `product_id` BINARY(16) NOT NULL,
    `product_version_id` BINARY(16) NOT NULL,
    `embedding` JSON NOT NULL COMMENT 'Vector embedding as JSON array',
    `content_hash` VARCHAR(64) NOT NULL COMMENT 'SHA256 hash of content for change detection',
    `content_text` TEXT NOT NULL COMMENT 'Original text that was embedded',
    `embedding_model` VARCHAR(100) NOT NULL DEFAULT 'text-embedding-ada-002',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    UNIQUE KEY `uniq_product_version` (`product_id`, `product_version_id`),
    INDEX `idx_content_hash` (`content_hash`),
    INDEX `idx_model` (`embedding_model`),
    INDEX `idx_updated` (`updated_at`),
    
    -- Foreign Key
    CONSTRAINT `fk_product_embeddings_product` 
        FOREIGN KEY (`product_id`, `product_version_id`) 
        REFERENCES `product` (`id`, `version_id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Product embeddings with JSON fallback for older MySQL';
SQL;
        
        $connection->executeStatement($sql);
    }

    private function createIndexTable(Connection $connection): void
    {
        // Fast lookup table for search results
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `mh_embedding_index` (
    `id` BINARY(16) NOT NULL PRIMARY KEY,
    `query_hash` VARCHAR(64) NOT NULL COMMENT 'Hash of search query',
    `query_text` TEXT NOT NULL COMMENT 'Original search query',
    `results` JSON NOT NULL COMMENT 'Cached search results',
    `result_count` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NOT NULL,
    
    -- Indexes
    UNIQUE KEY `uniq_query_hash` (`query_hash`),
    INDEX `idx_expires` (`expires_at`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Search result cache for vector queries';
SQL;
        
        $connection->executeStatement($sql);
    }
} 
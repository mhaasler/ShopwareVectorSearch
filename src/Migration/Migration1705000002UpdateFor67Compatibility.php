<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1705000002UpdateFor67Compatibility extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1705000002;
    }

    public function update(Connection $connection): void
    {
        // Check if table exists
        $tableExists = $connection->getSchemaManager()->tablesExist(['mh_product_embeddings']);
        
        if (!$tableExists) {
            // Table doesn't exist yet, skip this migration
            return;
        }

        // Check MySQL version for Vector support
        $version = $connection->fetchOne('SELECT VERSION()');
        $supportsVector = version_compare($version, '8.0.28', '>=');
        
        if ($supportsVector) {
            $this->updateVectorTable($connection);
        } else {
            $this->updateJsonTable($connection);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes needed
    }

    private function updateVectorTable(Connection $connection): void
    {
        // Check current vector dimensions
        $result = $connection->fetchAssociative(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'mh_product_embeddings' 
             AND COLUMN_NAME = 'embedding'"
        );

        if ($result && str_contains($result['COLUMN_TYPE'], 'vector(384)')) {
            // Need to update from 384 to 1536 dimensions
            $this->recreateVectorTable($connection);
        }

        // Update default embedding model
        $connection->executeStatement(
            "ALTER TABLE `mh_product_embeddings` 
             ALTER COLUMN `embedding_model` SET DEFAULT 'text-embedding-ada-002'"
        );
    }

    private function updateJsonTable(Connection $connection): void
    {
        // For JSON table, just update the default model
        $connection->executeStatement(
            "ALTER TABLE `mh_product_embeddings` 
             ALTER COLUMN `embedding_model` SET DEFAULT 'text-embedding-ada-002'"
        );
    }

    private function recreateVectorTable(Connection $connection): void
    {
        // Backup existing data
        $connection->executeStatement(
            "CREATE TEMPORARY TABLE mh_product_embeddings_backup AS 
             SELECT id, product_id, product_version_id, content_hash, content_text, 
                    created_at, updated_at FROM mh_product_embeddings"
        );

        // Drop and recreate table with new dimensions
        $connection->executeStatement('DROP TABLE `mh_product_embeddings`');
        
        $sql = <<<SQL
CREATE TABLE `mh_product_embeddings` (
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
COMMENT='Product embeddings with MySQL 8.0+ Vector support - Updated for Shopware 6.7';
SQL;
        
        $connection->executeStatement($sql);

        // Restore non-embedding data (embeddings need to be regenerated)
        $connection->executeStatement(
            "INSERT INTO `mh_product_embeddings` 
             (id, product_id, product_version_id, content_hash, content_text, created_at, updated_at)
             SELECT id, product_id, product_version_id, content_hash, content_text, created_at, updated_at 
             FROM mh_product_embeddings_backup"
        );

        // Clean up backup table
        $connection->executeStatement('DROP TEMPORARY TABLE mh_product_embeddings_backup');
    }
} 
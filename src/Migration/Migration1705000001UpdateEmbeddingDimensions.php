<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1705000001UpdateEmbeddingDimensions extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1705000001;
    }

    public function update(Connection $connection): void
    {
        // Check MySQL version for Vector support
        $version = $connection->fetchOne('SELECT VERSION()');
        $supportsVector = version_compare($version, '8.0.28', '>=');
        
        if ($supportsVector) {
            $this->updateVectorTable($connection);
        } else {
            // JSON table doesn't need dimension updates
            $this->clearJsonEmbeddings($connection);
        }
        
        // Clear embedding cache
        $this->clearEmbeddingCache($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
        // This migration only updates existing tables, no destructive changes needed
    }

    private function updateVectorTable(Connection $connection): void
    {
        // First, drop the existing vector index
        try {
            $connection->executeStatement('ALTER TABLE `mh_product_embeddings` DROP INDEX `vec_idx_embedding`');
        } catch (\Exception $e) {
            // Index might not exist, ignore error
        }
        
        // Clear all existing embeddings (wrong dimensions)
        $connection->executeStatement('DELETE FROM `mh_product_embeddings`');
        
        // Update the vector column to support OpenAI dimensions (1536)
        $connection->executeStatement('
            ALTER TABLE `mh_product_embeddings` 
            MODIFY COLUMN `embedding` VECTOR(1536) NOT NULL COMMENT "Vector embedding (1536 dimensions for OpenAI)"
        ');
        
        // Update default embedding model
        $connection->executeStatement('
            ALTER TABLE `mh_product_embeddings` 
            MODIFY COLUMN `embedding_model` VARCHAR(100) NOT NULL DEFAULT "text-embedding-ada-002"
        ');
        
        // Recreate the vector index
        $connection->executeStatement('
            ALTER TABLE `mh_product_embeddings` 
            ADD VECTOR INDEX `vec_idx_embedding` (`embedding`)
        ');
        
        echo "✅ Updated embedding table for OpenAI (1536 dimensions)\n";
        echo "🗑️  Cleared all existing embeddings (dimension mismatch)\n";
        echo "🔄 Ready for re-indexing with OpenAI embeddings\n";
    }

    private function clearJsonEmbeddings(Connection $connection): void
    {
        // For JSON fallback, just clear existing embeddings
        $connection->executeStatement('DELETE FROM `mh_product_embeddings`');
        
        // Update default embedding model
        $connection->executeStatement('
            ALTER TABLE `mh_product_embeddings` 
            MODIFY COLUMN `embedding_model` VARCHAR(100) NOT NULL DEFAULT "text-embedding-ada-002"
        ');
        
        echo "✅ Cleared JSON embeddings for OpenAI compatibility\n";
    }

    private function clearEmbeddingCache(Connection $connection): void
    {
        // Clear the search cache since embeddings changed
        try {
            $connection->executeStatement('DELETE FROM `mh_embedding_index`');
            echo "🗑️  Cleared embedding search cache\n";
        } catch (\Exception $e) {
            // Table might not exist yet, ignore
        }
    }
} 
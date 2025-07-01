<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'shopware:vector-search:debug',
    description: 'Debug MySQL version and vector support'
)]
class DebugCommand extends Command
{
    public function __construct(
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Vector Search - MySQL Debug Information');

        try {
            // Get MySQL version
            $version = $this->connection->fetchOne('SELECT VERSION()');
            $io->text("MySQL Version: $version");
            
            // Check if version supports VECTOR
            $supportsVector = version_compare($version, '8.0.28', '>=');
            $io->text("Supports MySQL VECTOR: " . ($supportsVector ? '✓ YES' : '✗ NO'));
            $io->text("Required: MySQL 8.0.28+");
            
            if (!$supportsVector) {
                $io->warning("Your MySQL version is too old for native VECTOR support. Using JSON fallback.");
                return Command::SUCCESS;
            }
            
            // Check if VECTOR type is actually available
            try {
                $this->connection->executeStatement('CREATE TEMPORARY TABLE test_vector (id INT, vec VECTOR(3))');
                $this->connection->executeStatement('DROP TEMPORARY TABLE test_vector');
                $io->success("✓ VECTOR data type is working!");
                
                // Check if table was created with VECTOR or JSON
                $tableStructure = $this->connection->fetchAllAssociative(
                    "SHOW CREATE TABLE mh_product_embeddings"
                );
                
                if (!empty($tableStructure)) {
                    $createSQL = $tableStructure[0]['Create Table'];
                    $io->section('Table Structure:');
                    
                    if (strpos($createSQL, 'VECTOR(1536)') !== false) {
                        $io->success("✓ Table uses VECTOR(1536) data type");
                    } elseif (strpos($createSQL, 'JSON') !== false) {
                        $io->warning("⚠ Table uses JSON data type (fallback mode)");
                        $io->note("You may need to recreate the table to use VECTOR support");
                    } else {
                        $io->error("? Unknown embedding column type");
                    }
                    
                    // Show the relevant part of CREATE TABLE
                    $lines = explode("\n", $createSQL);
                    foreach ($lines as $line) {
                        if (strpos($line, 'embedding') !== false) {
                            $io->text("Embedding column: " . trim($line));
                        }
                    }
                }
                
            } catch (\Exception $e) {
                $io->error("✗ VECTOR data type test failed: " . $e->getMessage());
                $io->warning("Even though version >= 8.0.28, VECTOR support seems disabled");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Database check failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
} 
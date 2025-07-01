<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'shopware:vector-search:clear',
    description: 'Clear all vector search data'
)]
class ClearCommand extends Command
{
    public function __construct(
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip confirmation prompt'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $io->title('Shopware Vector Search - Clear Data');

        try {
            // Get current count
            $count = $this->connection->fetchOne('SELECT COUNT(*) FROM mh_product_embeddings');
            
            if ($count == 0) {
                $io->success('No vector data found to clear.');
                return Command::SUCCESS;
            }

            $io->warning(sprintf('This will delete %d product embeddings from the database.', $count));

            if (!$force && !$io->confirm('Are you sure you want to continue?', false)) {
                $io->text('Operation cancelled.');
                return Command::SUCCESS;
            }

            $io->text('Clearing vector data...');

            $deletedRows = $this->connection->executeStatement('DELETE FROM mh_product_embeddings');

            $io->success(sprintf('Successfully deleted %d product embeddings.', $deletedRows));
            $io->note('Run "shopware:vector-search:index" to rebuild the vector index.');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to clear data: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
} 
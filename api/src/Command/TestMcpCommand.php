<?php

namespace App\Command;

use App\Agent\Mcp\McpInProcessClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:test-mcp')]
final class TestMcpCommand extends Command
{
    public function __construct(
        private readonly McpInProcessClient $mcp,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'api_platform.mcp.metadata.operation.mcp_factory')]
        private readonly \ApiPlatform\Metadata\Operation\Factory\OperationMetadataFactoryInterface $opFactory,
    ) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $op = $this->opFactory->create('list_products');
        $output->writeln('Operation class: '.get_class($op));
        $output->writeln('Filters: '.json_encode($op->getFilters()));
        $output->writeln('Provider: '.($op->getProvider() ?? 'null'));
        return Command::SUCCESS;
    }
}
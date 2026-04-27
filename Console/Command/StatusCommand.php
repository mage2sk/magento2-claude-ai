<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\ToolRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly ToolRegistry $tools,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth_claudeai:status')
             ->setDescription('Show Panth Claude AI module status: config, enabled tools, recent activity counts.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Panth Claude AI — Status</info>');
        $output->writeln('───────────────────────────');
        $output->writeln('Module enabled:       ' . ($this->config->isEnabled() ? '<info>yes</info>' : '<comment>no</comment>'));
        $output->writeln('API key configured:   ' . ($this->config->getApiKey() !== '' ? '<info>yes</info>' : '<error>no</error>'));
        $output->writeln('Model:                ' . $this->config->getModel());
        $output->writeln('Effort:               ' . $this->config->getEffort());
        $output->writeln('Max iterations:       ' . $this->config->getMaxIterations());
        $output->writeln('Dry run:              ' . ($this->config->isDryRun() ? '<comment>ON (no real writes)</comment>' : 'off'));
        $output->writeln('Bulk cap per call:    ' . $this->config->getMaxBulkUpdate());

        $output->writeln('');
        $output->writeln('<info>Enabled tools</info>');
        $enabled = $this->tools->enabled();
        if (empty($enabled)) {
            $output->writeln('  <comment>(none — check Tool Capabilities config)</comment>');
        } else {
            foreach ($enabled as $name => $_t) {
                $output->writeln('  • ' . $name);
            }
        }

        try {
            $conn = $this->resource->getConnection();
            $actTable = $this->resource->getTableName('panth_claudeai_activity');
            if ($conn->isTableExists($actTable)) {
                $tot = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$actTable}");
                $today = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$actTable} WHERE DATE(created_at) = CURDATE()");
                $output->writeln('');
                $output->writeln('<info>Activity</info>');
                $output->writeln('  Total entries:      ' . $tot);
                $output->writeln('  Today:              ' . $today);
            }
            $chkTable = $this->resource->getTableName('panth_claudeai_checkpoint');
            if ($conn->isTableExists($chkTable)) {
                $active = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$chkTable} WHERE status = 'active'");
                $output->writeln('  Active checkpoints: ' . $active);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not read activity table: ' . $e->getMessage() . '</error>');
        }

        return Command::SUCCESS;
    }
}

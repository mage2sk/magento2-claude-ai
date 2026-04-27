<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Console\Command;

use Panth\ClaudeAi\Model\ClaudeClient;
use Panth\ClaudeAi\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestApiCommand extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly ClaudeClient $client
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panth_claudeai:test-api')
             ->setDescription('Send a one-token roundtrip to Anthropic to verify the API key + connectivity.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->config->getApiKey() === '') {
            $output->writeln('<error>API key not configured.</error>');
            return Command::FAILURE;
        }
        $output->writeln('Sending test request to Anthropic API…');
        $startedAt = microtime(true);
        try {
            $resp = $this->client->send(
                [['role' => 'user', 'content' => 'Reply with the single word OK.']],
                'You are a connectivity test. Reply with one word only: OK',
                []
            );
            $ms = (int) round((microtime(true) - $startedAt) * 1000);
            $text = '';
            foreach ($resp['content'] ?? [] as $b) {
                if (($b['type'] ?? '') === 'text') {
                    $text .= ($b['text'] ?? '');
                }
            }
            $usage = $resp['usage'] ?? [];
            $output->writeln('<info>✔ Success (' . $ms . 'ms)</info>');
            $output->writeln('Reply:        "' . trim($text) . '"');
            $output->writeln('Input tokens: ' . (int) ($usage['input_tokens'] ?? 0));
            $output->writeln('Output tokens:' . (int) ($usage['output_tokens'] ?? 0));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>✘ ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}

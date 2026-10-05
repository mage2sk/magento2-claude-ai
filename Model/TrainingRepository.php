<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Panth\ClaudeAi\Model\ResourceModel\Training\CollectionFactory;

class TrainingRepository
{
    private ?array $cache = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getActiveExamples(?int $limit = null): array
    {
        if ($this->cache === null) {
            $coll = $this->collectionFactory->create();
            $coll->addFieldToFilter('status', Training::STATUS_ACTIVE)
                 ->setOrder('sort_order', 'ASC')
                 ->setOrder('training_id', 'ASC');
            $this->cache = array_values($coll->getItems());
        }
        if ($limit !== null) {
            return array_slice($this->cache, 0, $limit);
        }
        return $this->cache;
    }

    public function renderForSystemPrompt(?int $limit = 20): string
    {
        $examples = $this->getActiveExamples($limit);
        if (empty($examples)) {
            return '';
        }
        $out  = "\n# Training examples (merchant-curated)\n";
        $out .= "These examples teach you the conventions of THIS specific store. ";
        $out .= "Treat them as the merchant's preferences - when a similar request comes in, follow the pattern.\n\n";
        foreach ($examples as $i => $ex) {
            $idx = $i + 1;
            $title = (string) $ex->getData('title');
            $userMsg = (string) $ex->getData('user_message');
            $expected = (string) $ex->getData('expected_outcome');
            $out .= "## Example {$idx}: {$title}\n";
            $out .= "**Merchant says:** \"{$userMsg}\"\n";
            $out .= "**You should:** {$expected}\n\n";
        }
        return $out;
    }
}

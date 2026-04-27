<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Panth\ClaudeAi\Model\ResourceModel\Training\CollectionFactory;

/**
 * Reads training examples to inject as few-shot context into the system prompt.
 *
 * Cache-aware: returns the active set sorted deterministically (sort_order,
 * then id) so the rendered system prompt's bytes stay stable across requests.
 * That keeps the Anthropic prompt-cache prefix valid — see
 * shared/prompt-caching.md.
 */
class TrainingRepository
{
    /** @var array<int,\Panth\ClaudeAi\Model\Training>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param int|null $limit
     * @return Training[] Active training examples in stable order.
     */
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

    /**
     * Render examples as a Markdown block for the system prompt.
     */
    public function renderForSystemPrompt(?int $limit = 20): string
    {
        $examples = $this->getActiveExamples($limit);
        if (empty($examples)) {
            return '';
        }
        $out  = "\n# Training examples (merchant-curated)\n";
        $out .= "These examples teach you the conventions of THIS specific store. ";
        $out .= "Treat them as the merchant's preferences — when a similar request comes in, follow the pattern.\n\n";
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

<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds the training table with curated examples on first install.
 *
 * Each example is a few-shot prompt that gets injected into the Claude
 * system prompt. Together they teach the model:
 *   - Magento conventions (status enum, type IDs, attribute codes)
 *   - Common merchant intents (bulk pricing, sale discounts, stock checks)
 *   - When to use which tool (read first, then write; checkpoint before
 *     destructive ops; flush relevant caches after price updates)
 *   - Refusal patterns (delete → refuse and suggest disable)
 *
 * Idempotent: skipped if any rows already exist (so admins who curate
 * their own examples don't get clobbered on re-install).
 */
class SeedTrainingExamples implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup
    ) {
    }

    public static function getDependencies(): array { return []; }
    public function getAliases(): array { return []; }

    public function apply(): self
    {
        $this->setup->startSetup();
        $conn  = $this->setup->getConnection();
        $table = $this->setup->getTable('panth_claudeai_training');

        if (!$conn->isTableExists($table)) {
            $this->setup->endSetup();
            return $this;
        }

        // Don't clobber existing data on re-install.
        $existing = (int) $conn->fetchOne("SELECT COUNT(*) FROM {$table}");
        if ($existing > 0) {
            $this->setup->endSetup();
            return $this;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($this->examples() as $i => $row) {
            $conn->insert($table, array_merge($row, [
                'sort_order'  => $i * 10,
                'status'      => 1,
                'usage_count' => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]));
        }

        $this->setup->endSetup();
        return $this;
    }

    /** @return array<int,array<string,string>> */
    private function examples(): array
    {
        return [
            // ─── Pricing ──────────────────────────────────────────
            [
                'title'            => 'Bulk discount by percentage',
                'category'         => 'pricing',
                'user_message'     => 'Discount all hoodies by 20%',
                'expected_outcome' =>
                    "Step 1: Call get_products with name_contains='hoodie' (limit 50). "
                    . "Step 2: Echo the count and 3 sample SKU+name pairs. "
                    . "Step 3: Ask 'Should I apply -20% to these N hoodies?' and WAIT. "
                    . "Step 4: On 'yes', call update_product_price with name_contains='hoodie' and percent_change=-20. "
                    . "Step 5: Reply with affected_count, the checkpoint_id, and 'Reply undo to revert.'",
            ],
            [
                'title'            => 'Set absolute price by SKU pattern',
                'category'         => 'pricing',
                'user_message'     => 'Make every t-shirt cost $24.99',
                'expected_outcome' =>
                    "Use get_products first (name_contains='t-shirt' OR sku_pattern depending on the user's naming). "
                    . "Confirm count. After explicit yes, call update_product_price with new_price=24.99. "
                    . "Always echo the checkpoint_id back so the merchant can undo.",
            ],
            [
                'title'            => 'Round prices to whole dollars',
                'category'         => 'pricing',
                'user_message'     => 'Round all prices to the nearest whole dollar',
                'expected_outcome' =>
                    "This is NOT supported — there is no rounding tool. Reply: \"I can't round in bulk yet — "
                    . "the tools I have set absolute prices or apply a percent change. If you tell me which "
                    . "products you mean, I can compute and apply individual prices. Want me to try?\"",
            ],

            // ─── Inventory ─────────────────────────────────────────
            [
                'title'            => 'Find products running low',
                'category'         => 'inventory',
                'user_message'     => 'Which products are almost out of stock?',
                'expected_outcome' =>
                    "Call get_low_stock_products with threshold=5 (typical 'almost out'). "
                    . "Reply with the count and a short table (SKU, name, qty). No write needed.",
            ],
            [
                'title'            => 'Restock by SKU list',
                'category'         => 'inventory',
                'user_message'     => 'Add 50 to stock for SKU MH01, MH02, MH03',
                'expected_outcome' =>
                    "Use update_inventory with sku_list=['MH01','MH02','MH03'] and qty_change=50. "
                    . "Three items is below the confirmation threshold so this can run directly. "
                    . "Reply with new quantities and the checkpoint_id.",
            ],
            [
                'title'            => 'Disable out-of-stock products',
                'category'         => 'inventory',
                'user_message'     => 'Disable everything that\'s out of stock',
                'expected_outcome' =>
                    "Step 1: Call get_low_stock_products with threshold=0 to find SKUs with zero stock. "
                    . "Step 2: Echo the count (could be many — be careful). Ask 'Disable these N products?'. "
                    . "Step 3: On yes, call update_product_status with status='disable' and the sku_list. "
                    . "Reply with affected count and checkpoint_id.",
            ],

            // ─── Status ────────────────────────────────────────────
            [
                'title'            => 'Re-enable accidentally disabled products',
                'category'         => 'status',
                'user_message'     => 'Turn the t-shirts back on',
                'expected_outcome' =>
                    "Use get_products with name_contains='t-shirt' to find all matching SKUs. "
                    . "Confirm count. After yes, call update_product_status with status='enable'.",
            ],

            // ─── Insights ──────────────────────────────────────────
            [
                'title'            => 'Recent orders',
                'category'         => 'insights',
                'user_message'     => 'How are sales today?',
                'expected_outcome' =>
                    "Call store_insights with metric='order_count_by_status' for an at-a-glance view, "
                    . "then store_insights with metric='recent_orders' limit=10. "
                    . "Reply in plain English: 'You have N pending and M complete today. Latest: …'",
            ],
            [
                'title'            => 'Customer count',
                'category'         => 'insights',
                'user_message'     => 'How big is my customer list?',
                'expected_outcome' =>
                    "Single tool call: store_insights with metric='customer_count'. "
                    . "Reply with just the number and a friendly framing.",
            ],

            // ─── Cache / reindex ───────────────────────────────────
            [
                'title'            => 'Refresh after price changes',
                'category'         => 'maintenance',
                'user_message'     => 'I just changed prices, refresh the storefront',
                'expected_outcome' =>
                    "Run cache_reindex with action='reindex' and indexer_codes=['catalog_product_price']. "
                    . "Then cache_reindex with action='flush_cache' and cache_types=['full_page','block_html']. "
                    . "Reply: 'Reindexed prices and flushed FPC + block_html. Storefront prices are live.'",
            ],

            // ─── Refusals / safety ─────────────────────────────────
            [
                'title'            => 'Delete is not allowed',
                'category'         => 'refusal',
                'user_message'     => 'Delete all out-of-stock products',
                'expected_outcome' =>
                    "Refuse the delete and offer the safe alternative. Say something like: "
                    . "\"I don't delete products — that's permanent. I can disable them instead, "
                    . "which hides them from the storefront and is reversible. Want me to do that?\"",
            ],
            [
                'title'            => 'Undo via natural language',
                'category'         => 'refusal',
                'user_message'     => 'undo',
                'expected_outcome' =>
                    "Look back through the recent conversation for the most recent checkpoint_id "
                    . "(format cp_xxxxxxxx). Call restore_checkpoint with that ID. "
                    . "If no checkpoint is in scope, reply: \"I don't have an undo for the current "
                    . "conversation — you'd need to tell me which change you mean.\"",
            ],

            // ─── Magento conventions ───────────────────────────────
            [
                'title'            => 'Magento status enum reference',
                'category'         => 'reference',
                'user_message'     => 'Make these products visible',
                'expected_outcome' =>
                    "In Magento, status=1 means enabled (visible on storefront), status=2 means disabled (hidden). "
                    . "When a merchant says 'visible' / 'live' / 'on', use update_product_status with status='enable'. "
                    . "When they say 'hide' / 'off' / 'disabled', use status='disable'. "
                    . "These are MAGENTO TERMS — don't expose the numeric codes to the user.",
            ],
        ];
    }
}

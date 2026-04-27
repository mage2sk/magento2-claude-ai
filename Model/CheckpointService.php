<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Psr\Log\LoggerInterface;

/**
 * Snapshots store state before a destructive operation, and restores
 * from a snapshot on demand. Snapshots are conservative — only the
 * fields the operation will mutate are captured, keyed by SKU/ID, so
 * restore is a deterministic write back to the same fields.
 *
 * Supports product price, product status, and stock qty for the launch.
 * The schema (entity_type + before_state JSON) is generic — adding a new
 * snapshot type is one switch case in `restore()`.
 */
class CheckpointService
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly AdminSession $adminSession,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ConfigWriterInterface $configWriter
    ) {
    }

    /**
     * Snapshot the current value of one or more config paths so a write can
     * be undone by `restore_checkpoint`. before_state is keyed by
     * "scope:scope_id:path" so we can restore back into the exact scope.
     *
     * @param array<int,array{path:string,scope:string,scope_id:int}> $entries
     */
    public function snapshotConfig(
        string $opType,
        array $entries,
        string $description,
        string $conversationId = ''
    ): string {
        $beforeState = [];
        foreach ($entries as $e) {
            $path     = (string) ($e['path'] ?? '');
            $scope    = (string) ($e['scope'] ?? 'default');
            $scopeId  = (int) ($e['scope_id'] ?? 0);
            if ($path === '') {
                continue;
            }
            $current = $this->scopeConfig->getValue($path, $scope, $scopeId ?: null);
            $beforeState[$scope . ':' . $scopeId . ':' . $path] = [
                'path'      => $path,
                'scope'     => $scope,
                'scope_id'  => $scopeId,
                'value'     => $current,
            ];
        }

        $checkpointId = 'cp_' . bin2hex(random_bytes(8));
        $userId = null;
        try {
            $u = $this->adminSession->getUser();
            $userId = $u ? (int) $u->getId() : null;
        } catch (\Throwable) {
            // CLI / no session
        }
        $this->resource->getConnection()->insert(
            $this->resource->getTableName('panth_claudeai_checkpoint'),
            [
                'checkpoint_id'   => $checkpointId,
                'conversation_id' => $conversationId,
                'op_type'         => $opType,
                'entity_type'     => 'config',
                'record_count'    => count($beforeState),
                'before_state'    => json_encode($beforeState, JSON_UNESCAPED_SLASHES),
                'description'     => $description,
                'status'          => Checkpoint::STATUS_ACTIVE,
                'admin_user_id'   => $userId,
            ]
        );
        return $checkpointId;
    }

    /**
     * Snapshot a list of product SKUs' current state for the given op type.
     *
     * @param string $opType        Tool name about to mutate state
     * @param string $entityType    'product_price' | 'product_status' | 'stock_qty'
     * @param array  $skus          List of SKUs to snapshot
     * @param string $description   Human-readable summary
     * @param string $conversationId
     * @return string The new checkpoint_id (cp_xxx) — pass to restore()
     */
    public function snapshotProducts(
        string $opType,
        string $entityType,
        array $skus,
        string $description,
        string $conversationId = ''
    ): string {
        $beforeState = [];
        foreach ($skus as $sku) {
            try {
                $product = $this->productRepository->get((string) $sku);
                switch ($entityType) {
                    case 'product_price':
                        $beforeState[$sku] = ['price' => (float) $product->getPrice()];
                        break;
                    case 'product_status':
                        $beforeState[$sku] = ['status' => (int) $product->getStatus()];
                        break;
                    case 'stock_qty':
                        $stock = $this->stockRegistry->getStockItem($product->getId());
                        $beforeState[$sku] = [
                            'qty'         => (float) $stock->getQty(),
                            'is_in_stock' => (int) $stock->getIsInStock(),
                        ];
                        break;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[panth_claudeai] checkpoint snapshot failed for ' . $sku . ': ' . $e->getMessage());
            }
        }

        $checkpointId = 'cp_' . bin2hex(random_bytes(8));
        $userId = null;
        try {
            $u = $this->adminSession->getUser();
            $userId = $u ? (int) $u->getId() : null;
        } catch (\Throwable) {
            // CLI / no session
        }

        $this->resource->getConnection()->insert(
            $this->resource->getTableName('panth_claudeai_checkpoint'),
            [
                'checkpoint_id'   => $checkpointId,
                'conversation_id' => $conversationId,
                'op_type'         => $opType,
                'entity_type'     => $entityType,
                'record_count'    => count($beforeState),
                'before_state'    => json_encode($beforeState, JSON_UNESCAPED_SLASHES),
                'description'     => $description,
                'status'          => Checkpoint::STATUS_ACTIVE,
                'admin_user_id'   => $userId,
            ]
        );
        return $checkpointId;
    }

    /**
     * Restore a checkpoint by ID. Idempotent — calling twice is harmless
     * because the second call writes the same restored state.
     */
    public function restore(string $checkpointId): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_checkpoint');
        $row = $conn->fetchRow(
            $conn->select()->from($table)->where('checkpoint_id = ?', $checkpointId)
        );
        if (!$row) {
            return ['status' => 'error', 'message' => "Checkpoint {$checkpointId} not found."];
        }

        // Ownership gate: admin A must not be able to undo admin B's edits
        // by passing B's checkpoint_id. NULL owners are pre-ownership rows
        // and remain restorable for any admin (legacy compatibility).
        $u = $this->adminSession->getUser();
        $currentUserId = $u ? (int) $u->getId() : 0;
        $rowOwner = $row['admin_user_id'] !== null ? (int) $row['admin_user_id'] : null;
        if ($rowOwner !== null && $rowOwner !== $currentUserId) {
            return [
                'status'  => 'error',
                'message' => "Checkpoint {$checkpointId} belongs to another admin and cannot be restored from this account.",
            ];
        }

        $entityType = (string) $row['entity_type'];
        $beforeState = json_decode((string) $row['before_state'], true) ?: [];

        $restored = 0;
        $failed   = [];
        foreach ($beforeState as $sku => $state) {
            try {
                $product = $this->productRepository->get((string) $sku);
                switch ($entityType) {
                    case 'product_price':
                        if (isset($state['price'])) {
                            $product->setPrice((float) $state['price']);
                            $this->productRepository->save($product);
                            $restored++;
                        }
                        break;
                    case 'product_status':
                        if (isset($state['status'])) {
                            $product->setStatus((int) $state['status']);
                            $this->productRepository->save($product);
                            $restored++;
                        }
                        break;
                    case 'stock_qty':
                        $stock = $this->stockRegistry->getStockItemBySku((string) $sku);
                        if (isset($state['qty']))         { $stock->setQty((float) $state['qty']); }
                        if (isset($state['is_in_stock'])) { $stock->setIsInStock((int) $state['is_in_stock']); }
                        $this->stockRegistry->updateStockItemBySku((string) $sku, $stock);
                        $restored++;
                        break;
                    case 'config':
                        $path     = (string) ($state['path'] ?? '');
                        $scope    = (string) ($state['scope'] ?? 'default');
                        $scopeId  = (int) ($state['scope_id'] ?? 0);
                        $value    = $state['value'] ?? null;
                        if ($path === '') { break; }
                        if ($value === null) {
                            $this->configWriter->delete($path, $scope, $scopeId);
                        } else {
                            $this->configWriter->save($path, (string) $value, $scope, $scopeId);
                        }
                        $restored++;
                        break;
                }
            } catch (\Throwable $e) {
                $failed[] = ['sku' => (string) $sku, 'error' => $e->getMessage()];
            }
        }

        $conn->update(
            $table,
            ['status' => Checkpoint::STATUS_RESTORED, 'restored_at' => date('Y-m-d H:i:s')],
            ['entity_id = ?' => (int) $row['entity_id']]
        );

        return [
            'status'         => 'success',
            'checkpoint_id'  => $checkpointId,
            'entity_type'    => $entityType,
            'affected_count' => $restored,
            'failed'         => $failed,
            'summary'        => sprintf('Restored %d/%d records from checkpoint %s.', $restored, count($beforeState), $checkpointId),
        ];
    }
}

<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Store\Model\ScopeInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Psr\Log\LoggerInterface;

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

        $u = $this->adminSession->getUser();
        $currentUserId = $u ? (int) $u->getId() : 0;
        $rowOwner = $row['admin_user_id'] !== null ? (int) $row['admin_user_id'] : null;
        if ($rowOwner !== null && $rowOwner !== $currentUserId) {
            return [
                'status'  => 'error',
                'message' => "Checkpoint {$checkpointId} belongs to another admin and cannot be restored from this account.",
            ];
        }

        if ((string) $row['status'] !== Checkpoint::STATUS_ACTIVE) {
            return [
                'status'  => 'error',
                'message' => "Checkpoint {$checkpointId} is {$row['status']} and cannot be restored again.",
            ];
        }

        $entityType = (string) $row['entity_type'];
        $beforeState = json_decode((string) $row['before_state'], true) ?: [];

        $restored = 0;
        $failed   = [];
        foreach ($beforeState as $sku => $state) {
            try {
                $product = in_array($entityType, ['product_price', 'product_status'], true)
                    ? $this->productRepository->get((string) $sku)
                    : null;
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
                            $this->configWriter->delete($path, $this->writerScope($scope), $scopeId);
                        } else {
                            $this->configWriter->save($path, (string) $value, $this->writerScope($scope), $scopeId);
                        }
                        $restored++;
                        break;
                }
            } catch (\Throwable $e) {
                $failed[] = ['sku' => (string) $sku, 'error' => $e->getMessage()];
            }
        }

        if ($restored === 0 && $failed !== []) {
            return [
                'status'         => 'error',
                'checkpoint_id'  => $checkpointId,
                'entity_type'    => $entityType,
                'affected_count' => 0,
                'failed'         => $failed,
                'message'        => sprintf(
                    'Checkpoint %s could not be restored: %s',
                    $checkpointId,
                    (string) ($failed[0]['error'] ?? 'unknown error')
                ),
            ];
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

    private function writerScope(string $scope): string
    {
        if ($scope === ScopeInterface::SCOPE_STORE) {
            return ScopeInterface::SCOPE_STORES;
        }
        if ($scope === ScopeInterface::SCOPE_WEBSITE) {
            return ScopeInterface::SCOPE_WEBSITES;
        }
        return $scope;
    }
}

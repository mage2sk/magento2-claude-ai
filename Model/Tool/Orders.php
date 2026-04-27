<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Panth\ClaudeAi\Model\Config;

/**
 * Order search + lookup + limited writes (status change, comment).
 *
 * Reads:    search by status / email / created_after; get by increment_id.
 * Writes:   hold/unhold/cancel via OrderManagement; add admin comment.
 *
 * Cancel is irreversible, so it always requires confirmation regardless of
 * the global confirmation_threshold. Refunds/invoices live in Magento's own
 * UI — this tool intentionally doesn't touch payment surfaces.
 */
class Orders implements ToolInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly OrderStatusHistoryInterfaceFactory $historyFactory,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly Config $config
    ) {
    }

    public function name(): string { return 'orders'; }

    public function definition(): array
    {
        return [
            'name' => 'orders',
            'description' => 'Look up and manage orders. action="search" with status/email_contains/created_after; action="get" with increment_id; action="update_status" with increment_id and operation=hold|unhold|cancel; action="add_comment" with increment_id, comment, optional notify_customer. Cancel is irreversible — always confirm with the user first.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action'          => ['type' => 'string', 'enum' => ['search', 'get', 'update_status', 'add_comment']],
                    'increment_id'    => ['type' => 'string', 'description' => 'e.g. 000000123'],
                    'status'          => ['type' => 'string', 'description' => 'pending|processing|complete|canceled|closed|holded'],
                    'email_contains'  => ['type' => 'string'],
                    'created_after'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'limit'           => ['type' => 'integer', 'description' => 'Default 25, max 100.'],
                    'operation'       => ['type' => 'string', 'enum' => ['hold', 'unhold', 'cancel']],
                    'comment'         => ['type' => 'string'],
                    'notify_customer' => ['type' => 'boolean', 'description' => 'Default false. When true, the comment is emailed to the customer.'],
                    'confirm'         => ['type' => 'boolean', 'description' => 'Required true for cancel.'],
                ],
                'required' => ['action'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $action = (string) ($input['action'] ?? '');
            switch ($action) {
                case 'search':
                    return $this->search($input);
                case 'get':
                    return $this->get($input);
                case 'update_status':
                    return $this->updateStatus($input);
                case 'add_comment':
                    return $this->addComment($input);
            }
            return ['status' => 'error', 'message' => 'Unknown action: ' . $action];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function search(array $input): array
    {
        $limit = min(100, max(1, (int) ($input['limit'] ?? 25)));
        $cb = $this->criteriaBuilder;
        if (!empty($input['status'])) {
            $cb->addFilter('status', (string) $input['status']);
        }
        if (!empty($input['email_contains'])) {
            $cb->addFilter('customer_email',
                '%' . str_replace('%', '\\%', (string) $input['email_contains']) . '%', 'like');
        }
        if (!empty($input['created_after'])) {
            $cb->addFilter('created_at', (string) $input['created_after'], 'gteq');
        }
        $cb->addSortOrder('created_at', 'DESC');
        $list = $this->orderRepository->getList($cb->setPageSize($limit)->create());

        $rows = [];
        foreach ($list->getItems() as $o) {
            $rows[] = $this->shape($o);
        }
        return [
            'status'         => 'success',
            'affected_count' => count($rows),
            'total'          => (int) $list->getTotalCount(),
            'orders'         => $rows,
            'summary'        => sprintf('%d orders match (showing %d).', (int) $list->getTotalCount(), count($rows)),
        ];
    }

    private function get(array $input): array
    {
        $iid = trim((string) ($input['increment_id'] ?? ''));
        if ($iid === '') {
            return ['status' => 'error', 'message' => 'increment_id is required.'];
        }
        $order = $this->loadByIncrement($iid);
        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found: ' . $iid];
        }
        $items = [];
        foreach ($order->getAllVisibleItems() as $it) {
            $items[] = [
                'sku'       => $it->getSku(),
                'name'      => $it->getName(),
                'qty'       => (float) $it->getQtyOrdered(),
                'row_total' => (float) $it->getRowTotal(),
            ];
        }
        return [
            'status'  => 'success',
            'order'   => $this->shape($order) + ['items' => $items],
            'summary' => sprintf('Order %s — %s, %d items, total %.2f.',
                $iid, $order->getStatus(), count($items), (float) $order->getGrandTotal()),
        ];
    }

    private function updateStatus(array $input): array
    {
        $iid = trim((string) ($input['increment_id'] ?? ''));
        $op  = strtolower((string) ($input['operation'] ?? ''));
        if ($iid === '' || !in_array($op, ['hold', 'unhold', 'cancel'], true)) {
            return ['status' => 'error', 'message' => 'increment_id and operation (hold|unhold|cancel) are required.'];
        }

        $order = $this->loadByIncrement($iid);
        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found: ' . $iid];
        }

        // Cancel is irreversible — always require explicit confirm flag.
        if ($op === 'cancel' && !($input['confirm'] ?? false)) {
            return [
                'status'  => 'needs_confirmation',
                'message' => sprintf(
                    'Cancelling order %s is irreversible. Re-call with confirm=true to proceed.',
                    $iid
                ),
                'preview' => $this->shape($order),
            ];
        }

        if ($this->config->isDryRun()) {
            return [
                'status'  => 'dry_run',
                'message' => sprintf('[DRY RUN] Would %s order %s (current status: %s).', $op, $iid, $order->getStatus()),
                'preview' => $this->shape($order),
            ];
        }

        $orderId = (int) $order->getId();
        $ok = match ($op) {
            'hold'   => $this->orderManagement->hold($orderId),
            'unhold' => $this->orderManagement->unHold($orderId),
            'cancel' => $this->orderManagement->cancel($orderId),
        };
        if (!$ok) {
            return ['status' => 'error', 'message' => 'Order ' . $op . ' failed — order may not be eligible.'];
        }

        $fresh = $this->orderRepository->get($orderId);
        return [
            'status'         => 'success',
            'affected_count' => 1,
            'order'          => $this->shape($fresh),
            'summary'        => sprintf('Order %s %s. New status: %s.', $iid, $op . 'ed', $fresh->getStatus()),
        ];
    }

    private function addComment(array $input): array
    {
        $iid    = trim((string) ($input['increment_id'] ?? ''));
        $body   = trim((string) ($input['comment'] ?? ''));
        $notify = (bool) ($input['notify_customer'] ?? false);
        if ($iid === '' || $body === '') {
            return ['status' => 'error', 'message' => 'increment_id and comment are required.'];
        }
        $order = $this->loadByIncrement($iid);
        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found: ' . $iid];
        }

        if ($this->config->isDryRun()) {
            return [
                'status'  => 'dry_run',
                'message' => sprintf('[DRY RUN] Would add comment to order %s (notify=%s).', $iid, $notify ? 'yes' : 'no'),
            ];
        }

        $history = $this->historyFactory->create();
        $history->setParentId((int) $order->getId());
        $history->setComment($body);
        $history->setStatus($order->getStatus());
        $history->setIsCustomerNotified($notify ? 1 : 0);
        $history->setIsVisibleOnFront(0);
        $history->setEntityName('order');
        $this->historyRepository->save($history);

        return [
            'status'         => 'success',
            'affected_count' => 1,
            'summary'        => sprintf('Comment added to order %s%s.', $iid, $notify ? ' (customer notified)' : ''),
        ];
    }

    /**
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    private function loadByIncrement(string $incrementId)
    {
        $cb = $this->criteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();
        $items = $this->orderRepository->getList($cb)->getItems();
        return $items ? array_values($items)[0] : null;
    }

    private function shape($order): array
    {
        return [
            'order_id'        => (int) $order->getId(),
            'increment_id'    => (string) $order->getIncrementId(),
            'status'          => (string) $order->getStatus(),
            'state'           => (string) $order->getState(),
            'created_at'      => (string) $order->getCreatedAt(),
            'customer_email'  => (string) $order->getCustomerEmail(),
            'customer_name'   => trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname()),
            'grand_total'     => (float) $order->getGrandTotal(),
            'order_currency'  => (string) $order->getOrderCurrencyCode(),
            'store_id'        => (int) $order->getStoreId(),
        ];
    }
}

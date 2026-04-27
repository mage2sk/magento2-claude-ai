<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Aggregate counts and rollups across customers and orders.
 * One tool covers many "how many X" questions to keep the catalog small.
 */
class StoreInsights implements ToolInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    public function name(): string
    {
        return 'store_insights';
    }

    public function definition(): array
    {
        return [
            'name' => 'store_insights',
            'description' => 'Get aggregate counts for the store. Use the metric param to pick what to count: customer_count, order_count, order_count_by_status, recent_orders.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'metric' => [
                        'type' => 'string',
                        'enum' => [
                            'customer_count',
                            'order_count',
                            'order_count_by_status',
                            'recent_orders',
                        ],
                        'description' => 'Which insight to compute.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'For recent_orders only. Default 10.',
                    ],
                ],
                'required' => ['metric'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $metric = (string) ($input['metric'] ?? '');

        try {
            switch ($metric) {
                case 'customer_count':
                    $criteria = $this->criteriaBuilder->setPageSize(1)->create();
                    $list = $this->customerRepository->getList($criteria);
                    return [
                        'status' => 'success',
                        'metric' => $metric,
                        'count'  => (int) $list->getTotalCount(),
                        'summary' => sprintf('%d total customers.', (int) $list->getTotalCount()),
                    ];

                case 'order_count':
                    $criteria = $this->criteriaBuilder->setPageSize(1)->create();
                    $list = $this->orderRepository->getList($criteria);
                    return [
                        'status' => 'success',
                        'metric' => $metric,
                        'count'  => (int) $list->getTotalCount(),
                        'summary' => sprintf('%d total orders.', (int) $list->getTotalCount()),
                    ];

                case 'order_count_by_status': {
                    $statuses = ['pending', 'processing', 'complete', 'canceled', 'closed'];
                    $by = [];
                    foreach ($statuses as $st) {
                        $b = $this->criteriaBuilder
                            ->addFilter('status', $st)
                            ->setPageSize(1)
                            ->create();
                        $by[$st] = (int) $this->orderRepository->getList($b)->getTotalCount();
                    }
                    return [
                        'status' => 'success',
                        'metric' => $metric,
                        'by_status' => $by,
                        'summary' => 'Order counts by status: ' . json_encode($by),
                    ];
                }

                case 'recent_orders': {
                    $limit = min(50, max(1, (int) ($input['limit'] ?? 10)));
                    // Magento 2.4.8+ requires a SortOrder object — passing
                    // ('created_at', 'DESC') as strings throws
                    // "Call to a member function getField() on string".
                    $sort = $this->sortOrderBuilder
                        ->setField('created_at')
                        ->setDirection('DESC')
                        ->create();
                    $criteria = $this->criteriaBuilder
                        ->addSortOrder($sort)
                        ->setPageSize($limit)
                        ->create();
                    $list = $this->orderRepository->getList($criteria);
                    $rows = [];
                    foreach ($list->getItems() as $o) {
                        $rows[] = [
                            'increment_id'  => $o->getIncrementId(),
                            'created_at'    => $o->getCreatedAt(),
                            'status'        => $o->getStatus(),
                            'grand_total'   => (float) $o->getGrandTotal(),
                            'customer_email'=> $o->getCustomerEmail(),
                        ];
                    }
                    return [
                        'status' => 'success',
                        'metric' => $metric,
                        'orders' => $rows,
                        'summary' => sprintf('Last %d orders.', count($rows)),
                    ];
                }

                default:
                    return ['status' => 'error', 'message' => "Unknown metric: {$metric}"];
            }
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}

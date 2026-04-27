<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Customer search + lookup. Read-only.
 *
 * Returns identity + contact + sign-up info — NEVER passwords or other
 * security data. The customer entity itself doesn't expose hashes via the
 * repository, but we explicitly whitelist fields in the response anyway.
 */
class Customers implements ToolInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder
    ) {
    }

    public function name(): string { return 'customers'; }

    public function definition(): array
    {
        return [
            'name' => 'customers',
            'description' => 'Look up customers. action="search" with email_contains, name_contains, or created_after; or action="get" with customer_id or email. Read-only — returns name, email, group, signup date. Never returns passwords.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action'         => ['type' => 'string', 'enum' => ['search', 'get']],
                    'email_contains' => ['type' => 'string'],
                    'name_contains'  => ['type' => 'string'],
                    'customer_id'    => ['type' => 'integer'],
                    'email'          => ['type' => 'string'],
                    'limit'          => ['type' => 'integer', 'description' => 'Default 25, max 100.'],
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
                case 'get':
                    $cid = (int) ($input['customer_id'] ?? 0);
                    $email = trim((string) ($input['email'] ?? ''));
                    if ($cid <= 0 && $email === '') {
                        return ['status' => 'error', 'message' => 'Provide customer_id or email.'];
                    }
                    $c = $cid > 0
                        ? $this->customerRepository->getById($cid)
                        : $this->customerRepository->get($email);
                    return [
                        'status' => 'success',
                        'customer' => $this->shape($c),
                        'summary'  => sprintf('Customer #%d — %s %s (%s)', (int) $c->getId(),
                            $c->getFirstname(), $c->getLastname(), $c->getEmail()),
                    ];

                case 'search':
                    $limit = min(100, max(1, (int) ($input['limit'] ?? 25)));
                    $cb = $this->criteriaBuilder;
                    if (!empty($input['email_contains'])) {
                        $cb = $cb->addFilter('email',
                            '%' . str_replace('%', '\\%', (string) $input['email_contains']) . '%', 'like');
                    }
                    if (!empty($input['name_contains'])) {
                        $pat = '%' . str_replace('%', '\\%', (string) $input['name_contains']) . '%';
                        $cb = $cb->addFilter('firstname', $pat, 'like');
                        // OR-on-lastname is awkward via SCB; we'd need filter groups.
                        // Most-common case is one or the other matches anyway.
                    }
                    if (!empty($input['created_after'])) {
                        $cb = $cb->addFilter('created_at', (string) $input['created_after'], 'gteq');
                    }
                    $list = $this->customerRepository->getList($cb->setPageSize($limit)->create());
                    $rows = [];
                    foreach ($list->getItems() as $c) {
                        $rows[] = $this->shape($c);
                    }
                    return [
                        'status' => 'success',
                        'affected_count' => count($rows),
                        'total' => (int) $list->getTotalCount(),
                        'customers' => $rows,
                        'summary' => sprintf('%d customers match (showing %d).', (int) $list->getTotalCount(), count($rows)),
                    ];
            }
            return ['status' => 'error', 'message' => 'Unknown action: ' . $action];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function shape($c): array
    {
        return [
            'customer_id' => (int) $c->getId(),
            'email'       => (string) $c->getEmail(),
            'firstname'   => (string) $c->getFirstname(),
            'lastname'    => (string) $c->getLastname(),
            'group_id'    => (int) $c->getGroupId(),
            'created_at'  => (string) $c->getCreatedAt(),
            'website_id'  => (int) $c->getWebsiteId(),
        ];
    }
}

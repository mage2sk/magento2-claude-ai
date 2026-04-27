<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Read-only context tool. Returns store-level info (name, contact, currency,
 * country, base URL, Magento version) for either the current store, a named
 * store, or every store on the install.
 *
 * Action surface:
 *   - get   (default): info for one store. Optional `store_code` to target
 *           a specific store view; defaults to the current admin store.
 *   - list_stores:    every store with name + base URL + code, so questions
 *           like "what's the URL of the Luma view?" don't fall back to
 *           "I can't" — Claude reads them directly from here.
 *
 * READS only. URL writes (web/secure/base_url, web/unsecure/base_url) are
 * intentionally NOT in update_config's allow-list — but READS are fine and
 * happen here.
 */
class StoreInfo implements ToolInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductMetadataInterface $productMetadata
    ) {
    }

    public function name(): string { return 'store_info'; }

    public function definition(): array
    {
        return [
            'name' => 'store_info',
            'description' =>
                'Read-only store info. action="get" returns name, contact, currency, country, base URL, version (current store, or a specific one if you pass store_code). action="list_stores" returns every store on the install with its name, code, and base URL — use this when the merchant asks for the URL of a named store view (e.g. "give me the luma store URL"). This tool can read URLs; only WRITING URL config is blocked.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action'     => ['type' => 'string', 'enum' => ['get', 'list_stores']],
                    'store_code' => ['type' => 'string', 'description' => 'For action=get only — store view code to target (e.g. "default", "luma"). Omit for current.'],
                ],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $action = (string) ($input['action'] ?? 'get');

            if ($action === 'list_stores') {
                $rows = [];
                foreach ($this->storeManager->getStores() as $store) {
                    $rows[] = [
                        'store_id'   => (int) $store->getId(),
                        'store_code' => (string) $store->getCode(),
                        'store_name' => (string) $store->getName(),
                        'website_id' => (int) $store->getWebsiteId(),
                        'is_active'  => (bool) $store->getIsActive(),
                        'base_url'   => (string) $store->getBaseUrl(),
                    ];
                }
                $names = array_map(static fn(array $r) => $r['store_code'], $rows);
                return [
                    'status'         => 'success',
                    'affected_count' => count($rows),
                    'stores'         => $rows,
                    'summary'        => sprintf('%d stores: %s', count($rows), implode(', ', $names)),
                ];
            }

            // action=get (default)
            $code = trim((string) ($input['store_code'] ?? ''));
            $store = $code !== ''
                ? $this->storeManager->getStore($code)
                : $this->storeManager->getStore();

            $storeId = (int) $store->getId();
            $info = [
                'store_id'        => $storeId,
                'store_code'      => (string) $store->getCode(),
                'store_name'      => (string) $store->getName(),
                'phone'           => (string) $this->scopeConfig->getValue('general/store_information/phone', ScopeInterface::SCOPE_STORE, $storeId),
                'contact_email'   => (string) $this->scopeConfig->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE, $storeId),
                'country'         => (string) $this->scopeConfig->getValue('general/store_information/country_id', ScopeInterface::SCOPE_STORE, $storeId),
                'timezone'        => (string) $this->scopeConfig->getValue('general/locale/timezone', ScopeInterface::SCOPE_STORE, $storeId),
                'currency'        => $store->getCurrentCurrencyCode(),
                'base_url'        => $store->getBaseUrl(),
                'secure_base_url' => (string) $this->scopeConfig->getValue('web/secure/base_url', ScopeInterface::SCOPE_STORE, $storeId),
                'magento_version' => $this->productMetadata->getVersion(),
                'magento_edition' => $this->productMetadata->getEdition(),
                'contact_enabled' => (bool) $this->scopeConfig->getValue('contact/contact/enabled', ScopeInterface::SCOPE_STORE, $storeId),
            ];
            return [
                'status'  => 'success',
                'info'    => $info,
                'summary' => sprintf(
                    '%s (%s) — base URL %s, %s, %s currency, on Magento %s.',
                    $info['store_name'] ?: $info['store_code'],
                    $info['store_code'],
                    $info['base_url'],
                    $info['country'] ?: '?',
                    $info['currency'],
                    $info['magento_version']
                ),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}

<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Read-only context tool. Lets Claude (especially the storefront shop
 * assistant) answer "what's your shipping policy", "where are you
 * located", "what are your business hours" without making things up.
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
            'description' => 'Read store-level info: name, contact email, currency, time zone, country, base URL, contact-page URL, Magento version. Use this for shopper questions about who you are, where you are, what currency you accept, etc.',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $store = $this->storeManager->getStore();
            $info = [
                'store_name'        => (string) $this->scopeConfig->getValue('general/store_information/name'),
                'phone'             => (string) $this->scopeConfig->getValue('general/store_information/phone'),
                'contact_email'     => (string) $this->scopeConfig->getValue('trans_email/ident_general/email'),
                'country'           => (string) $this->scopeConfig->getValue('general/store_information/country_id'),
                'timezone'          => (string) $this->scopeConfig->getValue('general/locale/timezone'),
                'currency'          => $store->getCurrentCurrencyCode(),
                'base_url'          => $store->getBaseUrl(),
                'magento_version'   => $this->productMetadata->getVersion(),
                'magento_edition'   => $this->productMetadata->getEdition(),
                'contact_enabled'   => (bool) $this->scopeConfig->getValue('contact/contact/enabled'),
            ];
            return [
                'status'  => 'success',
                'info'    => $info,
                'summary' => sprintf('%s — %s, %s currency, on Magento %s.', $info['store_name'] ?: 'Store', $info['country'] ?: '?', $info['currency'], $info['magento_version']),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}

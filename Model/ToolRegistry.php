<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\AuthorizationInterface;
use Panth\ClaudeAi\Model\Tool\ToolInterface;

class ToolRegistry
{
    private const TOOL_ACL = [
        'get_products'           => 'Magento_Catalog::products',
        'manage_products'        => 'Magento_Catalog::products',
        'update_product_price'   => 'Magento_Catalog::products',
        'update_product_status'  => 'Magento_Catalog::products',
        'update_inventory'       => 'Magento_Catalog::products',
        'get_low_stock_products' => 'Magento_Catalog::products',
        'manage_categories'      => 'Magento_Catalog::categories',
        'customers'              => 'Magento_Customer::manage',
        'orders'                 => 'Magento_Sales::sales_order',
        'store_insights'         => 'Magento_Sales::sales_order',
        'manage_cms_pages'       => 'Magento_Cms::page',
        'manage_cms_blocks'      => 'Magento_Cms::block',
        'store_info'             => 'Magento_Config::config',
        'update_config'          => 'Magento_Config::config',
        'set_store_logo'         => 'Magento_Config::config',
        'cache_reindex'          => 'Magento_Backend::cache',
        'database_query'         => 'Magento_Backend::all',
        'restore_checkpoint'     => 'Panth_ClaudeAi::ai_checkpoint',
    ];

    private array $tools = [];

    public function __construct(
        array $tools = [],
        private readonly ?Config $config = null,
        private readonly ?AuthorizationInterface $authorization = null
    ) {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $this->tools[$tool->name()] = $tool;
            }
        }
        ksort($this->tools);
    }

    public function enabled(): array
    {
        if ($this->config === null) {
            return $this->tools;
        }
        $out = [];
        foreach ($this->tools as $name => $tool) {
            if ($this->config->isToolEnabled($name)) {
                $out[$name] = $tool;
            }
        }
        return $out;
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function has(string $name): bool
    {
        return isset($this->available()[$name]);
    }

    public function get(string $name): ?ToolInterface
    {
        $available = $this->available();
        return $available[$name] ?? null;
    }

    public function definitions(): array
    {
        $defs = [];
        foreach ($this->available() as $tool) {
            $defs[] = $tool->definition();
        }
        return $defs;
    }

    public function isAllowed(string $name): bool
    {
        if ($this->authorization === null) {
            return true;
        }
        $resource = self::TOOL_ACL[$name] ?? null;
        if ($resource === null) {
            return true;
        }
        try {
            return $this->authorization->isAllowed($resource);
        } catch (\Throwable) {
            return false;
        }
    }

    private function available(): array
    {
        $out = [];
        foreach ($this->enabled() as $name => $tool) {
            if ($this->isAllowed($name)) {
                $out[$name] = $tool;
            }
        }
        return $out;
    }
}

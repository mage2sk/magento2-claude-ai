<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Panth\ClaudeAi\Model\Tool\GetModules;
use PHPUnit\Framework\TestCase;

class GetModulesTest extends TestCase
{
    private function tool(): GetModules
    {
        $full = $this->createStub(FullModuleList::class);
        $full->method('getAll')->willReturn([
            'Magento_Catalog' => [],
            'Magento_Sales' => [],
            'Hyva_Theme' => [],
            'Panth_ClaudeAi' => [],
            'Panth_Seo' => [],
            'Vendor_Thing' => [],
        ]);
        $enabled = $this->createStub(ModuleListInterface::class);
        $enabled->method('getNames')->willReturn(['Magento_Catalog', 'Hyva_Theme', 'Panth_ClaudeAi', 'Vendor_Thing']);

        return new GetModules($full, $enabled);
    }

    public function testDefinition(): void
    {
        $this->assertSame('get_modules', $this->tool()->name());
        $this->assertSame('get_modules', $this->tool()->definition()['name']);
    }

    public function testAllModulesAreCounted(): void
    {
        $result = $this->tool()->execute([]);

        $this->assertSame('success', $result['status']);
        $this->assertSame(6, $result['total']);
        $this->assertSame(3, $result['magento_or_hyva']);
        $this->assertSame(3, $result['third_party']);
        $this->assertSame(['name' => 'Magento_Sales', 'enabled' => false], $result['modules'][1]);
        $this->assertStringStartsWith('6 modules match (3 Magento/Hyv', $result['summary']);
        $this->assertStringEndsWith(', 3 third-party).', $result['summary']);
    }

    public function testVendorFilterIsCaseInsensitivePrefix(): void
    {
        $result = $this->tool()->execute(['vendor' => 'panth']);
        $this->assertSame(['Panth_ClaudeAi', 'Panth_Seo'], array_column($result['modules'], 'name'));
    }

    public function testEnabledOnlyAndNameFilter(): void
    {
        $result = $this->tool()->execute(['enabled_only' => true, 'name_contains' => 'a']);
        $this->assertSame(['Magento_Catalog', 'Hyva_Theme', 'Panth_ClaudeAi'], array_column($result['modules'], 'name'));

        $result = $this->tool()->execute(['enabled_only' => true, 'name_contains' => 'seo']);
        $this->assertSame(0, $result['total']);
    }

    public function testFailureIsReturnedAsError(): void
    {
        $full = $this->createStub(FullModuleList::class);
        $full->method('getAll')->willThrowException(new \RuntimeException('broken config'));
        $tool = new GetModules($full, $this->createStub(ModuleListInterface::class));

        $this->assertSame(['status' => 'error', 'message' => 'broken config'], $tool->execute([]));
    }
}

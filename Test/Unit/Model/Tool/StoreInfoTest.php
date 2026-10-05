<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\Tool\StoreInfo;
use PHPUnit\Framework\TestCase;

class StoreInfoTest extends TestCase
{
    private function store(int $id, string $code, string $name): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getName')->willReturn($name);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getIsActive')->willReturn('1');
        $store->method('getBaseUrl')->willReturn('https://' . $code . '.test/');
        $store->method('getCurrentCurrencyCode')->willReturn('EUR');
        return $store;
    }

    private function tool(StoreManagerInterface $storeManager, array $config = []): StoreInfo
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType, $scopeId) use ($config) {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scopeType);
                return $config[$scopeId][$path] ?? null;
            }
        );
        $meta = $this->createStub(ProductMetadataInterface::class);
        $meta->method('getVersion')->willReturn('2.4.8');
        $meta->method('getEdition')->willReturn('Community');

        return new StoreInfo($scope, $storeManager, $meta);
    }

    public function testListStores(): void
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn([$this->store(1, 'default', 'Main'), $this->store(2, 'luma', 'Luma')]);

        $result = $this->tool($manager)->execute(['action' => 'list_stores']);

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['affected_count']);
        $this->assertSame([
            'store_id' => 2,
            'store_code' => 'luma',
            'store_name' => 'Luma',
            'website_id' => 1,
            'is_active' => true,
            'base_url' => 'https://luma.test/',
        ], $result['stores'][1]);
        $this->assertSame('2 stores: default, luma', $result['summary']);
    }

    public function testGetSpecificStoreReadsItsConfig(): void
    {
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->expects($this->once())->method('getStore')->with('luma')->willReturn($this->store(2, 'luma', 'Luma'));

        $result = $this->tool($manager, [2 => [
            'general/store_information/phone' => '555',
            'general/store_information/country_id' => 'DE',
            'contact/contact/enabled' => '1',
        ]])->execute(['action' => 'get', 'store_code' => ' luma ']);

        $info = $result['info'];
        $this->assertSame(2, $info['store_id']);
        $this->assertSame('555', $info['phone']);
        $this->assertSame('DE', $info['country']);
        $this->assertSame('', $info['contact_email']);
        $this->assertSame('EUR', $info['currency']);
        $this->assertSame('2.4.8', $info['magento_version']);
        $this->assertSame('Community', $info['magento_edition']);
        $this->assertTrue($info['contact_enabled']);
        $this->assertSame('Luma (luma) - base URL https://luma.test/, DE, EUR currency, on Magento 2.4.8.', $result['summary']);
    }

    public function testDefaultActionUsesCurrentStoreAndFallbacks(): void
    {
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->expects($this->once())->method('getStore')->with()->willReturn($this->store(1, 'default', ''));

        $result = $this->tool($manager)->execute([]);

        $this->assertSame('default (default) - base URL https://default.test/, ?, EUR currency, on Magento 2.4.8.', $result['summary']);
        $this->assertFalse($result['info']['contact_enabled']);
    }

    public function testUnknownStoreIsReturnedAsError(): void
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStore')->willThrowException(new \RuntimeException('The store that was requested wasn\'t found.'));

        $result = $this->tool($manager)->execute(['store_code' => 'nope']);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('wasn\'t found', $result['message']);
    }
}

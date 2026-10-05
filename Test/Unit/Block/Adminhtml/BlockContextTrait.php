<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;

trait BlockContextTrait
{
    private ?ObjectManagerInterface $previousObjectManager = null;

    protected function installObjectManager(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn(string $class) => $this->createStub($class));
        ObjectManager::setInstance($objectManager);
    }

    protected function restoreObjectManager(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $property->setValue(null, $this->previousObjectManager);
    }

    protected function blockContext(
        array $params = [],
        ?AuthorizationInterface $authorization = null,
        array $config = [],
        string $formKey = 'fk'
    ): Context {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            fn($route = '', $routeParams = []) => 'https://admin.test/' . $route . ($routeParams ? '?' . http_build_query($routeParams) : '')
        );

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn($key, $default = null) => $params[$key] ?? $default);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn($path) => $config[$path] ?? null);

        $formKeyModel = $this->createStub(FormKey::class);
        $formKeyModel->method('getFormKey')->willReturn($formKey);

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($url);
        $context->method('getRequest')->willReturn($request);
        $context->method('getScopeConfig')->willReturn($scopeConfig);
        $context->method('getFormKey')->willReturn($formKeyModel);
        $context->method('getAuthorization')->willReturn($authorization ?? $this->createStub(AuthorizationInterface::class));
        return $context;
    }
}

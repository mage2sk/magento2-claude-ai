<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Session;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Message\ManagerInterface;
use Magento\User\Model\User;

trait ContextBuilderTrait
{
    protected array $messages = [];
    protected ?array $redirect = null;
    protected string $contextFormKey = 'fk';

    protected function buildContext(
        HttpRequest $request,
        ?int $userId = 7,
        string $formKey = 'fk',
        ?ManagerInterface $messageManager = null
    ): Context {
        $this->contextFormKey = $formKey;
        $user = null;
        if ($userId !== null) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($userId);
        }
        $auth = $this->createStub(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $session = $this->createStub(Session::class);
        $session->method('__call')->willReturnCallback(fn(string $m) => $m === 'getFormKey' ? $formKey : null);

        $customMessageManager = $messageManager;
        $messageManager = $this->createStub(ManagerInterface::class);
        foreach (['addErrorMessage' => 'error', 'addSuccessMessage' => 'success'] as $method => $type) {
            $messageManager->method($method)->willReturnCallback(function ($message) use ($type, $messageManager) {
                $this->messages[] = [$type, (string) $message];
                return $messageManager;
            });
        }

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path, array $params = []) use ($redirect) {
            $this->redirect = [$path, $params];
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getActionFlag')->willReturn($this->createStub(\Magento\Framework\App\ActionFlag::class));
        $context->method('getResponse')->willReturn($this->createStub(\Magento\Framework\App\Response\Http::class));
        $context->method('getAuth')->willReturn($auth);
        $context->method('getSession')->willReturn($session);
        $context->method('getMessageManager')->willReturn($customMessageManager ?? $messageManager);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        return $context;
    }

    protected function formKeyStub(): FormKey
    {
        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn($this->contextFormKey);
        return $formKey;
    }

    protected function request(array $params = [], string $content = '', array $post = []): HttpRequest
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getParam')->willReturnCallback(fn($key, $default = null) => $params[$key] ?? $default);
        $request->method('getParams')->willReturn($params);
        $request->method('getContent')->willReturn($content);
        $request->method('getPostValue')->willReturn($post);
        return $request;
    }
}

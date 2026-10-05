<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Panth\ClaudeAi\Model\AttachmentStorage;

class File extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_chat';

    public function __construct(
        Context $context,
        private readonly RawFactory $rawFactory,
        private readonly AttachmentStorage $attachmentStorage
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Cache-Control', 'private, no-store', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);

        $userId = 0;
        try {
            $user = $this->_auth->getUser();
            $userId = $user ? (int) $user->getId() : 0;
        } catch (\Throwable) {
            $userId = 0;
        }

        $row = $this->attachmentStorage->getOwned((int) $this->getRequest()->getParam('id'), $userId);
        $bytes = $row !== null ? $this->attachmentStorage->read((string) $row['stored_path']) : null;
        if ($row === null || $bytes === null) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            return $result->setContents('File not found.');
        }

        $mime = (string) $row['mime_type'];
        $inline = $this->attachmentStorage->isInlineMime($mime);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['original_name']) ?: 'attachment';

        $result->setHeader('Content-Type', $inline ? $mime : 'application/octet-stream', true);
        $result->setHeader(
            'Content-Disposition',
            ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            true
        );
        $result->setHeader('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; sandbox", true);

        return $result->setContents($bytes);
    }
}

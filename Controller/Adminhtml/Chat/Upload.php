<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\ClaudeAi\Model\AttachmentStorage;
use Panth\ClaudeAi\Model\Config;
use Psr\Log\LoggerInterface;

class Upload extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_upload';

    private const MAX_FILE_SIZE  = 20971520;

    private const ALLOWED_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'csv', 'txt', 'json', 'xml', 'log', 'md',
        'zip', 'tar', 'gz',
    ];
    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/csv', 'text/plain', 'text/x-log', 'text/markdown',
        'application/json', 'text/json',
        'application/xml', 'text/xml',

        'application/zip', 'application/x-zip-compressed',
        'application/x-tar', 'application/gzip',
    ];

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly FormKey $formKey
    ) {
        parent::__construct($context);
    }

    public function _processUrlKeys()
    {
        $request = $this->getRequest();
        if ($this->validateForCsrf($request)) {
            return true;
        }
        $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
        $this->_actionFlag->set('', self::FLAG_NO_POST_DISPATCH, true);
        $result = $this->createCsrfValidationException($request)->getReplaceResult();
        $response = $this->getResponse();
        if ($result instanceof ResultInterface && $response instanceof HttpResponseInterface) {
            $result->renderResult($response);
        }
        return false;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $r = $this->jsonFactory->create();
        $r->setData(['error' => 'Your session expired - please refresh and try again.']);
        return new InvalidRequestException($r);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $key = $request->getParam('form_key');
        if ($key) {
            return hash_equals((string) $this->formKey->getFormKey(), (string) $key);
        }
        return false;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            if (!$this->config->isEnabled()) {
                return $result->setData(['error' => 'Claude AI is disabled.']);
            }
            $files = $this->getRequest()->getFiles('file');
            if (!$files || empty($files['tmp_name'])) {
                return $result->setData(['error' => 'No file was uploaded.']);
            }

            $original = basename((string) $files['name']);
            $size     = (int) $files['size'];
            $tmp      = (string) $files['tmp_name'];

            $mime     = (string) (function_exists('mime_content_type') ? @mime_content_type($tmp) : '');
            $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $errorCode = (int) ($files['error'] ?? UPLOAD_ERR_OK);

            if ($errorCode !== UPLOAD_ERR_OK) {
                $hint = match ($errorCode) {
                    UPLOAD_ERR_INI_SIZE   => 'larger than upload_max_filesize',
                    UPLOAD_ERR_FORM_SIZE  => 'larger than the form MAX_FILE_SIZE',
                    UPLOAD_ERR_PARTIAL    => 'upload was interrupted',
                    UPLOAD_ERR_NO_FILE    => 'no file present',
                    UPLOAD_ERR_NO_TMP_DIR => 'server has no tmp dir',
                    UPLOAD_ERR_CANT_WRITE => 'server could not write the upload',
                    UPLOAD_ERR_EXTENSION  => 'a PHP extension blocked it',
                    default               => 'unknown error code ' . $errorCode,
                };
                return $result->setData(['error' => 'Upload failed: ' . $hint . '.']);
            }

            if ($size > self::MAX_FILE_SIZE) {
                return $result->setData(['error' => 'That file is too big. Maximum 20 MB.']);
            }

            $imgInfo = @getimagesize($tmp);
            $isImageByContent = is_array($imgInfo) && isset($imgInfo[2]);
            $imageExtMap = [
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG  => 'png',
                IMAGETYPE_GIF  => 'gif',
                IMAGETYPE_WEBP => 'webp',
            ];
            if ($isImageByContent && isset($imageExtMap[$imgInfo[2]])) {
                $ext  = $imageExtMap[$imgInfo[2]];
                $mime = (string) image_type_to_mime_type($imgInfo[2]);
            } else {
                if ($ext === '' || !in_array($ext, self::ALLOWED_EXT, true)) {
                    $detected = $ext === '' ? '(no extension)' : '.' . $ext;
                    return $result->setData([
                        'error' => sprintf(
                            'That file type isn\'t supported (detected %s, MIME %s, %d bytes). Allowed: images, PDF, Word, Excel, CSV, text, archives.',
                            $detected,
                            $mime !== '' ? $mime : 'unknown',
                            $size
                        ),
                    ]);
                }
                if ($mime !== '' && !in_array($mime, self::ALLOWED_MIME, true)) {
                    return $result->setData([
                        'error' => sprintf(
                            'The file content (MIME %s) doesn\'t match its extension (.%s).',
                            $mime,
                            $ext
                        ),
                    ]);
                }
            }

            $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($original, PATHINFO_FILENAME));
            $safe = ltrim(str_replace('..', '_', mb_substr($safe, 0, 80)), '.');
            if ($safe === '') {
                $safe = 'file';
            }
            $stored = $safe . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

            $storage = $this->attachmentStorage->getDirectory();
            $dir = AttachmentStorage::UPLOAD_DIR;
            $storage->create($dir);

            $relPath = $dir . '/' . $stored;
            $absPath = $storage->getAbsolutePath($relPath);
            if (!move_uploaded_file($tmp, $absPath)) {
                return $result->setData(['error' => 'Could not save the file. Please try again.']);
            }

            $conversationId = (string) $this->getRequest()->getParam('conversation_id', '');
            $userId = null;
            try {
                $u = $this->_auth->getUser();
                $userId = $u ? (int) $u->getId() : null;
            } catch (\Throwable) {
            }

            $connection = $this->resource->getConnection();
            $connection->insert(
                $this->resource->getTableName('panth_claudeai_attachment'),
                [
                    'conversation_id' => $conversationId,
                    'original_name'   => $original,
                    'stored_path'     => $relPath,
                    'mime_type'       => $mime,
                    'size_bytes'      => $size,
                    'admin_user_id'   => $userId,
                ]
            );
            $attachmentId = (int) $connection->lastInsertId();

            $url = $this->getUrl('claudeai/chat/file', ['id' => $attachmentId]);

            $isImage = str_starts_with($mime, 'image/');
            $row = [
                'original_name' => $original,
                'stored_path'   => $relPath,
                'mime_type'     => $mime,
                'size_bytes'    => $size,
            ];

            return $result->setData([
                'success'        => true,
                'id'             => $attachmentId,
                'name'           => $original,
                'path'           => $relPath,
                'url'            => $url,
                'preview_url'    => $isImage ? $url : '',
                'mime'           => $mime,
                'size'           => $size,
                'is_image'       => $isImage,
                'is_pdf'         => $mime === 'application/pdf',
                'is_text'        => in_array($ext, ['txt', 'csv', 'json', 'xml', 'log', 'md'], true),
                'claude_can_see' => $this->attachmentStorage->isIngestible($relPath),
                'path_note'      => $this->attachmentStorage->getPathNote($row),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[panth_claudeai] upload error: ' . $e->getMessage());
            return $result->setData(['error' => 'Upload failed. Please try a different file.']);
        }
    }
}

<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Multipart upload endpoint for chat attachments.
 *
 * Defense in depth: extension allowlist, MIME allowlist, magic-byte image
 * verification, max size cap, sanitised filename, .htaccess dropped into
 * the upload dir to disable PHP execution.
 *
 * Returns JSON with the stored path/url + a row in panth_claudeai_attachment.
 */
class Upload extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_upload';

    private const UPLOAD_DIR     = 'panth/claudeai';
    private const MAX_FILE_SIZE  = 20971520; // 20 MB (admin scope)

    // Admins can upload a wider mix than shoppers.
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
        // Archives — stored only; Claude can't see inside compressed binaries
        // but admins often want a parking spot for an uploaded artefact.
        'application/zip', 'application/x-zip-compressed',
        'application/x-tar', 'application/gzip',
    ];

    /** Extensions Claude can DIRECTLY ingest as image/document content blocks. */
    private const CLAUDE_INGESTIBLE_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',  // images
        'pdf',                                 // documents
        'txt', 'csv', 'json', 'xml', 'log', 'md', // plain text — sent as inline text
    ];

    private const HTACCESS_GUARD = <<<HTA
# Block PHP execution in user-upload directory.
<IfModule mod_php.c>php_flag engine off</IfModule>
<IfModule mod_php7.c>php_flag engine off</IfModule>
<IfModule mod_php8.c>php_flag engine off</IfModule>
<FilesMatch "\\.(php|phtml|php3|php4|php5|php7|phar|pl|py|jsp|asp|sh|cgi)\$">
    Require all denied
</FilesMatch>
AddHandler cgi-script .php .phtml .phar
Options -ExecCGI
HTA;

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function _processUrlKeys() { return true; }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $r = $this->jsonFactory->create();
        $r->setData(['error' => 'Your session expired — please refresh and try again.']);
        return new InvalidRequestException($r);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $key = $request->getParam('form_key');
        if ($key) {
            $sessionKey = $this->_getSession()->getFormKey();
            return hash_equals((string) $sessionKey, (string) $key);
        }
        return false;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $files = $this->getRequest()->getFiles('file');
            if (!$files || empty($files['tmp_name'])) {
                return $result->setData(['error' => 'No file was uploaded.']);
            }

            $original = basename((string) $files['name']);
            $size     = (int) $files['size'];
            $tmp      = (string) $files['tmp_name'];
            // mime_content_type can return false / weird values inside docker.
            // getimagesize() is the authoritative check for images.
            $mime     = (string) (function_exists('mime_content_type') ? @mime_content_type($tmp) : '');
            $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $errorCode = (int) ($files['error'] ?? UPLOAD_ERR_OK);

            // Surface PHP upload-error codes plainly — most "file type not
            // supported" reports actually trace back to upload_max_filesize
            // or post_max_size truncating the file before validation.
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

            // ---- Image fast-path: trust getimagesize(), not the filename ----
            // This works even when the file has no extension or the browser
            // sends application/octet-stream for the MIME (which is what
            // tripped the previous version on certain PNGs).
            $imgInfo = @getimagesize($tmp);
            $isImageByContent = is_array($imgInfo) && isset($imgInfo[2]);
            $imageExtMap = [
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG  => 'png',
                IMAGETYPE_GIF  => 'gif',
                IMAGETYPE_WEBP => 'webp',
            ];
            if ($isImageByContent && isset($imageExtMap[$imgInfo[2]])) {
                // Authoritative: this IS an image. Replace any sketchy
                // ext/mime values with the ones derived from real bytes.
                $ext  = $imageExtMap[$imgInfo[2]];
                $mime = (string) image_type_to_mime_type($imgInfo[2]);
            } else {
                // Non-image: fall back to ext + mime allow-list.
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
            $safe = mb_substr($safe, 0, 80);
            $stored = $safe . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

            $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $dir = self::UPLOAD_DIR;
            $media->create($dir);
            // Drop .htaccess via the lower-level driver (writeFile rejects .htaccess)
            try {
                $abs = $media->getAbsolutePath($dir . '/.htaccess');
                $driver = $media->getDriver();
                if (!$driver->isExists($abs)) {
                    $driver->filePutContents($abs, self::HTACCESS_GUARD);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[panth_claudeai] could not write upload .htaccess: ' . $e->getMessage());
            }
            $relPath = $dir . '/' . $stored;
            $absPath = $media->getAbsolutePath($relPath);
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

            $this->resource->getConnection()->insert(
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

            $url = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $relPath;

            // Build the response. For Claude-ingestible types we include
            // base64 data so the chat JS can send it as an image/document
            // content block. For archives + opaque binaries we just return
            // the URL — the file is stored, but Claude is told it can only
            // SEE the filename, not the contents.
            $isImage      = str_starts_with($mime, 'image/');
            $isPdf        = $mime === 'application/pdf';
            $isText       = in_array($ext, ['txt', 'csv', 'json', 'xml', 'log', 'md'], true);
            $isIngestible = in_array($ext, self::CLAUDE_INGESTIBLE_EXT, true);

            $payload = [
                'success'        => true,
                'name'           => $original,
                'path'           => $relPath,
                'url'            => $url,
                'mime'           => $mime,
                'size'           => $size,
                'is_image'       => $isImage,
                'is_pdf'         => $isPdf,
                'is_text'        => $isText,
                'claude_can_see' => $isIngestible,
                // Sibling text block — Send.php injects this alongside the
                // image/document so Claude knows where the file lives on
                // disk and can pass that path to set_store_logo / etc.
                'path_note'      => sprintf(
                    "[The user attached a file: %s. It was saved at media path: %s. Pass this exact source_path to set_store_logo if asked to use it as the logo.]",
                    $original,
                    $relPath
                ),
            ];

            if ($isIngestible) {
                $bytes = @file_get_contents($absPath);
                if ($bytes !== false) {
                    if ($isText) {
                        // Plain text → send as a text content block (cap at 200KB to be safe)
                        $payload['claude_block'] = [
                            'type' => 'text',
                            'text' => "[Attached file: {$original}]\n\n" . mb_substr($bytes, 0, 200000),
                        ];
                    } elseif ($isImage) {
                        $payload['claude_block'] = [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mime,
                                'data' => base64_encode($bytes),
                            ],
                        ];
                    } elseif ($isPdf) {
                        $payload['claude_block'] = [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'application/pdf',
                                'data' => base64_encode($bytes),
                            ],
                        ];
                    }
                }
            } else {
                // Archive / opaque binary — Claude can't read the contents.
                // Send a text marker so it knows the file exists.
                $payload['claude_block'] = [
                    'type' => 'text',
                    'text' => "[A file was attached but I can't read inside it: {$original} ({$mime}, "
                            . round($size / 1024) . " KB). The file is saved at {$relPath}.]",
                ];
            }

            return $result->setData($payload);
        } catch (\Throwable $e) {
            $this->logger->error('[panth_claudeai] upload error: ' . $e->getMessage());
            return $result->setData(['error' => 'Upload failed. Please try a different file.']);
        }
    }
}

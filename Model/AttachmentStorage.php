<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

class AttachmentStorage
{
    public const UPLOAD_DIR = 'panth/claudeai';

    private const INGESTIBLE_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf',
        'txt', 'csv', 'json', 'xml', 'log', 'md',
    ];
    private const TEXT_EXT = ['txt', 'csv', 'json', 'xml', 'log', 'md'];
    private const INLINE_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ResourceConnection $resource
    ) {
    }

    public function getDirectory(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    public function isSafePath(string $relPath): bool
    {
        $prefix = self::UPLOAD_DIR . '/';
        if (!str_starts_with($relPath, $prefix)) {
            return false;
        }
        $name = substr($relPath, strlen($prefix));
        return $name !== ''
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !str_contains($name, '..')
            && !str_contains($name, "\0")
            && !str_starts_with($name, '.');
    }

    public function getAbsolutePath(string $relPath): ?string
    {
        if (!$this->isSafePath($relPath)) {
            return null;
        }
        return $this->getDirectory()->getAbsolutePath($relPath);
    }

    public function read(string $relPath): ?string
    {
        if (!$this->isSafePath($relPath)) {
            return null;
        }
        try {
            $dir = $this->getDirectory();
            if (!$dir->isFile($relPath)) {
                return null;
            }
            return (string) $dir->readFile($relPath);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getOwned(int $attachmentId, int $adminUserId): ?array
    {
        if ($attachmentId <= 0 || $adminUserId <= 0) {
            return null;
        }
        $conn = $this->resource->getConnection();
        $row = $conn->fetchRow(
            $conn->select()
                ->from($this->resource->getTableName('panth_claudeai_attachment'))
                ->where('attachment_id = ?', $attachmentId)
                ->where('admin_user_id = ?', $adminUserId)
                ->limit(1)
        );
        return is_array($row) && $row !== [] ? $row : null;
    }

    public function isIngestible(string $relPath): bool
    {
        return in_array($this->getExtension($relPath), self::INGESTIBLE_EXT, true);
    }

    public function isInlineMime(string $mime): bool
    {
        return in_array($mime, self::INLINE_MIME, true);
    }

    public function getPathNote(array $row): string
    {
        return sprintf(
            '[The user attached a file: %s. It was saved at upload path: %s. '
            . 'Pass this exact source_path to set_store_logo if asked to use it as the logo.]',
            (string) $row['original_name'],
            (string) $row['stored_path']
        );
    }

    public function buildBlocks(array $attachments, int $adminUserId): array
    {
        $blocks = [];
        $seen = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $id = (int) ($attachment['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $row = $this->getOwned($id, $adminUserId);
            if ($row === null) {
                continue;
            }
            $block = $this->buildBlock($row);
            if ($block === null) {
                continue;
            }
            $blocks[] = $block;
            if (in_array($block['type'], ['image', 'document'], true)) {
                $blocks[] = ['type' => 'text', 'text' => $this->getPathNote($row)];
            }
        }
        return $blocks;
    }

    public function buildBlock(array $row): ?array
    {
        $relPath = (string) ($row['stored_path'] ?? '');
        $name    = (string) ($row['original_name'] ?? '');
        $mime    = (string) ($row['mime_type'] ?? '');
        $size    = (int) ($row['size_bytes'] ?? 0);
        $ext     = $this->getExtension($relPath);

        if (!$this->isIngestible($relPath)) {
            return [
                'type' => 'text',
                'text' => "[A file was attached but I can't read inside it: {$name} ({$mime}, "
                    . round($size / 1024) . " KB). The file is saved at {$relPath}.]",
            ];
        }

        $bytes = $this->read($relPath);
        if ($bytes === null) {
            return null;
        }
        if (in_array($ext, self::TEXT_EXT, true)) {
            return [
                'type' => 'text',
                'text' => "[Attached file: {$name}]\n\n" . mb_substr($bytes, 0, 200000),
            ];
        }
        if (str_starts_with($mime, 'image/')) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mime,
                    'data' => base64_encode($bytes),
                ],
            ];
        }
        if ($mime === 'application/pdf') {
            return [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => base64_encode($bytes),
                ],
            ];
        }
        return null;
    }

    private function getExtension(string $relPath): string
    {
        return strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
    }
}

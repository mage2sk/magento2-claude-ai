<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Setup\Patch\Data;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\ClaudeAi\Model\AttachmentStorage;
use Psr\Log\LoggerInterface;

class MoveChatUploadsToPrivateStorage implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(): self
    {
        $dir = AttachmentStorage::UPLOAD_DIR;
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        if (!$media->isDirectory($dir)) {
            return $this;
        }
        $target = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $target->create($dir);

        $moved = 0;
        foreach ($media->read($dir) as $path) {
            if (!$media->isFile($path)) {
                continue;
            }
            $name = basename($path);
            if ($name === '.htaccess') {
                $media->delete($path);
                continue;
            }
            $newPath = $dir . '/' . $name;
            if ($target->isExist($newPath)) {
                $newPath = $this->uniquePath($target, $dir, $name);
            }
            try {
                $media->copyFile($path, $newPath, $target);
                $media->delete($path);
                if ($newPath !== $path) {
                    $this->updateReferences($path, $newPath);
                }
                $moved++;
            } catch (\Throwable $e) {
                $this->logger->warning('[panth_claudeai] could not move upload ' . $path . ': ' . $e->getMessage());
            }
        }

        if ($media->read($dir) === []) {
            $media->delete($dir);
        }
        $this->logger->info('[panth_claudeai] moved ' . $moved . ' chat uploads to private storage');

        return $this;
    }

    private function uniquePath(WriteInterface $target, string $dir, string $name): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        do {
            $candidate = $dir . '/' . $stem . '_' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
        } while ($target->isExist($candidate));
        return $candidate;
    }

    private function updateReferences(string $oldPath, string $newPath): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->update(
            $this->moduleDataSetup->getTable('panth_claudeai_attachment'),
            ['stored_path' => $newPath],
            ['stored_path = ?' => $oldPath]
        );
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}

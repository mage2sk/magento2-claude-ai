<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\Upload;
use Panth\ClaudeAi\Model\AttachmentStorage;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\ContextBuilderTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UploadTest extends TestCase
{
    use ContextBuilderTrait;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $dir;
    private ?array $data = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/panth_upload_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function tmpFile(string $contents): string
    {
        $path = $this->dir . '/php' . bin2hex(random_bytes(3));
        file_put_contents($path, $contents);
        return $path;
    }

    private function controller(?array $files, bool $enabled = true, ?AttachmentStorage $storage = null, string $formKey = 'fk'): Upload
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getFiles')->willReturn($files);
        $request->method('getParam')->willReturn(null);

        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->data = $data;
            return $json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        if ($storage === null) {
            $dir = $this->createStub(WriteInterface::class);
            $dir->method('getAbsolutePath')->willReturnCallback(fn($p = null) => $this->dir . '/stored_' . basename((string) $p));
            $storage = $this->createStub(AttachmentStorage::class);
            $storage->method('getDirectory')->willReturn($dir);
        }

        return new Upload(
            $this->buildContext($request, 7, $formKey),
            $jsonFactory,
            $storage,
            $this->createStub(ResourceConnection::class),
            $this->createStub(LoggerInterface::class),
            $config,
            $this->formKeyStub()
        );
    }

    private function file(string $name, string $tmp, int $size = 10, int $error = UPLOAD_ERR_OK): array
    {
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => $size, 'error' => $error];
    }

    public function testDisabled(): void
    {
        $this->controller(null, false)->execute();
        $this->assertSame(['error' => 'Claude AI is disabled.'], $this->data);
    }

    public function testNoFile(): void
    {
        $this->controller(null)->execute();
        $this->assertSame(['error' => 'No file was uploaded.'], $this->data);

        $this->controller(['name' => 'a.txt', 'tmp_name' => ''])->execute();
        $this->assertSame(['error' => 'No file was uploaded.'], $this->data);
    }

    public function testPhpUploadErrorIsExplained(): void
    {
        $this->controller($this->file('a.txt', $this->tmpFile('x'), 1, UPLOAD_ERR_INI_SIZE))->execute();
        $this->assertSame(['error' => 'Upload failed: larger than upload_max_filesize.'], $this->data);

        $this->controller($this->file('a.txt', $this->tmpFile('x'), 1, 99))->execute();
        $this->assertSame(['error' => 'Upload failed: unknown error code 99.'], $this->data);
    }

    public function testTooBig(): void
    {
        $this->controller($this->file('a.txt', $this->tmpFile('x'), 20971521))->execute();
        $this->assertSame(['error' => 'That file is too big. Maximum 20 MB.'], $this->data);
    }

    public function testUnsupportedExtension(): void
    {
        $this->controller($this->file('tool.exe', $this->tmpFile('hello'), 5))->execute();
        $this->assertStringContainsString("That file type isn't supported (detected .exe", $this->data['error']);

        $this->controller($this->file('README', $this->tmpFile('hello'), 5))->execute();
        $this->assertStringContainsString('(detected (no extension)', $this->data['error']);
    }

    public function testContentNotMatchingExtensionIsRejected(): void
    {
        $this->controller($this->file('data.csv', $this->tmpFile("\x00\x01\x02\x03\xFF\xFE binary"), 12))->execute();
        $this->assertMatchesRegularExpression('/^The file content \(MIME .+\) doesn\'t match its extension \(\.csv\)\.$/', $this->data['error']);
    }

    public function testValidImagePassesValidationButNonUploadedFileIsNotMoved(): void
    {
        $this->controller($this->file('logo.txt', $this->tmpFile(base64_decode(self::PNG)), 70))->execute();
        $this->assertSame(['error' => 'Could not save the file. Please try again.'], $this->data);
    }

    public function testUnexpectedFailureIsHidden(): void
    {
        $storage = $this->createStub(AttachmentStorage::class);
        $storage->method('getDirectory')->willThrowException(new \RuntimeException('disk full'));

        $this->controller($this->file('notes.txt', $this->tmpFile('hello'), 5), true, $storage)->execute();
        $this->assertSame(['error' => 'Upload failed. Please try a different file.'], $this->data);
    }

    public function testCsrfValidation(): void
    {
        $controller = $this->controller(null, true, null, 'secret');

        $ok = $this->createStub(HttpRequest::class);
        $ok->method('getParam')->willReturn('secret');
        $bad = $this->createStub(HttpRequest::class);
        $bad->method('getParam')->willReturn('guess');
        $none = $this->createStub(HttpRequest::class);
        $none->method('getParam')->willReturn(null);

        $this->assertTrue($controller->validateForCsrf($ok));
        $this->assertFalse($controller->validateForCsrf($bad));
        $this->assertFalse($controller->validateForCsrf($none));
        $this->assertFalse($controller->_processUrlKeys());

        $exception = $controller->createCsrfValidationException($none);
        $this->assertInstanceOf(InvalidRequestException::class, $exception);
        $this->assertSame(['error' => 'Your session expired - please refresh and try again.'], $this->data);
    }
}

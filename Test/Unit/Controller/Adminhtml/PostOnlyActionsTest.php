<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Panth\ClaudeAi\Controller\Adminhtml\Checkpoint\Restore;
use Panth\ClaudeAi\Controller\Adminhtml\Training\Delete;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PostOnlyActionsTest extends TestCase
{
    public static function actionProvider(): array
    {
        return [
            'checkpoint restore' => [Restore::class, 'Panth_ClaudeAi::ai_checkpoint'],
            'training delete' => [Delete::class, 'Panth_ClaudeAi::ai_training'],
        ];
    }

    #[DataProvider('actionProvider')]
    public function testStateChangingActionsAcceptPostOnly(string $class, string $resource): void
    {
        $reflection = new \ReflectionClass($class);

        $this->assertTrue($reflection->implementsInterface(HttpPostActionInterface::class));
        $this->assertFalse($reflection->implementsInterface(HttpGetActionInterface::class));
        $this->assertSame($resource, $reflection->getConstant('ADMIN_RESOURCE'));
    }
}

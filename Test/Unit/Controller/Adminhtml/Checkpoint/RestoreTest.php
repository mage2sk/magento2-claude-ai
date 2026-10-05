<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\Checkpoint;

use Panth\ClaudeAi\Controller\Adminhtml\Checkpoint\Restore;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\ContextBuilderTrait;
use PHPUnit\Framework\TestCase;

class RestoreTest extends TestCase
{
    use ContextBuilderTrait;

    public function testAclResource(): void
    {
        $this->assertSame('Panth_ClaudeAi::ai_checkpoint', Restore::ADMIN_RESOURCE);
    }

    public function testMissingCheckpointId(): void
    {
        $service = $this->createMock(CheckpointService::class);
        $service->expects($this->never())->method('restore');

        (new Restore($this->buildContext($this->request()), $service))->execute();

        $this->assertSame([['error', 'No checkpoint specified.']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testSuccessfulRestore(): void
    {
        $service = $this->createMock(CheckpointService::class);
        $service->expects($this->once())->method('restore')->with('cp_1')
            ->willReturn(['status' => 'success', 'affected_count' => 3]);

        (new Restore($this->buildContext($this->request(['checkpoint_id' => 'cp_1'])), $service))->execute();

        $this->assertSame([['success', 'Restored 3 records from checkpoint cp_1.']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testFailedRestoreShowsServiceMessage(): void
    {
        $service = $this->createStub(CheckpointService::class);
        $service->method('restore')->willReturn(['status' => 'error', 'message' => 'Checkpoint cp_1 is restored and cannot be restored again.']);

        (new Restore($this->buildContext($this->request(['checkpoint_id' => 'cp_1'])), $service))->execute();

        $this->assertSame([['error', 'Checkpoint cp_1 is restored and cannot be restored again.']], $this->messages);
    }

    public function testExceptionIsShownAsError(): void
    {
        $service = $this->createStub(CheckpointService::class);
        $service->method('restore')->willThrowException(new \RuntimeException('db down'));

        (new Restore($this->buildContext($this->request(['checkpoint_id' => 'cp_1'])), $service))->execute();

        $this->assertSame([['error', 'db down']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);
    }
}

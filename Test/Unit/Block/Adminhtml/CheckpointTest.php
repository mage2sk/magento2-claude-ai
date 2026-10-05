<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Block\Adminhtml;

use Magento\Framework\DataObject;
use Panth\ClaudeAi\Block\Adminhtml\Checkpoint;
use Panth\ClaudeAi\Block\Adminhtml\Credit;
use Panth\ClaudeAi\Block\Adminhtml\Training\Edit as TrainingEdit;
use Panth\ClaudeAi\Block\Adminhtml\Training\Listing as TrainingListing;
use Panth\ClaudeAi\Model\ResourceModel\Checkpoint\Collection as CheckpointCollection;
use Panth\ClaudeAi\Model\ResourceModel\Checkpoint\CollectionFactory as CheckpointCollectionFactory;
use Panth\ClaudeAi\Model\ResourceModel\Training\Collection as TrainingCollection;
use Panth\ClaudeAi\Model\ResourceModel\Training\CollectionFactory as TrainingCollectionFactory;
use Panth\ClaudeAi\Model\Training;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class CheckpointTest extends TestCase
{
    use BlockContextTrait;

    protected function setUp(): void
    {
        $this->installObjectManager();
    }

    protected function tearDown(): void
    {
        $this->restoreObjectManager();
    }

    public function testCheckpointsAreNewestFirstAndLimited(): void
    {
        $a = new DataObject(['checkpoint_id' => 'cp_a']);
        $b = new DataObject(['checkpoint_id' => 'cp_b']);
        $collection = $this->createMock(CheckpointCollection::class);
        $collection->expects($this->once())->method('setOrder')->with('created_at', 'DESC')->willReturnSelf();
        $collection->expects($this->once())->method('setPageSize')->with(10)->willReturnSelf();
        $collection->method('getItems')->willReturn([5 => $a, 9 => $b]);
        $factory = $this->createStub(CheckpointCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $block = new Checkpoint($this->blockContext(), $factory);

        $this->assertSame([$a, $b], $block->getCheckpoints(10));
        $this->assertSame('https://admin.test/claudeai/checkpoint/restore?checkpoint_id=cp_a', $block->getRestoreUrl('cp_a'));
    }

    public function testTrainingListing(): void
    {
        $item = new DataObject(['training_id' => 3]);
        $collection = $this->createStub(TrainingCollection::class);
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn([3 => $item]);
        $factory = $this->createStub(TrainingCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $block = new TrainingListing($this->blockContext(), $factory);

        $this->assertSame([$item], $block->getTrainingItems());
        $this->assertSame('https://admin.test/claudeai/training/edit', $block->getNewUrl());
        $this->assertSame('https://admin.test/claudeai/training/edit?id=3', $block->getEditUrl(3));
        $this->assertSame('https://admin.test/claudeai/training/delete?id=3', $block->getDeleteUrl(3));
    }

    public function testTrainingEditDeleteUrlOnlyForSavedRecord(): void
    {
        $saved = $this->createStub(Training::class);
        $saved->method('getId')->willReturn(8);
        $unsaved = $this->createStub(Training::class);

        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturnOnConsecutiveCalls($saved, $saved, $unsaved, null);

        $block = new TrainingEdit($this->blockContext(), $registry);

        $this->assertSame($saved, $block->getTraining());
        $this->assertSame('https://admin.test/claudeai/training/delete?id=8', $block->getDeleteUrl());
        $this->assertSame('', $block->getDeleteUrl());
        $this->assertSame('', $block->getDeleteUrl());
        $this->assertSame('https://admin.test/claudeai/training/save', $block->getSaveUrl());
        $this->assertSame('https://admin.test/claudeai/training/index', $block->getBackUrl());
    }

    public function testCreditDetails(): void
    {
        $credit = new Credit($this->blockContext());

        $this->assertSame('https://kishansavaliya.com', $credit->getWebsiteUrl());
        $this->assertStringContainsString('@', $credit->getEmailAddress());
        $this->assertStringStartsWith('https://www.upwork.com/', $credit->getUpworkUrl());
        $this->assertSame('Kishan Savaliya', $credit->getDeveloperName());
        $this->assertSame('Panth Infotech', $credit->getCompanyName());
    }
}

<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Panth\ClaudeAi\Model\ResourceModel\Training\Collection;
use Panth\ClaudeAi\Model\ResourceModel\Training\CollectionFactory;
use Panth\ClaudeAi\Model\Training;
use Panth\ClaudeAi\Model\TrainingRepository;
use PHPUnit\Framework\TestCase;

class TrainingRepositoryTest extends TestCase
{
    private function example(string $title, string $message, string $outcome): Training
    {
        $training = $this->createStub(Training::class);
        $training->method('getData')->willReturnCallback(
            fn(string $key) => ['title' => $title, 'user_message' => $message, 'expected_outcome' => $outcome][$key] ?? null
        );
        return $training;
    }

    private function repository(array $items, int $expectedCreates = 1): TrainingRepository
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addFieldToFilter')
            ->with('status', Training::STATUS_ACTIVE)->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn($items);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->exactly($expectedCreates))->method('create')->willReturn($collection);

        return new TrainingRepository($factory);
    }

    public function testActiveExamplesAreLoadedOnceAndSliced(): void
    {
        $a = $this->example('A', 'a', 'x');
        $b = $this->example('B', 'b', 'y');
        $repo = $this->repository([10 => $a, 20 => $b]);

        $this->assertSame([$a, $b], $repo->getActiveExamples());
        $this->assertSame([$a], $repo->getActiveExamples(1));
    }

    public function testRenderEmptyWhenNoExamples(): void
    {
        $this->assertSame('', $this->repository([])->renderForSystemPrompt());
    }

    public function testRenderListsExamplesInOrder(): void
    {
        $repo = $this->repository([
            $this->example('Discount', 'Discount hoodies by 20%', 'Call get_products first.'),
            $this->example('Stock', 'What is low?', 'Use get_low_stock_products.'),
            $this->example('Third', 'c', 'z'),
        ]);

        $out = $repo->renderForSystemPrompt(2);

        $this->assertStringStartsWith("\n# Training examples (merchant-curated)\n", $out);
        $this->assertStringContainsString("## Example 1: Discount\n**Merchant says:** \"Discount hoodies by 20%\"\n**You should:** Call get_products first.\n\n", $out);
        $this->assertStringContainsString('## Example 2: Stock', $out);
        $this->assertStringNotContainsString('Third', $out);
    }
}

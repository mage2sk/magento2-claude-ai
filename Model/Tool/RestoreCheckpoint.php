<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Panth\ClaudeAi\Model\CheckpointService;

class RestoreCheckpoint implements ToolInterface
{
    public function __construct(
        private readonly CheckpointService $checkpoints
    ) {
    }

    public function name(): string { return 'restore_checkpoint'; }

    public function definition(): array
    {
        return [
            'name' => 'restore_checkpoint',
            'description' => 'Undo a previous bulk operation by restoring from its checkpoint. Pass the checkpoint_id returned by a write tool (e.g. cp_a1b2c3d4...). Reverses the change for every record originally affected.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'checkpoint_id' => ['type' => 'string', 'description' => 'The cp_xxx ID returned by a previous write tool.'],
                ],
                'required' => ['checkpoint_id'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $id = trim((string) ($input['checkpoint_id'] ?? ''));
        if ($id === '') {
            return ['status' => 'error', 'message' => 'checkpoint_id is required.'];
        }
        return $this->checkpoints->restore($id);
    }
}

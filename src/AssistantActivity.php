<?php

namespace Alareqi\FilamentAiAssistant;

use Illuminate\Database\Eloquent\Model;

final class AssistantActivity extends Model
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const INTERRUPTED = 'interrupted';

    protected $table = 'filament_ai_assistant_activity';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['record_ids' => 'array', 'created_at' => 'immutable_datetime'];
    }
}

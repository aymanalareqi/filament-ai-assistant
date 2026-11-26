<?php

namespace Alareqi\FilamentAiAssistant;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class AssistantTool implements Tool
{
    public function __construct(
        private string $name,
        private string $description,
        private Closure $properties,
        private Closure $execute,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function schema(JsonSchema $schema): array
    {
        return ($this->properties)($schema);
    }

    public function handle(Request $request): string
    {
        try {
            $result = ($this->execute)($request);
        } catch (ValidationException $exception) {
            $result = ['error' => $exception->getMessage()];
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}

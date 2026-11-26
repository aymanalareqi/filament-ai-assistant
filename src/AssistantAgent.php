<?php

namespace Alareqi\FilamentAiAssistant;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;

#[MaxSteps(6)]
#[MaxTokens(1600)]
final class AssistantAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** @var array<string, mixed>|null */
    private ?array $draft = null;

    /** @param list<array{role: string, text: string}> $history */
    public function __construct(
        private ResourceRegistry $resources,
        private DraftStore $drafts,
        private array $history = [],
    ) {}

    public function instructions(): string
    {
        return FilamentAiAssistantPlugin::current()?->getInstructions() ?? Config::string('filament-ai-assistant.instructions');
    }

    public function messages(): iterable
    {
        foreach ($this->history as $message) {
            yield $message['role'] === 'user' ? new UserMessage($message['text']) : new AssistantMessage($message['text']);
        }
    }

    /** @return array<string, mixed>|null */
    public function draft(): ?array
    {
        return $this->draft;
    }

    public function tools(): iterable
    {
        $actions = app(ActionProposalStore::class);

        return [
            new AssistantTool('list_resources', 'List accessible panel resources and operations. Edit permission is checked per record.',
                fn (JsonSchema $schema): array => [],
                fn (Request $request): array => $this->resources->catalog(),
            ),
            new AssistantTool('search_records', 'Find records by name, title, code, or ID using the resources available to this admin.',
                fn (JsonSchema $schema): array => [
                    'resource' => $schema->string()->required(),
                    'query' => $schema->string()->required(),
                ],
                function (Request $request): array {
                    $args = $request->validate(['resource' => 'required|string|max:150', 'query' => 'required|string|max:100']);

                    return ['records' => $this->resources->search($args['resource'], $args['query'])];
                },
            ),
            new AssistantTool('describe_form', 'Describe fields and current values for the native Filament create or edit form. Omitted fields are not supported by this assistant.',
                fn (JsonSchema $schema): array => $this->targetSchema($schema),
                function (Request $request): array {
                    $args = $request->validate($this->targetRules());
                    $resource = $this->resources->resource($args['resource']);
                    $context = $this->resources->form($resource, $args['operation'], $args['record_id'] ?? null);

                    return ['fields' => $this->resources->describe($context['form'])];
                },
            ),
            new AssistantTool('prepare_change', 'Prepare a draft for admin review. Does not save any record. Send selected field values as strings or null. Use true or false as strings for toggles.',
                fn (JsonSchema $schema): array => [
                    ...$this->targetSchema($schema),
                    'changes' => $schema->array()->items($schema->object([
                        'field' => $schema->string()->required(),
                        'value' => $schema->string()->nullable()->required(),
                    ]))->required(),
                ],
                function (Request $request): array {
                    if ($this->draft !== null) {
                        return ['prepared' => true];
                    }

                    $args = $request->validate([
                        ...$this->targetRules(),
                        'changes' => 'required|array|list|min:1|max:20',
                        'changes.*' => 'required|array:field,value',
                        'changes.*.field' => 'required|string|max:100|distinct:strict',
                        'changes.*.value' => 'present|nullable|string|max:6000',
                    ]);

                    $this->draft = $this->drafts->prepare($args['resource'], $args['operation'], $args['record_id'] ?? null, $args['changes']);

                    return ['prepared' => true, 'fields' => array_column($this->draft['fields'], 'label')];
                },
            ),
            new AssistantTool('table_selection', 'Get the records explicitly sent to the assistant from a resource table. Ask the admin to select rows and choose Use selection in assistant if there is no selection.',
                fn (JsonSchema $schema): array => [],
                fn (): array => ['selection' => $actions->selection()],
            ),
            new AssistantTool('prepare_action', 'Prepare an existing Filament action for review only. It does not run the action. For publish, unpublish, or bulk_delete, use the entire confirmed resource table selection and never supply record_id. For delete, use the exact single ID from search_records. If the target is ambiguous, ask before preparing it.',
                fn (JsonSchema $schema): array => [
                    'resource' => $schema->string()->required(),
                    'operation' => $schema->string()->enum(ActionProposalStore::OPERATIONS)->required(),
                    'record_id' => $schema->string()->description('The exact ID for a single delete only. Omit for bulk actions.'),
                ],
                function (Request $request) use ($actions): array {
                    if ($this->draft !== null) {
                        return ['prepared' => true];
                    }

                    $args = $request->validate([
                        'resource' => 'required|string|max:150',
                        'operation' => 'required|in:'.implode(',', ActionProposalStore::OPERATIONS),
                        'record_id' => 'required_if:operation,delete|prohibited_unless:operation,delete|nullable|string|max:100',
                    ]);

                    $this->draft = $actions->prepare($args['resource'], $args['operation'], $args['record_id'] ?? null);

                    return ['prepared' => true, 'records' => count($this->draft['records'])];
                },
            ),
        ];
    }

    private function targetSchema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->required(),
            'operation' => $schema->string()->enum(['create', 'edit'])->required(),
            'record_id' => $schema->string()->description('The exact ID returned by search_records for an edit. Omit for a create.'),
        ];
    }

    private function targetRules(): array
    {
        return [
            'resource' => 'required|string|max:150',
            'operation' => 'required|in:create,edit',
            'record_id' => 'required_if:operation,edit|nullable|string|max:100',
        ];
    }
}

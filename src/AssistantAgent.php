<?php

namespace Alareqi\FilamentAiAssistant;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
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

    /** @var array<string, list<array{id: string, label: string, can_edit: bool}>> */
    private array $searchMatches = [];

    /** @var array{resource: string, candidates: list<array{id: string, label: string}>}|null */
    private ?array $recordChoice = null;

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @param  array{resource: string, record_id: string, resource_label: string, record_label: string, can_edit: bool}|null  $currentRecord
     * @param  array{resource: string, label: string}|null  $currentList
     * @param  array{resource: string, label: string, records: list<array{id: string, label: string}>}|null  $selection
     */
    public function __construct(
        private ResourceRegistry $resources,
        private DraftStore $drafts,
        private array $history = [],
        private ?array $currentRecord = null,
        private bool $recordExplicitlyChosen = false,
        private ?array $currentList = null,
        private ?array $selection = null,
    ) {}

    public function instructions(): string
    {
        $instructions = FilamentAiAssistantPlugin::current()?->getInstructions() ?? Config::string('filament-ai-assistant.instructions');

        if ($this->recordExplicitlyChosen) {
            return $instructions."\n\nThe user already chose the exact record for this request. Call current_record for that selection, then continue the original request. Do not ask them to choose again or prepare a change for any other record.";
        }

        if ($this->currentList !== null) {
            $instructions .= "\n\nThe admin is on the {$this->currentList['label']} list (resource: {$this->currentList['resource']}). A standalone name, code, ID, or request that does not name a resource refers to this list. Omit resource from search_records to search the current list. Use the exact spelling from the user's message. The current list takes precedence over resource assumptions in chat history, including previous assistant replies. Search another resource only when the user explicitly identifies it. If the list search has no matches, report that for this list instead of switching resources.";
        }

        if ($this->selection !== null) {
            $instructions .= "\n\nThe table has checked rows for this message. Use table_selection when the user refers to checked or selected rows, and current_record if exactly one checked row identifies what the user means by “this record”. If the request targets one of several checked rows and the exact row is unclear, call ask_record_choice for their resource. Do not assume a checked row overrides a different target explicitly named by the user.";
        }

        if ($this->currentRecord === null || $this->selection !== null) {
            return $instructions;
        }

        return $instructions."\n\nA record from the current panel page is attached to this message. Call current_record when the user says “this record” or talks about the record on the page. Use that tool's resource and record ID, unless the user explicitly identifies a different target. Never assume that the current record is the same as a record mentioned in chat history.";
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

    /** @return array{resource: string, candidates: list<array{id: string, label: string}>}|null */
    public function recordChoice(): ?array
    {
        return $this->recordChoice;
    }

    public function tools(): iterable
    {
        $actions = app(ActionProposalStore::class);

        return [
            ...($this->currentRecord === null ? [] : [
                new AssistantTool('current_record', 'Identify the exact authorized record chosen by the user or attached from the page for this message. Does not change anything.',
                    fn (JsonSchema $schema): array => [],
                    fn (): array => ['record' => $this->currentRecord],
                ),
            ]),
            new AssistantTool('list_resources', 'List accessible panel resources and operations. Edit permission is checked per record.',
                fn (JsonSchema $schema): array => [],
                fn (Request $request): array => $this->resources->catalog(),
            ),
            new AssistantTool('search_records', $this->currentList === null
                ? 'Find records by name, title, code, or ID using the resources available to this admin.'
                : 'Find records by name, title, code, or ID. Leave resource unset to search the current list. Set resource only if the user explicitly identifies a different resource.',
                fn (JsonSchema $schema): array => [
                    'resource' => $this->currentList === null ? $schema->string()->required() : $schema->string(),
                    'query' => $schema->string()->required(),
                ],
                function (Request $request): array {
                    $args = $request->validate([
                        'resource' => $this->currentList === null ? 'required|string|max:150' : 'sometimes|string|max:150',
                        'query' => 'required|string|max:100',
                    ]);
                    $resource = $args['resource'] ?? $this->currentList['resource'];

                    $records = $this->resources->search($resource, $args['query']);
                    $this->searchMatches[$resource] = $records;

                    return ['resource' => $resource, 'records' => $records];
                },
            ),
            new AssistantTool('ask_record_choice', 'For an ambiguous single-record target, ask the admin to choose from the latest search matches or the currently checked rows for this resource. Use this before preparing a change and stop when waiting_for_user is true. Do not call it for a search-only list unless the user must pick a target.',
                fn (JsonSchema $schema): array => ['resource' => $schema->string()->required()],
                function (Request $request): array {
                    $args = $request->validate(['resource' => 'required|string|max:150']);

                    return match (true) {
                        $this->recordChoice !== null => ['waiting_for_user' => true],
                        $this->draft !== null => ['prepared' => true],
                        $this->recordExplicitlyChosen => ['record_already_chosen' => true],
                        $this->offerRecordChoice($args['resource']) => ['waiting_for_user' => true],
                        default => ['error' => 'Search for multiple candidate records first.'],
                    };
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

                    if ($this->waitsForRecordChoice($args['resource'], $args['operation'] === 'edit' ? $args['record_id'] : null)) {
                        return ['waiting_for_user' => true];
                    }

                    $this->draft = $this->drafts->prepare($args['resource'], $args['operation'], $args['record_id'] ?? null, $args['changes']);

                    return ['prepared' => true, 'fields' => array_column($this->draft['fields'], 'label')];
                },
            ),
            new AssistantTool('table_selection', 'Get the rows checked in the current resource table when this message was sent. Ask the admin to check specific rows if there is no selection.',
                fn (JsonSchema $schema): array => [],
                fn (): array => ['selection' => $this->selection],
            ),
            new AssistantTool('list_actions', 'Find native Filament actions that can be reviewed. Provide the exact record_id to find row, view and edit actions. Bulk actions are listed only when rows are checked in the current resource table.',
                fn (JsonSchema $schema): array => [
                    'resource' => $schema->string()->required(),
                    'record_id' => $schema->string(),
                ],
                function (Request $request) use ($actions): array {
                    $args = $request->validate(['resource' => 'required|string|max:150', 'record_id' => 'nullable|string|max:100']);

                    return ['actions' => $actions->describeActions($args['resource'], $args['record_id'] ?? null, $this->selection)];
                },
            ),
            new AssistantTool('prepare_action', 'Open an existing Filament action for review only. Use the exact action ID reported by list_actions as operation. Supply the exact record_id only for row, view, or edit actions. Bulk actions use the checked rows from the same resource table. Ask when a record is ambiguous. Action inputs are entered by the admin in Filament.',
                fn (JsonSchema $schema): array => [
                    'resource' => $schema->string()->required(),
                    'operation' => $schema->string()->required(),
                    'record_id' => $schema->string()->description('The exact ID for a row, view, or edit action only.'),
                ],
                function (Request $request) use ($actions): array {
                    if ($this->draft !== null) {
                        return ['prepared' => true];
                    }

                    $args = $request->validate([
                        'resource' => 'required|string|max:150',
                        'operation' => 'required|string|max:150',
                        'record_id' => 'nullable|string|max:100',
                    ]);

                    if ($this->waitsForRecordChoice($args['resource'], $args['record_id'] ?? null)) {
                        return ['waiting_for_user' => true];
                    }

                    $this->draft = $actions->prepare($args['resource'], $args['operation'], $args['record_id'] ?? null, $this->selection);

                    return ['prepared' => true, 'records' => count($this->draft['records'])];
                },
            ),
        ];
    }

    private function offerRecordChoice(string $resource): bool
    {
        $matches = $this->searchMatches[$resource]
            ?? ($resource === ($this->selection['resource'] ?? null) ? $this->selection['records'] : []);

        if (count($matches) < 2) {
            return false;
        }

        $this->recordChoice = [
            'resource' => $resource,
            'candidates' => array_map(fn (array $match): array => ['id' => $match['id'], 'label' => $match['label']], $matches),
        ];

        return true;
    }

    private function waitsForRecordChoice(string $resource, ?string $recordId): bool
    {
        if ($this->recordChoice !== null) {
            return true;
        }

        $matchesExactRecord = $resource === ($this->currentRecord['resource'] ?? null)
            && $recordId === ($this->currentRecord['record_id'] ?? null);

        if ($this->recordExplicitlyChosen && ! $matchesExactRecord) {
            throw ValidationException::withMessages(['record_id' => __('filament-ai-assistant::assistant.errors.choose_listed_record')]);
        }

        return $recordId !== null && ! $matchesExactRecord && $this->offerRecordChoice($resource);
    }

    private function targetSchema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->required(),
            'operation' => $schema->string()->enum(['create', 'edit'])->required(),
            'record_id' => $schema->string()->description('The exact ID returned by current_record or search_records for an edit. Omit for a create.'),
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

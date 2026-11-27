<?php

namespace Alareqi\FilamentAiAssistant;

use Closure;
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

    private const SEARCH_SINGLE_RECORD = 'single_record';

    private const SEARCH_BROWSE = 'browse';

    /** @var array<string, mixed>|null */
    private ?array $draft = null;

    /** @var array<string, list<array{id: string, label: string, can_edit: bool}>> */
    private array $searchMatches = [];

    /** @var array{resource: string, candidates: list<array{id: string, label: string}>}|null */
    private ?array $recordChoice = null;

    /** @var array{resource: string, record_id: ?string, record_ids: list<string>, options: list<array{id: string, label: string}>}|null */
    private ?array $actionChoice = null;

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @param  array{resource: string, record_id: string, resource_label: string, record_label: string, can_edit: bool}|null  $currentRecord
     * @param  array{resource: string, label: string}|null  $currentList
     * @param  array{resource: string, label: string, records: list<array{id: string, label: string}>}|null  $selection
     * @param  (Closure(string): void)|null  $onProgress
     * @param  array{resource: string, record_id: ?string, kind: string}|null  $actionTarget
     */
    public function __construct(
        private ResourceRegistry $resources,
        private DraftStore $drafts,
        private array $history = [],
        private ?array $currentRecord = null,
        private bool $recordExplicitlyChosen = false,
        private ?array $currentList = null,
        private ?array $selection = null,
        private ?Closure $onProgress = null,
        private ?array $actionTarget = null,
    ) {}

    public function instructions(): string
    {
        $instructions = FilamentAiAssistantPlugin::current()?->getInstructions() ?? Config::string('filament-ai-assistant.instructions');

        if ($this->actionTarget !== null) {
            $instructions .= "\n\nThe latest user message answers a pending action clarification. Interpret it with the preceding request. Keep its original resource and target, including the original checked rows if any. Use prepare_action for the clarified native action. If the answer is still unclear, ask another action choice or explain what is missing. Do not treat the answer as approval to execute anything.";
        }

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

    /** @return array{resource: string, record_id: ?string, record_ids: list<string>, options: list<array{id: string, label: string}>}|null */
    public function actionChoice(): ?array
    {
        return $this->actionChoice;
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
                function (): array {
                    $this->reportProgress('checking_resources');

                    return $this->resources->catalog();
                },
            ),
            new AssistantTool('search_records', $this->currentList === null
                ? 'Find records by name, title, code, or ID using the resources available to this admin.'
                : 'Find records by name, title, code, or ID. Leave resource unset to search the current list. Set resource only if the user explicitly identifies a different resource.',
                fn (JsonSchema $schema): array => [
                    'resource' => $this->currentList === null ? $schema->string()->required() : $schema->string(),
                    'query' => $schema->string()->required(),
                    'purpose' => $schema->string()
                        ->enum([self::SEARCH_SINGLE_RECORD, self::SEARCH_BROWSE])
                        ->description('Use single_record when the user needs one exact target, including “can I delete or edit this record?” Use browse only when the user wants a list of matches.')
                        ->required(),
                ],
                function (Request $request): array {
                    $args = $request->validate([
                        'resource' => $this->currentList === null ? 'required|string|max:150' : 'sometimes|string|max:150',
                        'query' => 'required|string|max:100',
                        'purpose' => 'sometimes|in:'.self::SEARCH_SINGLE_RECORD.','.self::SEARCH_BROWSE,
                    ]);
                    $resource = $args['resource'] ?? $this->currentList['resource'];

                    if ($resource === ($this->currentList['resource'] ?? null)) {
                        $this->reportProgress('searching_list', ['resource' => $this->currentList['label']]);
                    } else {
                        $this->reportProgress('searching');
                    }

                    $records = $this->resources->search($resource, $args['query']);
                    $this->searchMatches[$resource] = $records;

                    $result = ['resource' => $resource, 'records' => $records];

                    if (($args['purpose'] ?? null) === self::SEARCH_SINGLE_RECORD && ! $this->recordExplicitlyChosen && $this->actionTarget === null
                        && $this->recordChoice === null && $this->actionChoice === null && $this->draft === null && $this->offerRecordChoice($resource)) {
                        $result['next_tool'] = ['name' => 'ask_record_choice', 'arguments' => ['resource' => $resource]];
                    }

                    return $result;
                },
            ),
            new AssistantTool('ask_record_choice', 'Show clickable record choices. You must call this before any reply or draft when search_records returns next_tool for an ambiguous single-record request, or when the user must choose between several checked rows. Do not ask the user to choose in plain text. Stop when waiting_for_user is true. Do not call this for a browse-only list.',
                fn (JsonSchema $schema): array => ['resource' => $schema->string()->required()],
                function (Request $request): array {
                    $args = $request->validate(['resource' => 'required|string|max:150']);

                    return match (true) {
                        $this->recordChoice !== null || $this->actionChoice !== null => ['waiting_for_user' => true],
                        $this->draft !== null => ['prepared' => true],
                        $this->recordExplicitlyChosen || $this->actionTarget !== null => ['record_already_chosen' => true],
                        $this->offerRecordChoice($args['resource']) => ['waiting_for_user' => true],
                        default => ['error' => 'Search for multiple candidate records first.'],
                    };
                },
            ),
            new AssistantTool('describe_form', 'Describe fields and current values for the native Filament create or edit form. Omitted fields are not supported by this assistant.',
                fn (JsonSchema $schema): array => $this->targetSchema($schema),
                function (Request $request): array {
                    $args = $request->validate($this->targetRules());
                    $this->reportProgress('checking_form');
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

                    if ($this->actionTarget !== null) {
                        throw ValidationException::withMessages(['operation' => __('filament-ai-assistant::assistant.errors.action_choice_target')]);
                    }

                    if ($this->waitsForChoice($args['resource'], $args['operation'] === 'edit' ? $args['record_id'] : null)) {
                        return ['waiting_for_user' => true];
                    }

                    $this->reportProgress('preparing_draft');
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
                    $this->reportProgress('checking_actions');

                    return ['actions' => $actions->describeActions($args['resource'], $args['record_id'] ?? null, $this->selection)];
                },
            ),
            new AssistantTool('ask_action_choice', 'Pause for the admin to choose between two or more plausible native actions for the same target. Use exact action IDs from list_actions and never invent options. Omit record_id for checked rows. Do not ask in plain text. Stop when waiting_for_user is true.',
                fn (JsonSchema $schema): array => [
                    'resource' => $schema->string()->required(),
                    'record_id' => $schema->string(),
                    'operations' => $schema->array()->items($schema->string())->required(),
                ],
                fn (Request $request): array => $this->offerActionChoice($request, $actions),
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

                    $this->assertActionTarget(
                        $args['resource'],
                        $args['record_id'] ?? null,
                        app(NativeResourceActions::class)->targetKind($args['operation']),
                    );
                    if ($this->waitsForChoice($args['resource'], $args['record_id'] ?? null)) {
                        return ['waiting_for_user' => true];
                    }

                    $this->reportProgress('preparing_action');
                    $this->draft = $actions->prepare($args['resource'], $args['operation'], $args['record_id'] ?? null, $this->selection);

                    return ['prepared' => true, 'records' => count($this->draft['records'])];
                },
            ),
        ];
    }

    /** @return array{waiting_for_user?: bool, prepared?: bool, error?: string} */
    private function offerActionChoice(Request $request, ActionProposalStore $actions): array
    {
        if ($this->draft !== null) {
            return ['prepared' => true];
        }

        $args = $request->validate([
            'resource' => 'required|string|max:150',
            'record_id' => 'nullable|string|max:100',
            'operations' => 'required|array|list|min:2|max:5',
            'operations.*' => 'required|string|max:150|distinct:strict',
        ]);
        $resource = $args['resource'];
        $recordId = $args['record_id'] ?? null;
        $selection = $recordId === null && ($this->selection['resource'] ?? null) === $resource ? $this->selection : null;
        $kind = $recordId !== null ? 'record' : ($selection === null ? 'resource' : 'selection');
        $this->assertActionTarget($resource, $recordId, $kind);
        if ($this->waitsForChoice($resource, $recordId)) {
            return ['waiting_for_user' => true];
        }

        $this->reportProgress('checking_actions');
        $native = app(NativeResourceActions::class);
        $options = [];
        foreach ($actions->describeActions($resource, $recordId, $selection) as $action) {
            if (in_array($action['id'], $args['operations'], true) && $native->targetKind($action['id']) === $kind) {
                $options[] = ['id' => $action['id'], 'label' => $action['label']];
            }
        }

        if (count($options) < 2) {
            return ['error' => 'At least two available native actions for the same target are required.'];
        }

        $this->actionChoice = [
            'resource' => $resource,
            'record_id' => $recordId,
            'record_ids' => $selection === null ? [] : array_column($selection['records'], 'id'),
            'options' => $options,
        ];

        return ['waiting_for_user' => true];
    }

    private function assertActionTarget(string $resource, ?string $recordId, string $kind): void
    {
        if ($this->actionTarget !== null && ($this->actionTarget['resource'] !== $resource
            || $this->actionTarget['record_id'] !== $recordId || $this->actionTarget['kind'] !== $kind)) {
            throw ValidationException::withMessages(['operation' => __('filament-ai-assistant::assistant.errors.action_choice_target')]);
        }
    }

    /** @param array<string, string> $replace */
    private function reportProgress(string $stage, array $replace = []): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)(__('filament-ai-assistant::assistant.progress.'.$stage, $replace));
        }
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

    private function waitsForChoice(string $resource, ?string $recordId): bool
    {
        if ($this->recordChoice !== null || $this->actionChoice !== null) {
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

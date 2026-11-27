<?php

namespace Alareqi\FilamentAiAssistant\Tests;

use Alareqi\FilamentAiAssistant\ActionProposalStore;
use Alareqi\FilamentAiAssistant\AssistantActivity;
use Alareqi\FilamentAiAssistant\AssistantAgent;
use Alareqi\FilamentAiAssistant\AssistantChat;
use Alareqi\FilamentAiAssistant\CurrentRecordContext;
use Alareqi\FilamentAiAssistant\DraftStore;
use Alareqi\FilamentAiAssistant\FilamentAiAssistantPlugin;
use Alareqi\FilamentAiAssistant\FilamentAiAssistantServiceProvider;
use Alareqi\FilamentAiAssistant\ResourceRegistry;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\Table;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema as StorageSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request as ToolRequest;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

final class AssistantPackageTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            AiServiceProvider::class,
            FilamentAiAssistantServiceProvider::class,
            FixturePanelProvider::class,
        ];
    }

    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }

    protected function defineEnvironment($app): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'database.default' => 'testing',
            'auth.providers.users.model' => FixtureUser::class,
            'session.driver' => 'array',
            'cache.default' => 'array',
            'ai.providers.gemini.key' => 'fake-key',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        StorageSchema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('allowed');
        });
        StorageSchema::create('entries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->json('translations')->nullable();
            $table->string('secret_code')->nullable();
            $table->boolean('published')->default(false);
        });

        Gate::policy(Entry::class, EntryPolicy::class);
        $this->actingAs(FixtureUser::query()->create(['allowed' => true]));
        Filament::setCurrentPanel(Filament::getPanel('package-test'));
        Filament::bootCurrentPanel();
    }

    public function test_it_discovers_read_and_write_capabilities_from_the_current_panel(): void
    {
        $catalog = collect(app(ResourceRegistry::class)->catalog())->keyBy('id');

        $this->assertTrue($catalog['entries']['create']);
        $this->assertTrue($catalog['entries']['edit']);
        $this->assertFalse($catalog['read-only-entries']['create']);
        $this->assertFalse($catalog['read-only-entries']['edit']);

        $this->actingAs(FixtureUser::query()->create(['allowed' => false]));
        Livewire::test(AssistantChat::class)->assertForbidden();
    }

    public function test_chat_uses_the_instructions_configured_by_the_host(): void
    {
        $instructions = "Use the store's terminology.\nReply concisely.";
        config(['filament-ai-assistant.instructions' => $instructions]);
        AssistantAgent::fake(['Ready.'])->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->set('message', 'What can you do?')
            ->call('send')
            ->assertHasNoErrors();

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->instructions() === $instructions);
    }

    public function test_chat_uses_the_default_instructions_without_an_override(): void
    {
        $instructions = app(AssistantAgent::class)->instructions();

        $this->assertStringContainsString('Respond in the language of the user', $instructions);
        $this->assertStringContainsString('A draft is not a saved change.', $instructions);
    }

    public function test_the_chat_gives_the_agent_the_authorized_current_record_for_this_message(): void
    {
        $entry = Entry::query()->create(['name' => 'Current entry', 'secret_code' => 'private']);
        $token = app(CurrentRecordContext::class)->issue(EntryResource::class, $entry)['token'];
        AssistantAgent::fake(['Ready.', 'Ready.'])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', 'Help me edit this record')
            ->call('send', $token)
            ->assertHasNoErrors();

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) use ($entry): bool {
            $tool = collect($prompt->agent->tools())->first(fn ($tool): bool => $tool->name() === 'current_record');
            $record = $tool ? json_decode($tool->handle(new ToolRequest), true)['record'] : null;

            return $prompt->contains('this record') && $record === [
                'resource' => 'entries',
                'record_id' => (string) $entry->id,
                'resource_label' => 'entry',
                'record_label' => 'Current entry',
                'can_edit' => true,
            ];
        });

        $chat->set('message', 'This page has no record')->call('send')->assertHasNoErrors();
        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('no record')
            && ! collect($prompt->agent->tools())->contains(fn ($tool): bool => $tool->name() === 'current_record'));
    }

    public function test_the_chat_rejects_tampered_context_and_context_from_another_admin(): void
    {
        $entry = Entry::query()->create(['name' => 'Current entry']);
        $token = app(CurrentRecordContext::class)->issue(EntryResource::class, $entry)['token'];
        AssistantAgent::fake()->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->set('message', 'Edit this record')
            ->call('send', 'tampered-'.$token)
            ->assertHasErrors('message');

        $this->actingAs(FixtureUser::query()->create(['allowed' => true]));
        Livewire::test(AssistantChat::class)
            ->set('message', 'Edit this record')
            ->call('send', $token)
            ->assertHasErrors('message');

        AssistantAgent::assertNeverPrompted();
    }

    public function test_record_context_is_rechecked_when_resource_access_changes(): void
    {
        $entry = Entry::query()->create(['name' => 'Current entry']);
        $token = app(CurrentRecordContext::class)->issue(EntryResource::class, $entry)['token'];
        FilamentAiAssistantPlugin::current()->allowResourceUsing(fn (string $resource): bool => $resource !== EntryResource::class);
        AssistantAgent::fake()->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->set('message', 'Edit this record')
            ->call('send', $token)
            ->assertSee('This record is no longer available.')
            ->assertHasErrors('message');

        AssistantAgent::assertNeverPrompted();
    }

    public function test_chat_rejects_a_list_token_when_the_resource_is_no_longer_allowed_or_the_admin_changes(): void
    {
        $token = app(CurrentRecordContext::class)->issueList(EntryResource::class)['token'];
        $originalAdmin = Filament::auth()->user();
        AssistantAgent::fake()->preventStrayPrompts();

        $this->actingAs(FixtureUser::query()->create(['allowed' => true]));
        Livewire::test(AssistantChat::class)
            ->set('message', 'Find an entry')
            ->call('send', null, $token)
            ->assertHasErrors('message');

        $this->actingAs($originalAdmin);
        FilamentAiAssistantPlugin::current()->allowResourceUsing(fn (string $resource): bool => $resource !== EntryResource::class);
        Livewire::test(AssistantChat::class)
            ->set('message', 'Find an entry')
            ->call('send', null, $token)
            ->assertSee('This list is no longer available.')
            ->assertHasErrors('message');

        AssistantAgent::assertNeverPrompted();
    }

    public function test_a_panel_can_replace_the_default_quick_prompts_without_sending_a_message(): void
    {
        FilamentAiAssistantPlugin::current()->quickPrompts(fn (): array => [
            ['label' => 'Check inventory', 'prompt' => 'Help me check product inventory.'],
        ]);
        AssistantAgent::fake()->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->assertSee('Check inventory')
            ->assertSee('Help me check product inventory.')
            ->assertDontSee('What can I manage here?')
            ->assertSet('message', '');

        AssistantAgent::assertNeverPrompted();
    }

    public function test_each_panel_can_override_the_assistant_instructions(): void
    {
        config(['filament-ai-assistant.instructions' => 'Use the shared instructions.']);
        $defaultPanel = Filament::getPanel('package-test');
        $catalogPanel = Panel::make()->id('catalog-test')
            ->plugin(FilamentAiAssistantPlugin::make()
                ->authorizeUsing(fn (FixtureUser $user): bool => $user->allowed)
                ->instructions('Help with the catalog.'));
        $supportPanel = Panel::make()->id('support-test')
            ->plugin(FilamentAiAssistantPlugin::make()
                ->authorizeUsing(fn (FixtureUser $user): bool => $user->allowed)
                ->instructions(fn (): string => 'Help with customer support.'));
        AssistantAgent::fake(['Ready.', 'Ready.', 'Ready.'])->preventStrayPrompts();

        foreach ([
            [$catalogPanel, 'Help with the catalog.'],
            [$supportPanel, 'Help with customer support.'],
            [$defaultPanel, 'Use the shared instructions.'],
        ] as [$panel, $instructions]) {
            Filament::setCurrentPanel($panel);
            $question = 'What can you do on '.$panel->getId().'?';

            Livewire::test(AssistantChat::class)
                ->set('message', $question)
                ->call('send')
                ->assertHasNoErrors();

            AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains($question)
                && $prompt->agent->instructions() === $instructions);
        }
    }

    public function test_panels_can_enable_or_disable_live_progress_independently_of_the_default(): void
    {
        $defaultPanel = Filament::getCurrentPanel();
        $enabledPanel = Panel::make()->id('progress-enabled')
            ->plugin(FilamentAiAssistantPlugin::make()
                ->authorizeUsing(fn (FixtureUser $user): bool => $user->allowed)
                ->liveProgress());
        $disabledPanel = Panel::make()->id('progress-disabled')
            ->plugin(FilamentAiAssistantPlugin::make()
                ->authorizeUsing(fn (FixtureUser $user): bool => $user->allowed)
                ->liveProgress(fn (): bool => false));

        config(['filament-ai-assistant.live_progress' => false]);
        Filament::setCurrentPanel($defaultPanel);
        Livewire::test(AssistantChat::class)->assertDontSeeHtml('wire:stream="fia-progress"');

        Filament::setCurrentPanel($enabledPanel);
        Livewire::test(AssistantChat::class)->assertSeeHtml('wire:stream="fia-progress"');

        config(['filament-ai-assistant.live_progress' => true]);
        Filament::setCurrentPanel($disabledPanel);
        Livewire::test(AssistantChat::class)
            ->assertDontSeeHtml('wire:stream="fia-progress"')
            ->assertSee('Preparing a response…');

        Filament::setCurrentPanel($defaultPanel);
        Livewire::test(AssistantChat::class)->assertSeeHtml('wire:stream="fia-progress"');
    }

    public function test_chat_prepares_a_draft_and_the_unmodified_native_page_validates_and_saves_it(): void
    {
        $entry = Entry::query()->create(['name' => 'Before', 'secret_code' => 'do-not-expose']);
        AssistantAgent::fake([
            new ToolCall('find', 'search_records', ['resource' => 'entries', 'query' => 'Before']),
            new ToolCall('describe', 'describe_form', ['resource' => 'entries', 'operation' => 'edit', 'record_id' => (string) $entry->id]),
            new ToolCall('prepare', 'prepare_change', [
                'resource' => 'entries',
                'operation' => 'edit',
                'record_id' => (string) $entry->id,
                'changes' => [['field' => 'name', 'value' => 'After']],
            ]),
            'Draft prepared',
        ])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', 'Rename Before to After')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Open review form');

        $draft = $chat->get('draft');
        $this->assertSame('Before', $entry->fresh()->name);
        $this->assertStringNotContainsString('do-not-expose', json_encode($draft));

        Livewire::test(AssistantChat::class)
            ->assertSee('Rename Before to After')
            ->assertSee('Open review form')
            ->assertSetStrict('draft', $draft);

        Livewire::withQueryParams(['assistantDraft' => $this->draftId($draft)])
            ->test(EditEntry::class, ['record' => $entry->id])
            ->assertFormSet(['name' => 'After'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('After', $entry->fresh()->name);
        $this->assertSame('do-not-expose', $entry->fresh()->secret_code);

        Livewire::test(AssistantChat::class)
            ->assertSee('Rename Before to After')
            ->assertDontSee('Open review form');
    }

    public function test_chat_pauses_when_a_draft_targets_ambiguous_search_results_then_resumes_for_the_chosen_record(): void
    {
        $first = Entry::query()->create(['name' => 'Shared name A']);
        $second = Entry::query()->create(['name' => 'Shared name B']);
        $other = Entry::query()->create(['name' => 'Unrelated']);
        $message = 'Rename Shared name to New name';
        $prepare = fn (Entry $entry): ToolCall => new ToolCall('prepare-'.$entry->id, 'prepare_change', [
            'resource' => 'entries',
            'operation' => 'edit',
            'record_id' => (string) $entry->id,
            'changes' => [['field' => 'name', 'value' => 'New name']],
        ]);

        AssistantAgent::fake([
            new ToolCall('find', 'search_records', ['resource' => 'entries', 'query' => 'Shared name']),
            $prepare($first),
            'Draft prepared',
            new ToolCall('chosen', 'current_record', []),
            $prepare($first),
            $prepare($second),
            'Draft prepared',
        ])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', $message)
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Choose the correct record')
            ->assertSee('Shared name A')
            ->assertSee('Shared name B')
            ->assertDontSee('Unrelated')
            ->assertSet('draft', null);
        $choiceId = $chat->get('recordChoice')['id'];

        Livewire::test(AssistantChat::class)
            ->assertSee('Choose the correct record')
            ->call('chooseRecord', 'stale-choice', (string) $second->id)
            ->assertHasErrors('message')
            ->call('chooseRecord', $choiceId, (string) $other->id)
            ->assertHasErrors('message')
            ->set('message', 'Delete something else')
            ->call('send')
            ->assertSee('Choose a record or cancel')
            ->assertSet('draft', null)
            ->call('chooseRecord', $choiceId, (string) $second->id)
            ->assertHasNoErrors()
            ->assertSet('recordChoice', null)
            ->assertSee('Open review form')
            ->assertSee('Selected Shared name B')
            ->assertSeeHtml('/entries/'.$second->id.'/edit?assistantDraft=');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) use ($message, $second): bool {
            $tool = collect($prompt->agent->tools())->first(fn ($tool): bool => $tool->name() === 'current_record');
            $record = $tool ? json_decode($tool->handle(new ToolRequest), true)['record'] : null;

            return $prompt->contains($message) && ($record['record_id'] ?? null) === (string) $second->id;
        });
        $this->assertSame('Shared name A', $first->fresh()->name);
        $this->assertSame('Shared name B', $second->fresh()->name);
    }

    public function test_chat_can_ask_a_record_question_and_cancel_it_if_resource_access_is_revoked(): void
    {
        Entry::query()->create(['name' => 'Shared name A']);
        $second = Entry::query()->create(['name' => 'Shared name B']);
        AssistantAgent::fake([
            new ToolCall('find', 'search_records', ['resource' => 'entries', 'query' => 'Shared name']),
            new ToolCall('clarify', 'ask_record_choice', ['resource' => 'entries']),
            new ToolCall('delete', 'prepare_action', ['resource' => 'entries', 'operation' => 'delete', 'record_id' => (string) $second->id]),
            'Which one?',
        ])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', 'Delete Shared name')
            ->call('send')
            ->assertSee('Choose the correct record')
            ->assertSet('draft', null);
        $choiceId = $chat->get('recordChoice')['id'];

        FilamentAiAssistantPlugin::current()->allowResourceUsing(fn (string $resource): bool => $resource !== EntryResource::class);

        $chat->call('chooseRecord', $choiceId, (string) $second->id)
            ->assertSee('This record is no longer available.')
            ->call('cancelRecordChoice', $choiceId)
            ->assertHasNoErrors()
            ->assertSet('recordChoice', null)
            ->assertSee('Request cancelled. No changes were made.');

        Livewire::test(AssistantChat::class)
            ->call('chooseRecord', $choiceId, (string) $second->id)
            ->assertHasErrors('message')
            ->assertSet('draft', null);
        $this->assertNotNull($second->fresh());
    }

    public function test_single_record_search_requests_the_choice_tool_for_multiple_matches(): void
    {
        Entry::query()->create(['name' => 'Shared name A']);
        Entry::query()->create(['name' => 'Shared name B']);
        $agent = app(AssistantAgent::class);
        $search = collect($agent->tools())->first(fn ($tool): bool => $tool->name() === 'search_records');

        $result = json_decode($search->handle(new ToolRequest([
            'resource' => 'entries', 'query' => 'Shared name', 'purpose' => 'single_record',
        ])), true);

        $this->assertSame([
            'name' => 'ask_record_choice',
            'arguments' => ['resource' => 'entries'],
        ], $result['next_tool']);
    }

    public function test_chat_shows_buttons_if_the_model_replies_in_text_after_an_ambiguous_single_record_search(): void
    {
        $first = Entry::query()->create(['name' => 'ايمن الأول']);
        $second = Entry::query()->create(['name' => 'ايمن الثاني']);
        app()->setLocale('ar');
        AssistantAgent::fake([
            new ToolCall('find', 'search_records', ['resource' => 'entries', 'query' => 'ايمن', 'purpose' => 'single_record']),
            'أرسل اسم السائق الذي تقصده.',
        ])->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->set('message', 'هل يمكن حذف السائق ايمن؟')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('اختر السجل المقصود')
            ->assertSee($first->name)
            ->assertSee($second->name)
            ->assertDontSee('أرسل اسم السائق الذي تقصده.')
            ->assertSet('draft', null);

        $this->assertModelExists($first);
        $this->assertModelExists($second);
    }

    public function test_search_can_return_multiple_records_without_asking_the_user_to_choose(): void
    {
        Entry::query()->create(['name' => 'Shared name A']);
        Entry::query()->create(['name' => 'Shared name B']);
        AssistantAgent::fake([
            new ToolCall('find', 'search_records', ['resource' => 'entries', 'query' => 'Shared name', 'purpose' => 'browse']),
            'I found Shared name A and Shared name B.',
        ])->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->set('message', 'List records named Shared name')
            ->call('send')
            ->assertSee('I found Shared name A and Shared name B.')
            ->assertSet('recordChoice', null)
            ->assertSet('draft', null);
    }

    public function test_activity_records_the_saved_draft_record_even_when_creating_another(): void
    {
        $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
        FilamentAiAssistantPlugin::current()->withActivity(fn (): bool => true);
        $draft = app(DraftStore::class)->prepare('entries', 'create', null, [['field' => 'name', 'value' => 'Test draft value']]);

        Livewire::withQueryParams(['assistantDraft' => $draft['id']])->test(CreateEntry::class)
            ->call('create', true)
            ->assertHasNoFormErrors();

        $record = Entry::query()->sole();
        $activity = AssistantActivity::query()->sole();
        $this->assertSame('draft:create', $activity->operation);
        $this->assertSame(AssistantActivity::SUCCEEDED, $activity->outcome);
        $this->assertSame([(string) $record->getKey()], $activity->record_ids);
        $this->assertStringNotContainsString('Test draft value', $activity->toJson());
    }

    public function test_activity_uses_the_native_action_failure_status_without_logging_form_data(): void
    {
        $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
        config(['assistant-test.native-action-fixtures' => true]);
        FilamentAiAssistantPlugin::current()
            ->withActivity(fn (): bool => true)
            ->actionsFor(EntryResource::class, ['index:failure']);
        $proposal = app(ActionProposalStore::class)->prepare('entries', 'index:failure', null);

        Livewire::withQueryParams(['assistantAction' => $proposal['id']])->test(ListEntries::class)
            ->set('mountedActions.0.data.note', 'Private data')
            ->call('callMountedAction')
            ->assertOk();

        $activity = AssistantActivity::query()->sole();
        $this->assertSame('index:failure', $activity->operation);
        $this->assertSame(AssistantActivity::FAILED, $activity->outcome);
        $this->assertStringNotContainsString('Private data', $activity->toJson());
    }

    public function test_activity_records_failed_confirmations_without_storing_the_exception_text(): void
    {
        $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
        config(['assistant-test.native-action-fixtures' => true]);
        FilamentAiAssistantPlugin::current()
            ->withActivity(fn (): bool => true)
            ->actionsFor(EntryResource::class, ['index:exception']);
        $proposal = app(ActionProposalStore::class)->prepare('entries', 'index:exception', null);
        $this->withoutExceptionHandling();

        try {
            Livewire::withQueryParams(['assistantAction' => $proposal['id']])->test(ListEntries::class)
                ->call('callMountedAction');
            $this->fail('Expected the native action to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Private internal detail', $exception->getMessage());
        }

        $activity = AssistantActivity::query()->sole();
        $this->assertSame('index:exception', $activity->operation);
        $this->assertSame(AssistantActivity::FAILED, $activity->outcome);
        $this->assertStringNotContainsString('Private internal detail', $activity->toJson());
    }

    public function test_progress_reports_actual_tool_steps_in_the_panel_language_without_record_data(): void
    {
        $entry = Entry::query()->create(['name' => 'Private record name']);
        $selection = app(ActionProposalStore::class)->selectionFor('entries', [(string) $entry->id]);
        $progress = [];
        app()->setLocale('ar');
        AssistantAgent::fake([
            new ToolCall('find', 'search_records', ['query' => $entry->name, 'purpose' => 'browse']),
            new ToolCall('list', 'list_actions', ['resource' => 'entries']),
            new ToolCall('stage', 'prepare_action', ['resource' => 'entries', 'operation' => 'bulk:publish']),
            'Draft ready.',
        ])->preventStrayPrompts();
        $agent = new AssistantAgent(
            app(ResourceRegistry::class),
            app(DraftStore::class),
            currentList: ['resource' => 'entries', 'label' => 'السجلات'],
            selection: $selection,
            onProgress: function (string $message) use (&$progress): void {
                $progress[] = $message;
            },
        );

        $agent->prompt('Prepare the publish action.', provider: 'gemini');

        $this->assertSame([
            'جارٍ البحث في قائمة السجلات…',
            'جارٍ التحقق من الإجراءات المتاحة…',
            'جارٍ تجهيز الإجراء للمراجعة…',
        ], $progress);
        $this->assertNotNull($agent->draft());
        $this->assertFalse($entry->fresh()->published);
    }

    public function test_numeric_search_finds_exact_codes_and_database_ids(): void
    {
        $idMatch = Entry::query()->create(['id' => 123, 'name' => 'ID match']);
        $codeMatch = Entry::query()->create(['name' => 'Code match', 'code' => '123']);
        $leadingZeros = Entry::query()->create(['name' => 'Padded code', 'code' => '00123']);
        $longCode = Entry::query()->create(['name' => 'Long code', 'code' => '123456789012345678901']);
        $registry = app(ResourceRegistry::class);

        $this->assertEqualsCanonicalizing(['123', (string) $codeMatch->id], array_column($registry->search('entries', '123'), 'id'));
        $this->assertEqualsCanonicalizing([(string) $idMatch->id, (string) $leadingZeros->id], array_column($registry->search('entries', '00123'), 'id'));
        $this->assertSame([(string) $longCode->id], array_column($registry->search('entries', '123456789012345678901'), 'id'));
    }

    public function test_chat_validation_messages_are_localized_in_arabic(): void
    {
        app()->setLocale('ar');

        Livewire::test(AssistantChat::class)
            ->set('message', '   ')
            ->call('send')
            ->assertHasErrors(['message' => 'required'])
            ->assertSee('اكتب رسالة قبل الإرسال.')
            ->set('message', str_repeat('س', 2001))
            ->call('send')
            ->assertHasErrors(['message' => 'max'])
            ->assertSee('يجب ألا تتجاوز الرسالة 2000 حرف.');
    }

    public function test_chat_restores_messages_on_a_new_component_until_cleared(): void
    {
        AssistantAgent::fake(['The catalog is ready.'])->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->set('message', 'Check the catalog')
            ->call('send');

        Livewire::test(AssistantChat::class)
            ->assertSee('Check the catalog')
            ->assertSee('The catalog is ready.')
            ->call('clearChat');

        Livewire::test(AssistantChat::class)->assertSetStrict('history', []);
    }

    public function test_chat_formats_assistant_replies_and_hides_resource_slugs_after_reload(): void
    {
        AssistantAgent::fake(["يمكنني مساعدتك في **المنتجات (`entries`)**.\nمعلومة إضافية (اختياري)."])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', '**نص المستخدم**')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('**نص المستخدم**')
            ->assertSeeHtml('<strong>المنتجات</strong>')
            ->assertDontSee('(`entries`)')
            ->assertSee('(اختياري)');

        $this->assertStringContainsString('(`entries`)', $chat->get('history')[1]['text']);

        Livewire::test(AssistantChat::class)->assertSeeHtml('<strong>المنتجات</strong>');
    }

    public function test_formatted_replies_do_not_render_raw_html_images_or_links(): void
    {
        AssistantAgent::fake([
            'هذه **إجابة** <span onclick="alert(1)">مع نص</span> ![صورة](https://example.test/image) [رابط](https://example.test/page).',
        ])->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->set('message', 'Test formatting')
            ->call('send')
            ->assertSeeHtml('<strong>إجابة</strong>')
            ->assertSee('مع نص')
            ->assertSee('رابط')
            ->assertDontSeeHtml('onclick="alert(1)"')
            ->assertDontSeeHtml('<img')
            ->assertDontSeeHtml('href="https://example.test/page"');
    }

    public function test_a_different_authorized_account_cannot_restore_or_update_the_chat(): void
    {
        AssistantAgent::fake(['This is only for the first account.'])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', 'Check the catalog')
            ->call('send');

        $this->actingAs(FixtureUser::query()->create(['allowed' => true]));

        Livewire::test(AssistantChat::class)->assertSetStrict('history', []);
        $chat->call('clearChat')->assertForbidden();
    }

    public function test_a_create_draft_does_not_write_until_native_create_is_clicked(): void
    {
        $draft = app(DraftStore::class)->prepare('entries', 'create', null, [['field' => 'name', 'value' => 'News']]);

        $this->assertDatabaseCount('entries', 0);
        Livewire::withQueryParams(['assistantDraft' => $this->draftId($draft)])
            ->test(CreateEntry::class)
            ->assertFormSet(['name' => 'News'])
            ->set('data.name', '')
            ->call('create')->assertHasFormErrors(['name' => 'required'])
            ->set('data.name', 'News')
            ->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('entries', ['name' => 'News']);
    }

    public function test_a_create_draft_audits_the_saved_translated_field(): void
    {
        Log::spy();
        $draft = app(DraftStore::class)->prepare('entries', 'create', null, [
            ['field' => 'name', 'value' => 'News'],
            ['field' => 'translations.ar', 'value' => 'أخبار'],
        ]);

        Livewire::withQueryParams(['assistantDraft' => $this->draftId($draft)])
            ->test(CreateEntry::class)
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('أخبار', Entry::sole()->translations['ar']);
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Filament AI assistant draft reviewed and saved.'
            && $context['operation'] === 'create' && $context['saved_fields'] === ['name', 'translations.ar']);
    }

    public function test_an_edit_draft_only_audits_the_saved_translations_that_actually_changed(): void
    {
        $entry = Entry::query()->create(['name' => 'Before', 'translations' => ['ar' => 'قبل', 'en' => 'Before']]);
        Log::spy();
        $draft = app(DraftStore::class)->prepare('entries', 'edit', (string) $entry->id, [
            ['field' => 'translations.ar', 'value' => 'بعد'],
            ['field' => 'translations.en', 'value' => 'After'],
            ['field' => 'name', 'value' => 'Renamed'],
        ]);

        Livewire::withQueryParams(['assistantDraft' => $this->draftId($draft)])
            ->test(EditEntry::class, ['record' => $entry->id])
            ->set('data.translations.en', 'Before')
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(['ar' => 'بعد', 'en' => 'Before'], $entry->fresh()->translations);
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Filament AI assistant draft reviewed and saved.'
            && $context['operation'] === 'edit' && $context['saved_fields'] === ['translations.ar', 'name']);
    }

    public function test_it_rejects_a_draft_if_the_record_changed_after_it_was_opened(): void
    {
        $entry = Entry::query()->create(['name' => 'Before']);
        $draft = app(DraftStore::class)->prepare('entries', 'edit', (string) $entry->id, [['field' => 'name', 'value' => 'Proposed']]);

        $form = Livewire::withQueryParams(['assistantDraft' => $this->draftId($draft)])
            ->test(EditEntry::class, ['record' => $entry->id])
            ->assertFormSet(['name' => 'Proposed']);

        $entry->update(['name' => 'Concurrent edit']);
        $form->call('save')->assertStatus(409);
        $this->assertSame('Concurrent edit', $entry->fresh()->name);
    }

    public function test_drafts_are_bound_to_the_chosen_record(): void
    {
        $entry = Entry::query()->create(['name' => 'Original']);
        $other = Entry::query()->create(['name' => 'Other']);
        $draft = app(DraftStore::class)->prepare('entries', 'edit', (string) $entry->id, [['field' => 'name', 'value' => 'Proposed']]);

        Livewire::withQueryParams(['assistantDraft' => $this->draftId($draft)])
            ->test(EditEntry::class, ['record' => $other->id])
            ->assertForbidden();
    }

    public function test_a_different_authorized_user_cannot_open_someone_elses_draft(): void
    {
        $draft = app(DraftStore::class)->prepare('entries', 'create', null, [['field' => 'name', 'value' => 'News']]);
        $this->actingAs(FixtureUser::query()->create(['allowed' => true]));

        Livewire::withQueryParams(['assistantDraft' => $this->draftId($draft)])
            ->test(CreateEntry::class)
            ->assertForbidden();
        $this->assertDatabaseCount('entries', 0);
    }

    public function test_it_excludes_secret_fields_and_rejects_unsupported_patches(): void
    {
        $entry = Entry::query()->create(['name' => 'Safe', 'secret_code' => 'do-not-expose']);
        $registry = app(ResourceRegistry::class);
        $form = $registry->form(EntryResource::class, 'edit', (string) $entry->id)['form'];
        $this->assertSame(['name', 'translations.ar', 'translations.en'], array_column($registry->describe($form), 'key'));

        $this->expectException(ValidationException::class);
        app(DraftStore::class)->prepare('entries', 'edit', (string) $entry->id, [['field' => 'secret_code', 'value' => 'Replaced']]);
    }

    private function draftId(array $draft): string
    {
        parse_str(parse_url($draft['url'], PHP_URL_QUERY), $parameters);

        return $parameters['assistantDraft'];
    }

    public function test_action_choice_only_offers_available_toolbar_actions_and_prepares_the_exact_picked_action(): void
    {
        $first = Entry::query()->create(['name' => 'First']);
        $second = Entry::query()->create(['name' => 'Second']);
        $ids = [(string) $first->id, (string) $second->id];
        $token = app(CurrentRecordContext::class)->issueList(EntryResource::class)['token'];
        AssistantAgent::fake([
            new ToolCall('list', 'list_actions', ['resource' => 'entries']),
            new ToolCall('ask', 'ask_action_choice', [
                'resource' => 'entries', 'operations' => ['bulk:publish', 'bulk:unpublish', 'bulk:missing'],
            ]),
            new ToolCall('premature', 'prepare_action', ['resource' => 'entries', 'operation' => 'bulk:publish']),
            'Which action?',
        ])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', 'Change the status of the checked entries')
            ->call('send', null, $token, $ids)
            ->assertSee('Choose an action')
            ->assertSee('Or type your answer…')
            ->assertSet('draft', null);
        $choice = $chat->get('actionChoice');
        $this->assertSame(['bulk:publish', 'bulk:unpublish'], array_column($choice['options'], 'id'));

        $resumed = Livewire::test(AssistantChat::class)
            ->assertSee('Choose an action')
            ->call('chooseAction', $choice['id'], 'bulk:missing')->assertHasErrors('message')
            ->call('chooseAction', $choice['id'], 'bulk:publish')
            ->assertHasNoErrors()
            ->assertSet('actionChoice', null)
            ->assertSee('Review action');

        $this->assertSame('bulk:publish', $resumed->get('draft')['operation']);
        $this->assertSame($ids, array_column($resumed->get('draft')['records'], 'id'));
        $this->assertFalse($first->fresh()->published);
        $this->assertFalse($second->fresh()->published);
    }

    public function test_typed_action_clarification_keeps_the_original_selection_and_cannot_change_its_scope(): void
    {
        $entry = Entry::query()->create(['name' => 'Checked', 'published' => true]);
        $token = app(CurrentRecordContext::class)->issueList(EntryResource::class)['token'];
        AssistantAgent::fake([
            new ToolCall('ask', 'ask_action_choice', ['resource' => 'entries', 'operations' => ['bulk:publish', 'bulk:unpublish']]),
            'Which action?',
            new ToolCall('wrong-target', 'prepare_action', [
                'resource' => 'entries', 'operation' => 'edit:delete', 'record_id' => (string) $entry->id,
            ]),
            new ToolCall('correct-target', 'prepare_action', ['resource' => 'entries', 'operation' => 'bulk:unpublish']),
            'Draft prepared',
        ])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', 'Change the status of the checked entry')
            ->call('send', null, $token, [(string) $entry->id])
            ->assertSee('Choose an action');

        $chat->set('message', 'Please hide it from the catalog')
            ->call('answerActionChoice', $chat->get('actionChoice')['id'])
            ->assertHasNoErrors()
            ->assertSet('actionChoice', null)
            ->assertSee('Review action');
        $this->assertSame('bulk:unpublish', $chat->get('draft')['operation']);
        $this->assertTrue($entry->fresh()->published);
    }

    public function test_a_pending_action_choice_rechecks_availability_and_can_be_cancelled(): void
    {
        $entry = Entry::query()->create(['name' => 'Checked']);
        $token = app(CurrentRecordContext::class)->issueList(EntryResource::class)['token'];
        AssistantAgent::fake([
            new ToolCall('ask', 'ask_action_choice', ['resource' => 'entries', 'operations' => ['bulk:publish', 'bulk:unpublish']]),
            'Which action?',
        ])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', 'Change the checked entry')
            ->call('send', null, $token, [(string) $entry->id]);
        $choiceId = $chat->get('actionChoice')['id'];
        FilamentAiAssistantPlugin::current()->allowActionUsing(fn (Action $action): bool => $action->getName() !== 'publish');

        $chat->call('chooseAction', $choiceId, 'bulk:publish')
            ->assertHasErrors('message')
            ->assertSet('draft', null)
            ->assertSee('Choose an action')
            ->call('cancelActionChoice', $choiceId)
            ->assertHasNoErrors()
            ->assertSet('actionChoice', null)
            ->assertSee('Request cancelled. No changes were made.');
        $this->assertFalse($entry->fresh()->published);
    }

    public function test_assistant_bulk_actions_only_apply_to_the_explicit_selection_after_native_confirmation(): void
    {
        $one = Entry::query()->create(['name' => 'First item']);
        $two = Entry::query()->create(['name' => 'Second item']);
        $other = Entry::query()->create(['name' => 'Not selected']);
        $token = app(CurrentRecordContext::class)->issueList(EntryResource::class)['token'];

        foreach (['publish' => true, 'unpublish' => false] as $operation => $published) {
            AssistantAgent::fake([
                new ToolCall('prepare', 'prepare_action', ['resource' => 'entries', 'operation' => $operation]),
                'Action proposed',
            ])->preventStrayPrompts();

            $proposal = Livewire::withQueryParams([])->test(AssistantChat::class)
                ->set('message', 'Please '.$operation.' the selected entries')
                ->call('send', null, $token, [(string) $one->id, (string) $two->id])
                ->assertHasNoErrors()
                ->assertSee('Review action')
                ->assertSee('First item')
                ->assertSee('Second item')
                ->get('draft');

            $this->assertSame([$one->id, $two->id], array_map('intval', array_column($proposal['records'], 'id')));
            $this->assertSame(! $published, $one->fresh()->published);

            $review = Livewire::withQueryParams(['assistantAction' => $proposal['id']])->test(ListEntries::class);
            $this->assertSame($operation, $review->instance()->getMountedAction()?->getName());
            $this->assertSame(! $published, $two->fresh()->published);
            $review->call('callMountedAction')->assertOk();

            $this->assertSame($published, $one->fresh()->published);
            $this->assertSame($published, $two->fresh()->published);
            $this->assertFalse($other->fresh()->published);
            $this->assertFalse(app(ActionProposalStore::class)->isPending($proposal['id']));
        }
    }

    public function test_assistant_single_delete_uses_the_existing_confirmation_and_does_not_run_on_open(): void
    {
        $entry = Entry::query()->create(['name' => 'Delete target']);
        $other = Entry::query()->create(['name' => 'Other record']);
        $proposal = app(ActionProposalStore::class)->prepare('entries', 'delete', (string) $entry->id);

        Livewire::withQueryParams(['assistantAction' => $proposal['id']])
            ->test(EditEntry::class, ['record' => $other->id])->assertForbidden();

        $review = Livewire::withQueryParams(['assistantAction' => $proposal['id']])
            ->test(EditEntry::class, ['record' => $entry->id]);

        $this->assertModelExists($entry);
        $this->assertSame('delete', $review->instance()->getMountedAction()?->getName());
        $review->call('callMountedAction')->assertOk();
        $this->assertModelMissing($entry);
        $this->assertModelExists($other);
    }

    public function test_native_row_action_opens_its_form_and_can_be_retried_after_native_validation(): void
    {
        config(['assistant-test.native-action-fixtures' => true]);
        FilamentAiAssistantPlugin::current()->actionsFor(EntryResource::class, ['row:rename']);
        $entry = Entry::query()->create(['name' => 'Original']);
        AssistantAgent::fake([
            new ToolCall('list', 'list_actions', ['resource' => 'entries', 'record_id' => (string) $entry->id]),
            new ToolCall('prepare', 'prepare_action', ['resource' => 'entries', 'operation' => 'row:rename', 'record_id' => (string) $entry->id]),
            'Action proposed',
        ])->preventStrayPrompts();

        $draft = Livewire::test(AssistantChat::class)
            ->set('message', 'Open the Rename entry action')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Rename entry')
            ->get('draft');

        $review = Livewire::withQueryParams(['assistantAction' => $draft['id']])->test(ListEntries::class)->assertOk();
        $this->assertSame('rename', $review->instance()->getMountedAction()?->getName());
        $this->assertSame('Original', $entry->fresh()->name);

        $review->call('callMountedAction')->assertHasErrors(['mountedActions.0.data.name' => 'required']);
        $this->assertTrue(app(ActionProposalStore::class)->isPending($draft['id']));
        $review->set('mountedActions.0.data.name', 'Renamed')
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $this->assertSame('Renamed', $entry->fresh()->name);
        $this->assertFalse(app(ActionProposalStore::class)->isPending($draft['id']));
    }

    public function test_discovery_only_lists_native_actions_that_can_be_reviewed(): void
    {
        config(['assistant-test.native-action-fixtures' => true]);
        FilamentAiAssistantPlugin::current()->actionsFor(EntryResource::class, [
            'row:rename', 'row:hidden', 'row:denied', 'row:immediate', 'row:link', 'index:notice', 'bulk:publish',
        ]);
        $entry = Entry::query()->create(['name' => 'Original']);
        $store = app(ActionProposalStore::class);
        $selection = $store->selectionFor('entries', [(string) $entry->id]);

        $actions = collect($store->describeActions('entries', (string) $entry->id, $selection))->keyBy('id');

        $this->assertEqualsCanonicalizing(['row:rename', 'index:notice', 'bulk:publish'], $actions->keys()->all());
        $this->assertTrue($actions['row:rename']['input']);
        $this->assertFalse($actions['index:notice']['input']);
        $this->assertSame('Rename entry', $actions['row:rename']['label']);

        $this->expectException(ValidationException::class);
        $store->prepare('entries', 'row:immediate', (string) $entry->id);
    }

    public function test_a_checked_row_is_current_record_for_that_message_only(): void
    {
        $entry = Entry::query()->create(['name' => 'Checked row']);
        $token = app(CurrentRecordContext::class)->issueList(EntryResource::class)['token'];
        AssistantAgent::fake(['Found.', 'Not selected.'])->preventStrayPrompts();

        $chat = Livewire::test(AssistantChat::class)
            ->set('message', 'Which record is checked?')
            ->call('send', null, $token, [(string) $entry->id])
            ->assertHasNoErrors()
            ->set('message', 'Is there still a selection?')
            ->call('send', null, $token, [])
            ->assertHasNoErrors();

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) use ($entry): bool {
            if (! $prompt->contains('Which record')) {
                return false;
            }

            $tool = collect($prompt->agent->tools())->first(fn ($tool): bool => $tool->name() === 'current_record');

            return $tool !== null && json_decode($tool->handle(new ToolRequest), true)['record']['record_id'] === (string) $entry->id;
        });
        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('still a selection')
            && ! collect($prompt->agent->tools())->contains(fn ($tool): bool => $tool->name() === 'current_record'));
        $chat->assertSet('draft', null);
    }

    public function test_the_assistant_cannot_guess_one_of_several_checked_rows(): void
    {
        $first = Entry::query()->create(['name' => 'Blue entry']);
        $second = Entry::query()->create(['name' => 'Green entry']);
        $token = app(CurrentRecordContext::class)->issueList(EntryResource::class)['token'];
        AssistantAgent::fake([
            new ToolCall('prepare', 'prepare_change', [
                'resource' => 'entries',
                'operation' => 'edit',
                'record_id' => (string) $first->id,
                'changes' => [['field' => 'name', 'value' => 'New name']],
            ]),
            'Draft prepared',
        ])->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->set('message', 'Rename this entry')
            ->call('send', null, $token, [(string) $first->id, (string) $second->id])
            ->assertHasNoErrors()
            ->assertSee('Choose the correct record')
            ->assertSee('Blue entry')
            ->assertSee('Green entry')
            ->assertSet('draft', null);
    }

    public function test_chat_rejects_select_all_and_nonexistent_checked_ids_before_prompting(): void
    {
        $token = app(CurrentRecordContext::class)->issueList(EntryResource::class)['token'];
        AssistantAgent::fake()->preventStrayPrompts();

        Livewire::test(AssistantChat::class)
            ->set('message', 'Publish the checked rows')
            ->call('send', null, $token, [], true)
            ->assertSee('Selecting all matching records is not supported.')
            ->call('send', null, $token, ['9999'])
            ->assertHasErrors('message')
            ->assertSet('draft', null);

        AssistantAgent::assertNeverPrompted();
    }

    public function test_assistant_rejects_changed_records_and_tampered_bulk_selections(): void
    {
        $entry = Entry::query()->create(['name' => 'Selected']);
        $other = Entry::query()->create(['name' => 'Other']);
        $store = app(ActionProposalStore::class);
        $selection = $store->selectionFor('entries', [(string) $entry->id]);
        $proposal = $store->prepare('entries', 'publish', null, $selection);

        Livewire::withQueryParams(['assistantAction' => $proposal['id']])
            ->test(ListEntries::class)->set('selectedTableRecords', [(string) $other->id])
            ->call('callMountedAction')->assertStatus(409);
        $this->assertFalse($other->fresh()->published);

        $review = Livewire::withQueryParams(['assistantAction' => $proposal['id']])->test(ListEntries::class);
        $entry->update(['name' => 'Changed']);
        $review->call('callMountedAction')->assertStatus(409);
        $this->assertFalse($entry->fresh()->published);
    }

    public function test_explicit_action_allowlists_can_restrict_native_bulk_delete_and_keep_its_description(): void
    {
        $entry = Entry::query()->create(['name' => 'Selected']);
        $store = app(ActionProposalStore::class);

        $selection = $store->selectionFor('entries', [(string) $entry->id]);
        $this->assertNotContains('bulk:delete', array_column($store->describeActions('entries', null, $selection), 'id'));

        FilamentAiAssistantPlugin::current()->actionsFor(EntryResource::class, ['bulk_delete']);
        $proposal = $store->prepare('entries', 'bulk_delete', null, $selection);
        $this->assertSame('Native toolbar delete description.', $proposal['warning']);
        $review = Livewire::withQueryParams(['assistantAction' => $proposal['id']])->test(ListEntries::class);

        $this->assertModelExists($entry);
        $review->call('callMountedAction')->assertOk();
        $this->assertModelMissing($entry);
    }

    public function test_action_proposals_require_a_table_selection_and_are_bound_to_the_admin(): void
    {
        $entry = Entry::query()->create(['name' => 'Selected']);
        $store = app(ActionProposalStore::class);
        try {
            $store->prepare('entries', 'publish', null);
            $this->fail('A bulk proposal was prepared without a selection.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Check the specific rows', $exception->getMessage());
        }
        $proposal = $store->prepare('entries', 'delete', (string) $entry->id);

        $this->actingAs(FixtureUser::query()->create(['allowed' => true]));
        Livewire::withQueryParams(['assistantAction' => $proposal['id']])
            ->test(EditEntry::class, ['record' => $entry->id])->assertForbidden();
        $this->assertModelExists($entry);
    }

    public function test_confirmation_rechecks_record_permissions_and_an_expired_proposal_cannot_run(): void
    {
        $entry = Entry::query()->create(['name' => 'Selected']);
        $store = app(ActionProposalStore::class);
        $proposal = $store->prepare('entries', 'delete', (string) $entry->id);
        $review = Livewire::withQueryParams(['assistantAction' => $proposal['id']])
            ->test(EditEntry::class, ['record' => $entry->id]);

        config(['assistant-test.delete_denied' => $entry->id]);
        $review->call('callMountedAction')->assertForbidden();
        $this->assertModelExists($entry);

        config(['assistant-test.delete_denied' => null]);
        $this->travel(31)->minutes();
        Livewire::withQueryParams(['assistantAction' => $proposal['id']])
            ->test(EditEntry::class, ['record' => $entry->id])->assertStatus(410);
        $this->assertModelExists($entry);
    }
}

final class FixturePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('package-test')
            ->path('package-test')
            ->resources([EntryResource::class, ReadOnlyEntryResource::class])
            ->plugin(FilamentAiAssistantPlugin::make()
                ->actionsFor(EntryResource::class, ['publish', 'unpublish', 'delete'])
                ->authorizeUsing(fn (FixtureUser $user): bool => (bool) $user->allowed));
    }
}

final class FixtureUser extends User
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

final class Entry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['translations' => 'array', 'published' => 'boolean'];
}

final class EntryPolicy
{
    public function viewAny(FixtureUser $user): bool
    {
        return true;
    }

    public function view(FixtureUser $user, Entry $entry): bool
    {
        return true;
    }

    public function create(FixtureUser $user): bool
    {
        return (bool) $user->allowed;
    }

    public function update(FixtureUser $user, Entry $entry): bool
    {
        return (bool) $user->allowed;
    }

    public function delete(FixtureUser $user, Entry $entry): bool
    {
        return $user->allowed && config('assistant-test.delete_denied') !== $entry->id;
    }

    public function deleteAny(FixtureUser $user): bool
    {
        return (bool) $user->allowed;
    }
}

final class EntryResource extends Resource
{
    protected static ?string $model = Entry::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        $publishActions = collect(['publish' => true, 'unpublish' => false])
            ->map(fn (bool $published, string $name): BulkAction => BulkAction::make($name)
                ->requiresConfirmation()
                ->authorizeIndividualRecords('update')
                ->action(fn (Collection $records) => $records->each->update(['published' => $published])))
            ->values()->all();

        if (config('assistant-test.native-action-fixtures')) {
            $table->recordActions([
                ActionGroup::make([
                    Action::make('rename')->label('Rename entry')->authorize('update')
                        ->schema([TextInput::make('name')->required()])
                        ->action(fn (Entry $record, array $data): bool => $record->update(['name' => $data['name']])),
                    Action::make('hidden')->hidden()->requiresConfirmation()->action(fn () => null),
                    Action::make('denied')->authorize(false)->requiresConfirmation()->action(fn () => null),
                    Action::make('immediate')->action(fn () => null),
                    Action::make('link')->url('/elsewhere')->requiresConfirmation()->action(fn () => null),
                ]),
            ]);
        }

        return $table->toolbarActions([
            ...$publishActions,
            DeleteBulkAction::make()->authorizeIndividualRecords('delete')->modalDescription('Native toolbar delete description.'),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(60),
            TextInput::make('translations.ar'),
            TextInput::make('translations.en'),
            TextInput::make('secret_code')->password(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEntries::route('/'),
            'create' => CreateEntry::route('/create'),
            'edit' => EditEntry::route('/{record}/edit'),
        ];
    }
}

final class ReadOnlyEntryResource extends Resource
{
    protected static ?string $slug = 'read-only-entries';

    protected static ?string $model = Entry::class;
}

final class ListEntries extends ListRecords
{
    protected static string $resource = EntryResource::class;

    protected function getHeaderActions(): array
    {
        return config('assistant-test.native-action-fixtures')
            ? [
                Action::make('notice')->requiresConfirmation()->action(fn () => null),
                Action::make('failure')->schema([TextInput::make('note')->required()])
                    ->action(fn (Action $action) => $action->failure()),
                Action::make('exception')->requiresConfirmation()
                    ->action(fn () => throw new \RuntimeException('Private internal detail')),
            ]
            : [];
    }
}

final class CreateEntry extends CreateRecord
{
    protected static string $resource = EntryResource::class;
}

final class EditEntry extends EditRecord
{
    protected static string $resource = EntryResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

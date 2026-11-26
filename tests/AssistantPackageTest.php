<?php

namespace Alareqi\FilamentAiAssistant\Tests;

use Alareqi\FilamentAiAssistant\AssistantAgent;
use Alareqi\FilamentAiAssistant\AssistantChat;
use Alareqi\FilamentAiAssistant\DraftStore;
use Alareqi\FilamentAiAssistant\FilamentAiAssistantPlugin;
use Alareqi\FilamentAiAssistant\FilamentAiAssistantServiceProvider;
use Alareqi\FilamentAiAssistant\ResourceRegistry;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
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
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema as StorageSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
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
        $prompts = [];
        AssistantAgent::fake(function (AgentPrompt $prompt) use (&$prompts): string {
            $prompts[] = $prompt->agent->instructions();

            return 'Ready.';
        })->preventStrayPrompts();

        foreach ([$catalogPanel, $supportPanel, $defaultPanel] as $panel) {
            Filament::setCurrentPanel($panel);

            Livewire::test(AssistantChat::class)
                ->set('message', 'What can you do?')
                ->call('send')
                ->assertHasNoErrors();
        }

        $this->assertSame([
            'Help with the catalog.',
            'Help with customer support.',
            'Use the shared instructions.',
        ], $prompts);
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
}

final class FixturePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('package-test')
            ->path('package-test')
            ->resources([EntryResource::class, ReadOnlyEntryResource::class])
            ->plugin(FilamentAiAssistantPlugin::make()->authorizeUsing(fn (FixtureUser $user): bool => (bool) $user->allowed));
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

    protected $casts = ['translations' => 'array'];
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
}

final class EntryResource extends Resource
{
    protected static ?string $model = Entry::class;

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
}

final class CreateEntry extends CreateRecord
{
    protected static string $resource = EntryResource::class;
}

final class EditEntry extends EditRecord
{
    protected static string $resource = EntryResource::class;
}

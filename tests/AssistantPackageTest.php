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
use Illuminate\Support\Facades\Schema as StorageSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AiServiceProvider;
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
        $this->assertSame(['name'], array_column($registry->describe($form), 'key'));

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

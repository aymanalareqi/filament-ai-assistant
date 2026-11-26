# Filament AI Assistant

A reusable Filament 5 panel plugin for finding records and preparing create/edit drafts with the Laravel AI SDK. A draft opens the host application's existing resource form. The assistant never saves model records or invokes resource actions.

## Install in a local project

From the host application's directory:

```sh
composer config repositories.filament-ai-assistant path /path/to/filament-ai-assistant
composer require 'alareqi/filament-ai-assistant:@dev'
php artisan filament:assets
```

The package requires PHP 8.3+, Filament 5, and a Laravel version supported by the installed Laravel AI SDK. It does not add application tables. Use a server-side session driver, such as database, Redis, or file, for drafts.

Add the plugin to the panel provider:

```php
use Alareqi\FilamentAiAssistant\FilamentAiAssistantPlugin;

return $panel
    // Keep the panel's existing configuration.
    ->plugin(FilamentAiAssistantPlugin::make());
```

Define the `use-filament-ai-assistant` Gate ability in the host, or configure `authorizeUsing(...)` on the plugin. Access is denied when neither grants permission. Existing resource policies still apply. To narrow what is sent to the AI provider, use `allowResourceUsing(fn (string $resource): bool => ...)` or `allowFieldUsing(fn (string $resource, string $path): bool => ...)`.

Authorized users can open the assistant from the floating button in the panel. The plugin does not register a separate assistant page or navigation item.

Configure the Laravel AI SDK credentials in the host application. The defaults use Gemini and the SDK reads `GEMINI_API_KEY`:

```dotenv
GEMINI_API_KEY=
FILAMENT_AI_ASSISTANT_PROVIDER=gemini
FILAMENT_AI_ASSISTANT_MODEL=gemini-3.1-flash-lite
```

## Supported workflows

- Discovers resources registered on the current panel and respects their existing resource and record policies.
- Searches records by ID and common name or code fields.
- Inspects visible, writable fields on standard Filament create and edit pages. Text, basic numeric and date inputs, toggles, HTML rich-text fields, and non-relationship single-selects are supported.
- Prepares session-scoped drafts and opens the original form without changing its page class, validation, or save handler. Drafts expire after 30 minutes and cannot be applied to a different user, tenant, resource, or record.
- Relationship selects, uploads, repeaters, modal-only resources, and custom actions are not prepared automatically. Complete those fields or actions through the native panel.

The panel sends chat messages and limited authorized form context to the selected AI provider. Configure the resource and field filters for the data the provider should receive.

## Verify the package

```sh
composer install
composer test
```

The test suite uses a standalone Testbench panel and a fake AI provider.

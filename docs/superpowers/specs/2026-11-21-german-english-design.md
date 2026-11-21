# German and English — design

Date: 2026-11-21. Status: approved. Ticket: TOR-40. PRD (German): https://kb.balt.io/doc/mehrsprachigkeit-deutsch-und-englisch-FZmxnFWvog

## Intent

Tory should be usable by as many households as possible. Today everything is German: the interface, the texts the AI writes about items, the answers of the REST API and the MCP server. From now on Tory comes in German and English. Each person picks the language of their interface; the household picks once which language the AI describes items in.

Two languages are kept apart:

- **Interface language** belongs to a user: labels, notifications, statuses, dates, numbers, error messages in API and MCP answers.
- **Content language** belongs to the household: the language the AI writes an item's title, description and search terms in. It is one per household so keyword search finds every item.

They may differ: someone can use the English interface in a household that describes its items in German.

## Goals

- Every user can use Tory fully in German or English.
- A household can have the AI describe items in English, as well as it does in German today.
- Nothing changes for an existing installation unless someone changes a setting.

## Non-goals

- Translating existing content, all at once or automatically. Single items can be brought into the content language with „Neu generieren“.
- Several languages per item, or search across languages beyond what semantic search already does.
- A third language. The structure should make one mostly translation work.
- Developer and operator output: Artisan command output, eval reports, logs, exception messages that never reach a user.
- User input: location and spot names, spot codes, notes.

## Anti-goals

- An existing installation looks or captures differently after the update without anyone changing a setting.
- German text left in the English interface or the other way round, Filament's own texts, validation, status labels and JS messages included.
- German extraction gets worse than today.
- Changing the content language changes existing items or triggers AI calls.

## Approaches

1. **English source strings as keys, German in `lang/de.json` (chosen, PRD).** Code reads `__('Items')`; English is already the fallback locale. Code stays readable, a forgotten German translation shows up as an English word in the German interface, and a test checks that every key has a German translation. Filament ships its own `de` and `en` texts.
2. **Named keys in PHP files** (`__('items.plural')`), as Filament does internally. Rejected: inventing a key per text and editing two files is more work than it buys for two languages.
3. **A translation package** with its own UI. Rejected: a new dependency for two languages.

One branch and one pull request for everything (the user's call; splitting into three was offered).

## Decisions

### The `Language` enum

- `App\Enums\Language`, backed string enum with `German = 'de'` and `English = 'en'`, implementing `HasLabel`. Labels are endonyms and never translated: „Deutsch“, „English“. *(My call.)*
- It is used for both the interface language and the content language.

### Interface language

- **Storage:** nullable `users.locale` string, cast to `Language`, not fillable. The migration sets it to `de` for every existing user. `null` means "follow the browser". *(My call: storing nothing for new users gives exactly "browser language until chosen" without a write on invite acceptance or setup.)*
- **Resolution:** a middleware `App\Http\Middleware\SetLocale` sets `app()->setLocale()` in this order:
  1. the signed-in user's `locale`;
  2. the signed-in user's browser (`$request->getPreferredLanguage(['en', 'de'])`, `en` when the browser asks for neither) when their `locale` is `null`;
  3. without a signed-in user, the household's content language, so an existing German installation shows German guest pages (login, invite, OAuth consent) and German unauthenticated API answers whatever the browser says (the user's decision, resolving the conflict between "guest pages follow the browser" and the anti-goal);
  4. on a fresh installation, where no user exists yet, the browser, so `/setup` appears in the first administrator's language.

  It runs in the panel middleware after `AuthenticateSession`, on the OAuth consent page (`/oauth/authorize`), on the API group after `auth:sanctum` (the token endpoint runs it without a user) and on `/mcp` after `auth:api`. There is no `Accept-Language` support for signed-in API or MCP requests; they follow the token owner.
- `APP_LOCALE` stays `de`; it only matters where no request exists (queue jobs, Artisan).
- **Switch:** one entry in the user menu, next to Filament's theme switcher, labelled with the other language („English“ in German, „Deutsch“ in English) and a language icon. Choosing it saves `locale` on the user and reloads the current page. *(My call: with two languages a toggle needs one click and no submenu.)* The route is a panel-authenticated `POST /locale` like `/theme`, or a user-menu action if Filament 5 supports actions there; the builder picks whichever Filament supports cleanly.
- **Dates:** `AppServiceProvider` sets the display format per locale: `d.m.Y H:i` for German (unchanged), `j M Y, H:i` for English (`21 Nov 2026, 14:30`). The format is resolved when a table or schema is configured, after the middleware has run.
- **Numbers** follow the locale wherever Tory formats numbers itself (for example file sizes in messages); Filament formats its own.
- **HTML `lang`:** Filament follows the app locale. The two own documents with `<html lang="de">` (`mcp/authorize.blade.php`, `pdf/spot-labels.blade.php`) use `app()->getLocale()`.

### Strings

- Every user-facing string in `app/`, `resources/views/` and `resources/js/` goes through `__()` with the English text as key. German texts move to `lang/de.json`.
- Enum labels (`ImportStatus`, `BulkImportStatus`, `UserRole`, `UserStatus`, `LabelSize`, `GroupMark`) and `ProviderFailureReason::message()` use `__()`.
- **JS messages** (`bulk-photos.js`, `item-photos.js`, `photo-upload.js`): the Blade view that creates the Alpine component passes the translated messages in its config object, next to `maxPhotos`. *(My call: no JS translation library for six strings.)*
- **Validation:** `lang/de/validation.php` stays; English uses Laravel's built-in English messages. `lang/de/auth.php`, `pagination.php`, `passwords.php` stay.
- **Completeness test:** a test collects every `__('…')` literal in `app/` and `resources/views/` and fails for any key missing from `lang/de.json`, and for any key in `lang/de.json` that no code uses. *(My call: the second half keeps the file free of sediment.)*
- **Texts that stay German or English as they are:** Artisan output, eval reports, logs and internal exception messages (non-goal).

### English vocabulary

The English interface uses the code names from `CONTEXT.md` where they exist. Agreed with the user, and fixed before the string sweep, since every English word is a translation key:

| German UI | English UI |
| --- | --- |
| Gegenstand / Gegenstände | Item / Items |
| Lagerplatz | Spot |
| Lagerort | Location |
| Etikett | Label |
| Import, Bulkimport | Import, Bulk import |
| Suchbegriff | Search term |
| Bemerkung | Note |
| Eigene KI | AI providers |
| Claude-Zugang | Claude connection |
| Tory in Claude | Tory in Claude |
| Erfassen | Add item |
| Übernehmen | Create item |
| Neu generieren | Regenerate |
| Einladungslink | Invite link |
| Ersteinrichtung | First-time setup |
| Gesperrt | Locked |

### Content language

- **Storage:** a single-row table `household_settings` (UUID key, `content_language` string, timestamps) with model `App\Models\HouseholdSettings` and `HouseholdSettings::current(): self`, which returns the one row. The migration inserts the row with `de`, so existing installations keep German. *(My call: a typed column on a single-row table is the smallest thing that holds one household setting; a key-value table or `spatie/laravel-settings` would be speculative or a new dependency.)*
- **Fresh install:** `User::createFirstAdministrator()` also sets `content_language` to the locale `/setup` was shown in, inside the same transaction.
- **Page:** `App\Filament\Pages\HouseholdSettingsPage`, navigation label „Haushalt“ / "Household", in the existing navigation group „Einstellungen“, visible and reachable only for administrators. One section with one Select „Inhaltssprache“ / "Content language" and a helper text saying that it applies to new captures and „Neu generieren“ only. Saving shows a notification. *(My call on the name: the group „Einstellungen“ already exists, so the page needs a name of its own.)*
- **Effect:** read when an extraction runs (`ExtractImport`, `RegenerateTexts`, `ProviderTester`), so it applies to every provider and to the `.env` fallback. Saving does nothing else: no job, no change to items.

### Extraction

- `InventoryExtractor` gets the content language as a constructor argument (`Language $language`), passed by its callers from `HouseholdSettings::current()`.
- **Two prompts:** the German instructions, schema descriptions and user prompt stay exactly as they are for `de`. For `en` there is an English version with the same rules adapted to English (the everyday English word, search terms as single plain English words, the same spelling-variant rule). *(My call: leaving the German prompt untouched is the surest way to meet the anti-goal "German extraction gets worse"; the cost is two prompts kept in step.)*
- `PhotoBoundaryChecker` answers booleans only and stays German. *(My call.)*
- The embedding text keeps its `Suchbegriffe:` prefix. *(My call: a one-word label hardly moves the vector, and changing it would invite re-embedding everything.)*

### Eval

- `extraction:eval` gets `--language=de|en`, default `de`.
- `tests/Evals/extraction/cases.json` keeps its four cases; each case's `title_words`, `search_words` and `forbidden_words` become per-language (`"de": {…}, "en": {…}`), with English expectations for the same photos.
- **Release criterion:** with the local Ollama model (`--provider="Ollama lokal" --runs=3`), German scores no worse than on `master`, and English roughly as well as German. The reports go into the pull request description.

### REST API and MCP server

- Error messages, `status_label`, `failure_message` and validation messages follow the token owner's interface language through `SetLocale`.
- The MCP server's `#[Instructions]`, tool `#[Description]`s and schema descriptions are rewritten once in English. Tool answers (`Response::text`, `Response::error`) go through `__()` and follow the token owner.
- Content (title, description, search terms) comes back as stored.

### Tests

- Tests stay German: `TestCase` sends `Accept-Language: de` by default and `UserFactory` sets `locale` to `de`, so the existing German assertions keep holding. *(My call.)*
- New tests cover: locale resolution (user setting, browser, fallback `en`), the switch, the migration default for existing users, English date format, the content-language page (admin only, saving changes nothing else), the setup taking over the locale, the extractor choosing the prompt by language, an API error and an MCP answer in English, and the completeness test.

### Documentation

- `CLAUDE.md`: the "German UI" line in the architecture map becomes the language rules (English keys, `lang/de.json`, `SetLocale`, completeness test); the `extraction:eval` paragraph names `--language`.
- `CONTEXT.md`: the opening sentence no longer says the interface is German; terms **Interface language** (Sprache der Oberfläche) and **Content language** (Inhaltssprache); each existing term lists its English UI word where it differs from the code name (for example Provider: "AI providers").
- `README.md`: a short note on the two languages.

## Left to the builder

- Whether the switch is a `POST /locale` route or a user-menu action.
- How the completeness test extracts keys, as long as it covers PHP and Blade.
- The exact English texts and the English prompt wording, as long as the eval meets the release criterion.
- Test layout.

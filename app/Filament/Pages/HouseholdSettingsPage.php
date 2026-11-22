<?php

namespace App\Filament\Pages;

use App\Enums\Language;
use App\Models\HouseholdSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property-read Schema $form
 */
class HouseholdSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $slug = 'household';

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Household');
    }

    public static function getNavigationLabel(): string
    {
        return __('Household');
    }

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public function mount(): void
    {
        $this->form->fill(['content_language' => HouseholdSettings::current()->content_language->value]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('Save'))
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->schema([
                    Select::make('content_language')
                        ->label(__('Content language'))
                        ->options(collect(Language::cases())->mapWithKeys(fn (Language $language): array => [$language->value => $language->getLabel()])->all())
                        ->helperText(__('Applies to new captures and to “Regenerate” only. Existing items stay as they are.'))
                        ->required()
                        ->native(false),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = HouseholdSettings::current();
        $settings->changeContentLanguage(Language::from($data['content_language']));
        $settings->confirmContentLanguage();

        Notification::make()->title(__('Saved'))->success()->send();
    }
}

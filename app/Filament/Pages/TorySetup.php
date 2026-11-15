<?php

namespace App\Filament\Pages;

use App\Mcp\ClaudeConnections;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class TorySetup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $slug = 'tory-in-claude';

    protected static ?string $title = 'Tory in Claude';

    protected static ?string $navigationLabel = 'Tory in Claude';

    protected static string|UnitEnum|null $navigationGroup = 'Einstellungen';

    protected static ?int $navigationSort = 5;

    public function content(Schema $schema): Schema
    {
        $address = url('/mcp');

        return $schema->components([
            Section::make()->schema([
                Text::make('Du kannst Claude fragen, wo etwas liegt, und ihm sagen, wenn du etwas woanders hingelegt hast.'),
                TextEntry::make('address')
                    ->label('Adresse von Tory')
                    ->state($address)
                    ->copyable()
                    ->icon(Heroicon::OutlinedClipboard)
                    ->iconPosition(IconPosition::After)
                    ->copyMessage('Kopiert'),
            ]),
            Section::make('Im Browser (claude.ai)')->schema([
                Text::make('Öffne in claude.ai die Einstellungen, dann Connectors, und füge einen eigenen Connector hinzu. Tippe als Name „Tory" ein und füge die Adresse von oben ein, melde dich bei Tory an und klicke auf „Erlauben".'),
            ]),
            Section::make('In der Handy-App')->schema([
                Text::make('Hier ist nichts zu tun: Der Connector aus claude.ai ist dort auch da.'),
            ]),
            Section::make('In Claude Desktop')->schema([
                Text::make('Genau wie im Browser.'),
            ]),
            Section::make('In Claude Code')->schema([
                Text::make('Kopiere diesen Befehl. Starte danach Claude Code, tippe /mcp, wähle „tory“ und melde dich bei Tory an.'),
                TextEntry::make('command')
                    ->hiddenLabel()
                    ->state('claude mcp add --transport http --scope user tory '.$address)
                    ->copyable()
                    ->icon(Heroicon::OutlinedClipboard)
                    ->iconPosition(IconPosition::After)
                    ->copyMessage('Kopiert'),
            ]),
            Section::make('Probier es aus')->schema([
                Text::make('„Wo ist die Bohrmaschine?"'),
                Text::make('„Ich habe den Akkuschrauber in die Kiste Werkzeug gelegt."'),
                Text::make('„Leg im Keller einen neuen Lagerplatz ‚Regal 4\' an."'),
            ]),
            Section::make('Was Claude nicht kann')->schema([
                Text::make('Fotos und neue Gegenstände gehen über „Erfassen".'),
            ]),
            Section::make('Verbundene Claude-Zugänge')->schema([
                View::make('filament.pages.tory-setup-connections')
                    ->viewData(fn (ClaudeConnections $connections): array => [
                        'connections' => $connections->for($this->currentUser()),
                    ]),
            ]),
        ]);
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Trennen')
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Claude trennen?')
            ->modalDescription('Claude kann danach nicht mehr auf Tory zugreifen, bis du es neu verbindest.')
            ->action(function (array $arguments, ClaudeConnections $connections): void {
                $connections->disconnect($this->currentUser(), (string) $arguments['clientId']);
            });
    }

    private function currentUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}

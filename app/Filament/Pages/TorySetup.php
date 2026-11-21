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
use Illuminate\Contracts\Support\Htmlable;

class TorySetup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $slug = 'tory-in-claude';

    protected static ?int $navigationSort = 5;

    public function getTitle(): string|Htmlable
    {
        return __('Tory in Claude');
    }

    public static function getNavigationLabel(): string
    {
        return __('Tory in Claude');
    }

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public function content(Schema $schema): Schema
    {
        $address = url('/mcp');

        return $schema->components([
            Section::make()->schema([
                Text::make(__('You can ask Claude where something is and tell it when you have put something elsewhere.')),
                TextEntry::make('address')
                    ->label(__('Address of Tory'))
                    ->state($address)
                    ->copyable()
                    ->icon(Heroicon::OutlinedClipboard)
                    ->iconPosition(IconPosition::After)
                    ->copyMessage(__('Copied')),
            ]),
            Section::make(__('In the browser (claude.ai)'))->schema([
                Text::make(__('In claude.ai, open Settings, then Connectors, and add a custom connector. Enter “Tory” as the name, paste the address from above, sign in to Tory and click “Allow”.')),
            ]),
            Section::make(__('In the mobile app'))->schema([
                Text::make(__('Nothing to do here: the connector from claude.ai is there too.')),
            ]),
            Section::make(__('In Claude Desktop'))->schema([
                Text::make(__('Exactly like in the browser.')),
            ]),
            Section::make(__('In Claude Code'))->schema([
                Text::make(__('Copy this command. Then start Claude Code, type /mcp, choose “tory” and sign in to Tory.')),
                TextEntry::make('command')
                    ->hiddenLabel()
                    ->state('claude mcp add --transport http --scope user tory '.$address)
                    ->copyable()
                    ->icon(Heroicon::OutlinedClipboard)
                    ->iconPosition(IconPosition::After)
                    ->copyMessage(__('Copied')),
            ]),
            Section::make(__('Try it out'))->schema([
                Text::make(__('“Where is the drill?”')),
                Text::make(__('“I put the cordless screwdriver in the toolbox.”')),
                Text::make(__('“Create a new spot ‘Shelf 4’ in the basement.”')),
            ]),
            Section::make(__('What Claude cannot do'))->schema([
                Text::make(__('Photos and new items go through “Add item”.')),
            ]),
            Section::make(__('Claude connections'))->schema([
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
            ->label(__('Disconnect'))
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(__('Disconnect Claude?'))
            ->modalDescription(__('Claude can no longer access Tory afterwards, until you connect it again.'))
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

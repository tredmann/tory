<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;

class ManageUsers extends ManageRecords
{
    use OmitsPageBreadcrumb, StartsBreadcrumbsAtHome;

    protected static ?string $title = 'Nutzer';

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nutzer anlegen')
                ->createAnother(false)
                ->using(fn (array $data): User => User::invite($data['name'], $data['email'], $data['role']))
                ->after(fn (User $record) => $this->showInviteLink($record->issueInviteLink())),
        ];
    }

    /**
     * Replaces the action that produced the link, so the link appears once and is never stored in plain text.
     */
    public function showInviteLink(string $url): void
    {
        $this->replaceMountedAction('showInviteLink', ['url' => $url]);
    }

    public function showInviteLinkAction(): Action
    {
        return Action::make('showInviteLink')
            ->modalHeading('Einladungslink')
            ->modalDescription('Gilt 24 Stunden und nur einmal. Der Link wird nur jetzt angezeigt.')
            ->schema(fn (array $arguments): array => [
                TextEntry::make('url')
                    ->hiddenLabel()
                    ->state($arguments['url'] ?? null)
                    ->copyable()
                    ->icon(Heroicon::OutlinedClipboard)
                    ->iconPosition(IconPosition::After)
                    ->copyMessage('Kopiert'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Schließen');
    }
}

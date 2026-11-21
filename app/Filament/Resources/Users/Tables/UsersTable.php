<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Access\Gate;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('Email')),
                TextColumn::make('role')
                    ->label(__('Role'))
                    ->badge(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (User $record): UserStatus => $record->status())
                    ->badge(),
            ])
            ->paginated(false)
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label(__('Edit'))
                        ->using(self::saveUser(...)),
                    Action::make('issueInvite')
                        ->authorize('issueInvite')
                        ->label(__('New link'))
                        ->groupedIcon(Heroicon::Link)
                        ->requiresConfirmation()
                        ->modalHeading(__('New link'))
                        ->modalDescription(__('An invite link issued so far becomes invalid.'))
                        ->modalSubmitActionLabel(__('Create link'))
                        ->action(fn (User $record, ManageUsers $livewire) => $livewire->showInviteLink($record->issueInviteLink())),
                    Action::make('lock')
                        ->authorize('lock')
                        ->label(__('Lock'))
                        ->groupedIcon(Heroicon::LockClosed)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Lock'))
                        ->modalDescription(__('The person can then no longer sign in or use the API or Claude.'))
                        ->modalSubmitActionLabel(__('Lock'))
                        ->action(function (User $record): void {
                            $record->lock();

                            Notification::make()->title(__('Locked'))->success()->send();
                        }),
                    Action::make('unlock')
                        ->authorize('unlock')
                        ->label(__('Unlock'))
                        ->groupedIcon(Heroicon::LockOpen)
                        ->requiresConfirmation()
                        ->modalHeading(__('Unlock'))
                        ->modalSubmitActionLabel(__('Unlock'))
                        ->action(function (User $record): void {
                            $record->unlock();

                            Notification::make()->title(__('Unlocked'))->success()->send();
                        }),
                ])->color('gray'),
            ]);
    }

    /**
     * `role` is not fillable; it changes only where the policy allows it, so a
     * disabled field on the administrator's own row cannot be bypassed.
     *
     * @param  array{name: string, email: string, role?: UserRole}  $data
     */
    private static function saveUser(array $data, User $record, Gate $gate): void
    {
        $record->update(['name' => $data['name'], 'email' => $data['email']]);

        if (isset($data['role']) && $gate->allows('changeRole', $record)) {
            $record->assignRole($data['role']);
        }
    }
}

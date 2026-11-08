<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
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
                    ->label('Name')
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-Mail'),
                TextColumn::make('role')
                    ->label('Rolle')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (User $record): UserStatus => $record->status())
                    ->badge(),
            ])
            ->paginated(false)
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()
                    ->label('Bearbeiten')
                    ->using(self::saveUser(...)),
                Action::make('issueInvite')
                    ->authorize('issueInvite')
                    ->label('Neuer Link')
                    ->requiresConfirmation()
                    ->modalHeading('Neuer Link')
                    ->modalDescription('Ein bisher ausgegebener Link wird damit ungültig.')
                    ->modalSubmitActionLabel('Link erzeugen')
                    ->action(fn (User $record, ManageUsers $livewire) => $livewire->showInviteLink($record->issueInviteLink())),
                Action::make('lock')
                    ->authorize('lock')
                    ->label('Sperren')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Sperren')
                    ->modalDescription('Die Person kann sich danach weder anmelden noch die API oder Claude nutzen.')
                    ->modalSubmitActionLabel('Sperren')
                    ->action(function (User $record): void {
                        $record->lock();

                        Notification::make()->title('Gesperrt')->success()->send();
                    }),
                Action::make('unlock')
                    ->authorize('unlock')
                    ->label('Entsperren')
                    ->requiresConfirmation()
                    ->modalHeading('Entsperren')
                    ->modalSubmitActionLabel('Entsperren')
                    ->action(function (User $record): void {
                        $record->unlock();

                        Notification::make()->title('Entsperrt')->success()->send();
                    }),
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

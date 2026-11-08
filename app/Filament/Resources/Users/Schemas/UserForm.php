<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('E-Mail')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Select::make('role')
                ->label('Rolle')
                ->options(UserRole::class)
                ->default(UserRole::Member)
                ->required()
                ->selectablePlaceholder(false)
                ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
        ]);
    }
}

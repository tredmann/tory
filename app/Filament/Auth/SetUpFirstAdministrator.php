<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The page of an installation without any user (`/setup`): the first visitor creates the first administrator and is logged in.
 *
 * @property-read Schema $form
 */
class SetUpFirstAdministrator extends SimplePage
{
    protected string $view = 'filament.auth.set-up-first-administrator';

    public ?string $name = '';

    public ?string $email = '';

    public ?string $password = '';

    public ?string $passwordConfirmation = '';

    #[Unexposed]
    public function mount(): void
    {
        abort_if(User::query()->exists(), 404);

        $this->form->fill();
    }

    #[Exposed]
    public function create(): void
    {
        $data = $this->form->getState();

        $user = User::createFirstAdministrator($data['name'], $data['email'], $data['password']);

        if ($user === null) {
            $this->redirect(filament()->getLoginUrl());

            return;
        }

        Filament::auth()->login($user);
        session()->regenerate();

        $this->redirect(filament()->getUrl());
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255)
                ->autofocus(),
            TextInput::make('email')
                ->label(__('Email'))
                ->email()
                ->required()
                ->maxLength(255),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('Password'))
            ->password()
            ->autocomplete('new-password')
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(PasswordRule::default())
            ->same('passwordConfirmation')
            ->validationAttribute(__('Password'));
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label(__('Confirm password'))
            ->password()
            ->autocomplete('new-password')
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->dehydrated(false);
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Welcome to Tory');
    }

    #[Unexposed]
    public function getHeading(): string|Htmlable|null
    {
        return __('Welcome to Tory');
    }

    #[Unexposed]
    public function getSubheading(): string|Htmlable|null
    {
        return __('Create the first administrator.');
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('create')
                ->footer([
                    Actions::make([
                        Action::make('create')
                            ->label(__('Create administrator'))
                            ->submit('create'),
                    ])
                        ->alignment($this->getFormActionsAlignment())
                        ->fullWidth()
                        ->key('form-actions'),
                ]),
        ]);
    }
}

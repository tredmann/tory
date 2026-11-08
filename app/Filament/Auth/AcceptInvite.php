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
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * The page behind an invite link (`/invite/{token}`): the invited user sets a password and is logged in.
 *
 * @property-read Schema $form
 */
class AcceptInvite extends SimplePage
{
    protected string $view = 'filament.auth.accept-invite';

    #[Locked]
    public string $token = '';

    #[Locked]
    public bool $isLinkValid = false;

    public ?string $password = '';

    public ?string $passwordConfirmation = '';

    #[Unexposed]
    public function mount(#[SensitiveParameter] string $token): void
    {
        $this->token = $token;
        $this->isLinkValid = User::findByInviteToken($token) !== null;

        $this->form->fill();
    }

    #[Exposed]
    public function accept(): void
    {
        $data = $this->form->getState();

        $user = User::findByInviteToken($this->token);

        if ($user === null) {
            $this->isLinkValid = false;

            return;
        }

        $user->acceptInvite($data['password']);

        Filament::auth()->login($user);
        session()->regenerate();

        $this->redirect(filament()->getUrl());
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Passwort')
            ->password()
            ->autocomplete('new-password')
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(PasswordRule::default())
            ->same('passwordConfirmation')
            ->validationAttribute('Passwort')
            ->autofocus();
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label('Passwort bestätigen')
            ->password()
            ->autocomplete('new-password')
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->dehydrated(false);
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return 'Passwort festlegen';
    }

    #[Unexposed]
    public function getHeading(): string|Htmlable|null
    {
        return 'Passwort festlegen';
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('accept')
                ->footer([
                    Actions::make([
                        Action::make('accept')
                            ->label('Passwort speichern')
                            ->submit('accept'),
                    ])
                        ->alignment($this->getFormActionsAlignment())
                        ->fullWidth()
                        ->key('form-actions'),
                ]),
        ]);
    }
}

<?php

namespace App\Filament\Widgets;

use App\Models\HouseholdSettings;
use App\Models\User;
use App\Onboarding\Onboarding;
use App\Onboarding\OnboardingStep;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;

class OnboardingWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.onboarding-widget';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return new Onboarding()->isShown(self::user());
    }

    public function confirmContentLanguageAction(): Action
    {
        return Action::make('confirmContentLanguage')
            ->label(__('Looks right'))
            ->authorize(fn (): bool => self::user()?->isAdmin() ?? false)
            ->action(fn () => HouseholdSettings::current()->confirmContentLanguage());
    }

    public function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->label(__('Hide'))
            ->link()
            ->color('gray')
            ->authorize(fn (): bool => self::user()?->isAdmin() ?? false)
            ->requiresConfirmation()
            ->modalHeading(__('Hide the setup?'))
            ->modalDescription(__('The setup disappears for every administrator and does not come back.'))
            ->modalSubmitActionLabel(__('Hide'))
            ->action(fn () => HouseholdSettings::current()->finishOnboarding());
    }

    /**
     * The steps are computed once per render; the open step and the done count are derived from them.
     * After "Ausblenden" or the last step, the widget renders nothing until the next page load drops it.
     *
     * @return array{shown: bool, steps: list<OnboardingStep>, openKey: string|null, done: int, total: int}
     */
    protected function getViewData(): array
    {
        $steps = new Onboarding()->steps();
        $open = collect($steps)->first(fn (OnboardingStep $step): bool => ! $step->done);
        $done = count(array_filter($steps, fn (OnboardingStep $step): bool => $step->done));

        return [
            'shown' => $open !== null && HouseholdSettings::current()->onboarding_finished_at === null,
            'steps' => $steps,
            'openKey' => $open?->key,
            'done' => $done,
            'total' => count($steps),
        ];
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}

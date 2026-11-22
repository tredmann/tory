<?php

namespace App\Onboarding;

use App\Filament\Pages\HouseholdSettingsPage;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Spots\SpotResource;
use App\Models\AiProvider;
use App\Models\HouseholdSettings;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;

final readonly class Onboarding
{
    /**
     * @return list<OnboardingStep>
     */
    public function steps(): array
    {
        $settings = HouseholdSettings::current();
        $recognition = AiProvider::active();
        $search = AiProvider::activeForSearch();

        return [
            new OnboardingStep(
                key: 'content-language',
                title: __('Check the content language'),
                done: $settings->content_language_confirmed_at !== null,
                url: HouseholdSettingsPage::getUrl(),
                result: $settings->content_language->getLabel(),
                explanation: __('The content language decides in which language Tory writes titles, descriptions and search terms for new items. It was taken from your browser; check that it fits.'),
                actionLabel: __('Looks right'),
            ),
            new OnboardingStep(
                key: 'recognition',
                title: __('Set up AI for image recognition'),
                done: $recognition !== null,
                url: AiProviderResource::getUrl(),
                result: $recognition?->name,
                explanation: __('This AI writes the title, description and search terms from your photos.'),
                actionLabel: __('Open AI providers'),
            ),
            new OnboardingStep(
                key: 'search',
                title: __('Set up AI for search'),
                done: $search !== null,
                url: AiProviderResource::getUrl(),
                result: $search?->name,
                explanation: __('This AI lets search find things by meaning: "drill" also finds "cordless screwdriver". Claude cannot do this, so with Claude for image recognition, search needs OpenAI, Mistral or Ollama.'),
                actionLabel: __('Open AI providers'),
            ),
            new OnboardingStep(
                key: 'spot',
                title: __('Create the first spot'),
                done: Spot::query()->exists(),
                url: SpotResource::getUrl('create'),
                result: null,
                explanation: __('A location is an area such as "Basement", a spot is the place in it such as "Shelf 3, compartment 2". Every spot gets a printable label with a QR code.'),
                actionLabel: __('Create spot'),
            ),
            new OnboardingStep(
                key: 'item',
                title: __('Add the first item'),
                done: Item::query()->exists(),
                url: ImportResource::getUrl('create'),
                result: null,
                explanation: __('Photograph something and Tory suggests title, description and search terms. You only choose the spot.'),
                actionLabel: __('Add item'),
            ),
        ];
    }

    public function openStep(): ?OnboardingStep
    {
        return collect($this->steps())->first(fn (OnboardingStep $step): bool => ! $step->done);
    }

    public function doneCount(): int
    {
        return count(array_filter($this->steps(), fn (OnboardingStep $step): bool => $step->done));
    }

    /**
     * Whether the onboarding shows for this user; when every step is done it stores the end of the onboarding.
     */
    public function isShown(?User $user): bool
    {
        if (! $user?->isAdmin()) {
            return false;
        }

        $settings = HouseholdSettings::current();

        if ($settings->onboarding_finished_at !== null) {
            return false;
        }

        if ($this->openStep() === null) {
            $settings->finishOnboarding();

            return false;
        }

        return true;
    }
}

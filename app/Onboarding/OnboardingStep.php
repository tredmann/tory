<?php

namespace App\Onboarding;

final readonly class OnboardingStep
{
    public function __construct(
        public string $key,
        public string $title,
        public bool $done,
        public string $url,
        public ?string $result,
        public string $explanation,
        public string $actionLabel,
    ) {}
}

<?php

namespace App\Filament\Resources\Concerns;

use App\Filament\Pages\Dashboard;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

use function Filament\Support\generate_icon_html;

trait StartsBreadcrumbsAtHome
{
    #[Unexposed]
    public function hasResourceBreadcrumbs(): bool
    {
        return true;
    }

    /**
     * @return array<string>
     */
    #[Unexposed]
    public function getResourceBreadcrumbs(): array
    {
        $icon = generate_icon_html(Heroicon::Home, size: IconSize::Small)?->toHtml();

        return [
            Dashboard::getUrl() => new HtmlString($icon.'<span class="tory-visually-hidden">Übersicht</span>'),
            ...parent::getResourceBreadcrumbs(),
        ];
    }
}

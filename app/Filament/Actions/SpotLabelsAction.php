<?php

namespace App\Filament\Actions;

use App\Enums\LabelSize;
use App\Filament\Resources\Spots\SpotLabelSheet;
use App\Models\Spot;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpotLabelsAction
{
    public static function make(): Action
    {
        return self::configure(Action::make('labels'))
            ->label(__('Generate label'))
            ->action(fn (Spot $record, array $data, SpotLabelSheet $sheet): StreamedResponse => $sheet->download(
                new Collection([$record]),
                $data['size'],
            ));
    }

    public static function bulk(): BulkAction
    {
        return self::configure(BulkAction::make('labels'))
            ->label(__('Generate labels'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data, SpotLabelSheet $sheet): StreamedResponse => $sheet->download(
                $records,
                $data['size'],
            ));
    }

    /**
     * @template TAction of Action
     *
     * @param  TAction  $action
     * @return TAction
     */
    private static function configure(Action $action): Action
    {
        return $action
            ->color('gray')
            ->groupedIcon(Heroicon::QrCode)
            ->modalHeading(__('Generate labels'))
            ->modalSubmitActionLabel(__('Download PDF'))
            ->schema([
                Select::make('size')
                    ->label(__('Size'))
                    ->options(LabelSize::class)
                    ->default(LabelSize::Medium->value)
                    ->selectablePlaceholder(false)
                    ->required(),
            ]);
    }
}

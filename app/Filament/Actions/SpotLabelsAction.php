<?php

namespace App\Filament\Actions;

use App\Enums\LabelSize;
use App\Filament\Resources\Spots\SpotLabelSheet;
use App\Models\Spot;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpotLabelsAction
{
    public static function make(): Action
    {
        return self::configure(Action::make('labels'))
            ->label('Etikett erzeugen')
            ->action(fn (Spot $record, array $data, SpotLabelSheet $sheet): StreamedResponse => $sheet->download(
                new Collection([$record]),
                $data['size'],
            ));
    }

    public static function bulk(): BulkAction
    {
        return self::configure(BulkAction::make('labels'))
            ->label('Etiketten erzeugen')
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
            ->modalHeading('Etiketten erzeugen')
            ->modalSubmitActionLabel('PDF herunterladen')
            ->schema([
                Select::make('size')
                    ->label('Größe')
                    ->options(LabelSize::class)
                    ->default(LabelSize::Medium->value)
                    ->selectablePlaceholder(false)
                    ->required(),
            ]);
    }
}

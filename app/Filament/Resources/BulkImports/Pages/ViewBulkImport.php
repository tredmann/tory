<?php

namespace App\Filament\Resources\BulkImports\Pages;

use App\BulkImports\PhotoGroup;
use App\BulkImports\ReviewCard;
use App\BulkImports\ReviewPhoto;
use App\BulkImports\StaleBulkImport;
use App\Enums\BulkImportStatus;
use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Filament\Resources\BulkImports\Concerns\SitsBelowImports;
use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Imports\ImportResource;
use App\Models\BulkImport;
use App\Models\Import;
use App\Photos\PhotoLinks;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewBulkImport extends ViewRecord
{
    use OmitsPageBreadcrumb, SitsBelowImports, StartsBreadcrumbsAtHome;

    protected static string $resource = BulkImportResource::class;

    protected static ?string $title = 'Bulkimport';

    protected string $view = 'filament.resources.bulk-imports.view';

    /**
     * The page shows its own view and no form, so the record stays out of the Livewire state.
     */
    protected function fillForm(): void {}

    public function getSubheading(): ?string
    {
        return $this->bulkImport()->spot?->label;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Löschen')
                ->successRedirectUrl(ImportResource::getUrl('index')),
            Action::make('confirm')
                ->label(fn (): string => self::imports($this->bulkImport()->importCount()).' anlegen')
                ->visible(fn (): bool => $this->bulkImport()->status === BulkImportStatus::Ready)
                ->disabled(fn (): bool => ! $this->bulkImport()->canConfirm())
                ->action(function (): void {
                    $bulkImport = $this->bulkImport();

                    try {
                        $imports = $bulkImport->confirm();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title($e->getMessage())
                            ->danger()
                            ->send();

                        $this->getRecord()->refresh();

                        return;
                    }

                    auth()->user()?->forceFill(['last_spot_id' => $bulkImport->spot_id])->save();

                    Notification::make()
                        ->title(self::imports(count($imports)).' angelegt')
                        ->success()
                        ->send();

                    $this->redirect(ImportResource::getUrl('index'));
                }),
        ];
    }

    public function movePhoto(string $path, string $groupId, int $position): void
    {
        $this->edit(fn (BulkImport $bulkImport) => $bulkImport->movePhoto($path, $groupId, $position));
    }

    /**
     * The wire:sort handler: Livewire passes the item, its new position and the target card's group id.
     */
    public function sortPhoto(string $path, int $position, string $groupId): void
    {
        $this->movePhoto($path, $groupId, $position);
    }

    public function splitBefore(string $path): void
    {
        $this->edit(fn (BulkImport $bulkImport) => $bulkImport->splitBefore($path));
    }

    public function mergeWithNext(string $groupId, string $nextGroupId): void
    {
        $this->edit(fn (BulkImport $bulkImport) => $bulkImport->mergeWithNext($groupId, $nextGroupId));
    }

    public function discard(string $path): void
    {
        $this->edit(fn (BulkImport $bulkImport) => $bulkImport->setDiscarded($path, true));
    }

    public function restore(string $path): void
    {
        $this->edit(fn (BulkImport $bulkImport) => $bulkImport->setDiscarded($path, false));
    }

    /**
     * @return array{bulkImport: BulkImport, cards: list<ReviewCard>}
     */
    protected function getViewData(): array
    {
        return ['bulkImport' => $this->bulkImport(), 'cards' => $this->cards()];
    }

    /**
     * The cards in order, each with its photos linked to their thumbnails.
     *
     * @return list<ReviewCard>
     */
    private function cards(): array
    {
        $bulkImport = $this->bulkImport();
        $discarded = $bulkImport->discarded ?? [];

        return array_map(function (PhotoGroup $group) use ($bulkImport, $discarded): ReviewCard {
            $remaining = count($bulkImport->remainingPhotos($group));

            return new ReviewCard(
                group: $group,
                remaining: $remaining,
                overLimit: $remaining > Import::MAX_PHOTOS,
                photos: array_map(fn (string $path): ReviewPhoto => new ReviewPhoto(
                    path: $path,
                    url: PhotoLinks::thumbnail($path),
                    discarded: in_array($path, $discarded, true),
                ), $group->photos),
            );
        }, $bulkImport->groupList());
    }

    private function bulkImport(): BulkImport
    {
        $bulkImport = $this->getRecord();
        assert($bulkImport instanceof BulkImport);

        return $bulkImport;
    }

    /**
     * Runs an edit; when the groups changed meanwhile, it tells the user and shows the current state.
     *
     * @param  Closure(BulkImport): void  $edit
     */
    private function edit(Closure $edit): void
    {
        try {
            $edit($this->bulkImport());
        } catch (StaleBulkImport) {
            Notification::make()
                ->title('Die Gruppen wurden inzwischen geändert. Die Seite zeigt jetzt den aktuellen Stand.')
                ->warning()
                ->send();

            $current = $this->bulkImport()->fresh();

            if ($current === null) {
                $this->redirect(ImportResource::getUrl('index'));

                return;
            }

            $this->record = $current;
        }
    }

    private static function imports(int $count): string
    {
        return $count === 1 ? '1 Import' : "{$count} Importe";
    }
}

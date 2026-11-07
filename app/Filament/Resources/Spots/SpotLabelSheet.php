<?php

namespace App\Filament\Resources\Spots;

use App\Enums\LabelSize;
use App\Models\Spot;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpotLabelSheet
{
    /**
     * @param  Collection<int, Spot>  $spots
     */
    public function download(Collection $spots, LabelSize $size): StreamedResponse
    {
        // Rendered up front, so a failure is still a normal error and not a broken download.
        $pdf = $this->pdf($spots, $size);

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf;
        }, $this->fileName($spots, $size), ['Content-Type' => 'application/pdf']);
    }

    /**
     * @param  Collection<int, Spot>  $spots
     */
    private function fileName(Collection $spots, LabelSize $size): string
    {
        $first = $spots->firstOrFail();

        $subject = match (true) {
            $spots->containsOneItem() => $this->slug($first->name) ?: Str::lower($first->code),
            $spots->pluck('location_id')->unique()->containsOneItem() => $this->slug($first->location->name) ?: 'etiketten',
            default => 'etiketten',
        };

        return "{$subject}-{$size->fileNamePart()}.pdf";
    }

    private function slug(string $name): string
    {
        return rtrim(Str::limit(Str::slug($name, language: 'de'), 100, ''), '-');
    }

    /**
     * @param  Collection<int, Spot>  $spots
     */
    public function pdf(Collection $spots, LabelSize $size): string
    {
        return Pdf::view('pdf.spot-labels', [
            'rows' => array_chunk($this->labels($spots), $size->columns()),
            'size' => $size,
        ])
            ->format(Format::A4)
            ->margins(5, 5, 5, 5)
            ->generatePdfContent();
    }

    /**
     * @param  Collection<int, Spot>  $spots
     * @return array<int, SpotLabel>
     */
    public function labels(Collection $spots): array
    {
        return $spots
            ->sortBy('code')
            ->map(function (Spot $spot): SpotLabel {
                $address = $this->address($spot);

                return new SpotLabel(
                    code: $spot->code,
                    name: $spot->name,
                    address: Str::after($address, '://'),
                    qrCode: $this->qrCode($address),
                );
            })
            ->values()
            ->all();
    }

    /**
     * A QRCode instance keeps what it rendered, so every label needs its own.
     */
    private function qrCode(string $address): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => 20,
        ])))->render($address);
    }

    /**
     * The address a label keeps for good, so it comes from the installation and not from the current request.
     */
    private function address(Spot $spot): string
    {
        return rtrim((string) config('app.url'), '/').SpotResource::getUrl('view', ['record' => $spot], isAbsolute: false);
    }
}

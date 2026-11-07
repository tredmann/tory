<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Api\V1\StoreSpotRequest;
use App\Mcp\SpotSummary;
use App\Models\Location;
use App\Models\Spot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Legt einen neuen Lagerplatz in einem bestehenden Lagerort an. Vorher den Nutzer fragen.')]
class CreateSpotTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Name des neuen Lagerplatzes, zum Beispiel "Regal 5".')->required(),
            'location' => $schema->string()->description('Name eines bestehenden Lagerorts, zum Beispiel "Keller".')->required(),
            'note' => $schema->string()->description('Optionale Notiz zum Lagerplatz.'),
        ];
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $rules = (new StoreSpotRequest)->rules();

        $validated = $request->validate([
            'name' => $rules['name'],
            'note' => $rules['note'],
            'location' => ['required', 'string'],
        ]);

        $location = Location::query()->whereRaw('lower(name) = lower(?)', [trim($validated['location'])])->first();

        if ($location === null) {
            $names = Location::query()->orderBy('name')->pluck('name')->all();

            return Response::error('Diesen Lagerort gibt es nicht.'.($names === [] ? '' : ' Vorhandene Lagerorte: '.implode(', ', $names).'.'));
        }

        $spot = Spot::query()->create([
            'name' => $validated['name'],
            'note' => $validated['note'] ?? null,
            'location_id' => $location->id,
        ]);
        $spot->setRelation('location', $location);

        $summary = SpotSummary::of($spot);

        return Response::make(Response::text("Lagerplatz {$summary['name']} ({$summary['code']}) in {$summary['location']} angelegt."))
            ->withStructuredContent($summary);
    }
}

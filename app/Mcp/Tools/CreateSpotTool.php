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

#[Description('Creates a new spot in an existing location. Ask the user first.')]
class CreateSpotTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Name of the new spot, for example "Shelf 5".')->required(),
            'location' => $schema->string()->description('Name of an existing location, for example "Cellar".')->required(),
            'note' => $schema->string()->description('Optional note on the spot.'),
        ];
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $rules = (new StoreSpotRequest)->rules();

        if (is_string($request->get('name'))) {
            $request->merge(['name' => trim($request->get('name'))]);
        }

        $validated = $request->validate([
            'name' => $rules['name'],
            'note' => $rules['note'],
            'location' => ['required', 'string'],
        ]);

        $matches = Location::query()->named($validated['location'])->orderBy('name')->get();

        if ($matches->count() > 1) {
            return Response::error(__('Several locations match this name: :names. Please ask the user which one is meant.', ['names' => $matches->pluck('name')->implode(', ')]));
        }

        $location = $matches->first();

        if ($location === null) {
            $names = Location::query()->orderBy('name')->pluck('name')->all();

            return Response::error(__('This location does not exist.').($names === [] ? '' : ' '.__('Existing locations: :names.', ['names' => implode(', ', $names)])));
        }

        $spot = Spot::query()->create([
            'name' => $validated['name'],
            'note' => $validated['note'] ?? null,
            'location_id' => $location->id,
        ]);
        $spot->setRelation('location', $location);

        $summary = SpotSummary::of($spot);

        return Response::make(Response::text(__('Spot :name (:code) created in :location.', ['name' => $summary['name'], 'code' => $summary['code'], 'location' => $summary['location']])))
            ->withStructuredContent($summary);
    }
}

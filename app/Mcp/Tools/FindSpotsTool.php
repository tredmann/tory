<?php

namespace App\Mcp\Tools;

use App\Mcp\SpotSummary;
use App\Models\Location;
use App\Models\Spot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Searches spots by name or code, optionally only within one location. Shows all matches.')]
#[IsReadOnly]
class FindSpotsTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Name or code of the spot, for example "Shelf 3".')->required(),
            'location' => $schema->string()->description('Name of the location, to narrow the search.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'location' => ['nullable', 'string'],
        ]);

        $term = trim($validated['name']);
        $code = Spot::normalizeCode($term);
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        $query = Spot::query()
            ->with('location')
            ->join('locations', 'locations.id', '=', 'spots.location_id')
            ->select('spots.*')
            ->where(function (Builder $match) use ($pattern, $code): void {
                $match->where('spots.name', 'ilike', $pattern);

                if ($code !== null) {
                    $match->orWhere('spots.code', $code);
                }
            })
            ->orderBy('locations.name')
            ->orderBy('spots.name')
            ->orderBy('spots.code');

        if (filled($validated['location'] ?? null)) {
            $query->whereIn('spots.location_id', Location::query()->named($validated['location'])->select('id'));
        }

        $spots = $query->get()->map(fn (Spot $spot): array => SpotSummary::of($spot))->all();

        if ($spots === []) {
            $locations = Location::query()->orderBy('name')->pluck('name')->all();
            $text = __('No spot found.').($locations === [] ? '' : ' '.__('Existing locations: :names.', ['names' => implode(', ', $locations)]));

            return Response::make(Response::text($text))
                ->withStructuredContent(['spots' => [], 'locations' => $locations]);
        }

        $lines = array_map(
            fn (array $spot): string => "{$spot['name']} ({$spot['code']}), {$spot['location']}",
            $spots,
        );

        return Response::make(Response::text(implode("\n", $lines)))->withStructuredContent(['spots' => $spots]);
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\SpotSummary;
use App\Models\Item;
use App\Models\Spot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Legt einen Gegenstand auf einen anderen Lagerplatz. Braucht die genaue ID des Gegenstands und den Code des Lagerplatzes aus einer vorherigen Suche.')]
#[IsIdempotent]
class MoveItemTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item' => $schema->string()->description('ID des Gegenstands aus der Suche.')->required(),
            'spot' => $schema->string()->description('Code des Ziel-Lagerplatzes aus der Suche, zum Beispiel "K7XM".')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $validated = $request->validate([
            'item' => ['required', 'string'],
            'spot' => ['required', 'string'],
        ]);

        $itemId = trim($validated['item']);
        $item = Str::isUuid($itemId) ? Item::query()->find($itemId) : null;

        if ($item === null) {
            return Response::error('Diesen Gegenstand gibt es nicht.');
        }

        $code = Spot::normalizeCode(trim($validated['spot']));
        $spot = $code === null ? null : Spot::query()->with('location')->firstWhere('code', $code);

        if ($spot === null) {
            return Response::error('Diesen Lagerplatz gibt es nicht.');
        }

        if (Item::query()->whereKey($item->getKey())->whereNotNull('regenerating_at')->exists()) {
            return Response::error('Der Gegenstand wird gerade neu generiert. Bitte versuch es gleich noch einmal.');
        }

        $item->update(['spot_id' => $spot->id]);

        $summary = SpotSummary::of($spot);

        return Response::make(Response::text("{$item->title} liegt jetzt auf {$summary['name']} ({$summary['code']}), {$summary['location']}."))
            ->withStructuredContent(['id' => $item->id, 'title' => $item->title, 'spot' => $summary]);
    }
}

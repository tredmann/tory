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

#[Description('Puts an item on another spot. Needs the exact ID of the item and the code of the spot from a previous search.')]
#[IsIdempotent]
class MoveItemTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item' => $schema->string()->description('ID of the item from the search.')->required(),
            'spot' => $schema->string()->description('Code of the target spot from the search, for example "K7XM".')->required(),
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
            return Response::error(__('This item does not exist.'));
        }

        $code = Spot::normalizeCode(trim($validated['spot']));
        $spot = $code === null ? null : Spot::query()->with('location')->firstWhere('code', $code);

        if ($spot === null) {
            return Response::error(__('This spot does not exist.'));
        }

        if (Item::query()->whereKey($item->getKey())->whereNotNull('regenerating_at')->exists()) {
            return Response::error(__('The item is being regenerated right now. Please try again in a moment.'));
        }

        $item->update(['spot_id' => $spot->id]);

        $summary = SpotSummary::of($spot);

        return Response::make(Response::text(__(':title is now on :spot (:code), :location.', ['title' => $item->title, 'spot' => $summary['name'], 'code' => $summary['code'], 'location' => $summary['location']])))
            ->withStructuredContent(['id' => $item->id, 'title' => $item->title, 'spot' => $summary]);
    }
}

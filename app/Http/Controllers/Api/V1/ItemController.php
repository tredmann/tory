<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\ItemSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateItemRequest;
use App\Http\Resources\Api\V1\ItemResource;
use App\Models\Item;
use App\Models\Spot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ItemController extends Controller
{
    public function index(Request $request, ItemSearch $search): AnonymousResourceCollection
    {
        $query = Item::query()->with('spot.location');

        if ($request->query->has('location')) {
            $location = $request->query('location');

            if (is_string($location) && Str::isUuid($location)) {
                $query->whereHas('spot', fn ($spot) => $spot->where('location_id', $location));
            } else {
                $query->whereRaw('false');
            }
        }

        if ($request->query->has('spot')) {
            $code = Spot::normalizeCode((string) $request->query('spot'));

            if ($code === null) {
                $query->whereRaw('false');
            } else {
                $query->whereHas('spot', fn ($spot) => $spot->where('code', $code));
            }
        }

        $search->apply($query, (string) $request->query('search', ''));

        return ItemResource::collection($query->latest()->paginate(24)->withQueryString());
    }

    public function show(Item $item): ItemResource
    {
        return new ItemResource($item->load('spot.location'));
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        $conflict = 'Wird gerade neu generiert. Deine Änderungen wurden nicht gespeichert.';

        abort_if(Item::query()->whereKey($item->getKey())->whereNotNull('regenerating_at')->exists(), 409, $conflict);

        $data = $request->validated();

        if (array_key_exists('spot', $data)) {
            unset($data['spot']);
            $data['spot_id'] = $request->spotId();
        }

        $item->update($data);

        return new ItemResource($item->load('spot.location'));
    }

    public function destroy(Item $item): Response
    {
        $item->delete();

        return response()->noContent();
    }
}

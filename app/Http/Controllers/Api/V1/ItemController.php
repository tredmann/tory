<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\ItemSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreItemRequest;
use App\Http\Requests\Api\V1\UpdateItemRequest;
use App\Http\Resources\Api\V1\ItemResource;
use App\Models\Item;
use App\Models\Spot;
use App\Photos\PhotoStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ItemController extends Controller
{
    public function index(Request $request, ItemSearch $search): AnonymousResourceCollection
    {
        $query = Item::query()->with('spot.location');

        if ($request->filled('location') || is_array($request->query('location'))) {
            $location = $request->query('location');

            if (is_string($location) && Str::isUuid($location)) {
                $query->whereHas('spot', fn ($spot) => $spot->where('location_id', $location));
            } else {
                $query->whereRaw('false');
            }
        }

        if ($request->filled('spot') || is_array($request->query('spot'))) {
            $spotQuery = $request->query('spot');
            $code = is_string($spotQuery) ? Spot::normalizeCode($spotQuery) : null;

            if ($code === null) {
                $query->whereRaw('false');
            } else {
                $query->whereHas('spot', fn ($spot) => $spot->where('code', $code));
            }
        }

        $term = $request->query('search', '');
        $search->apply($query, is_string($term) ? $term : '');

        return ItemResource::collection($query->latest()->latest('id')->paginate(24)->withQueryString());
    }

    public function show(Item $item): ItemResource
    {
        return new ItemResource($item->load('spot.location'));
    }

    /**
     * Create an item with photos and texts.
     *
     * Stores the item directly, without an import and without the server's object recognition: the client supplies title, description and search terms. The search embedding is computed in the background as for every item.
     */
    public function store(StoreItemRequest $request): JsonResponse
    {
        $spotId = $request->spotId();
        $paths = [];

        try {
            /** @var UploadedFile $file */
            foreach ($request->file('photos') as $file) {
                $path = PhotoStore::store($file);

                if ($path === false) {
                    throw new RuntimeException('Photo could not be stored.');
                }

                $paths[] = $path;
            }

            $item = Item::create([
                'spot_id' => $spotId,
                'title' => $request->validated('title'),
                'description' => $request->validated('description'),
                'search_terms' => $request->validated('search_terms') ?? [],
                'photos' => $paths,
            ]);
        } catch (Throwable $e) {
            PhotoStore::delete($paths);

            throw $e;
        }

        $request->user()->forceFill(['last_spot_id' => $spotId])->save();

        return (new ItemResource($item->load('spot.location')))
            ->response()
            ->setStatusCode(201);
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

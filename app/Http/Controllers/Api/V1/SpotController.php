<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSpotRequest;
use App\Http\Resources\Api\V1\SpotResource;
use App\Models\Spot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpotController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $spots = Spot::query()
            ->join('locations', 'locations.id', '=', 'spots.location_id')
            ->select('spots.*')
            ->with('location')
            ->withCount('items')
            ->orderBy('locations.name')
            ->orderBy('spots.name')
            ->orderBy('spots.code')
            ->get();

        return SpotResource::collection($spots);
    }

    public function show(Spot $spot): SpotResource
    {
        return new SpotResource($spot->load('location')->loadCount('items'));
    }

    public function store(StoreSpotRequest $request): JsonResponse
    {
        $spot = Spot::create([
            'name' => $request->validated('name'),
            'note' => $request->validated('note'),
            'location_id' => $request->validated('location'),
        ]);

        return new SpotResource($spot->load('location')->loadCount('items'))
            ->response()
            ->setStatusCode(201);
    }
}

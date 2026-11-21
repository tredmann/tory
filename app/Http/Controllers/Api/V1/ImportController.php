<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConvertImportRequest;
use App\Http\Requests\Api\V1\StoreImportRequest;
use App\Http\Resources\Api\V1\ImportResource;
use App\Http\Resources\Api\V1\ItemResource;
use App\Jobs\ExtractImport;
use App\Models\Import;
use App\Photos\PhotoStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ImportController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ImportResource::collection(
            Import::query()->with('spot.location')->latest()->latest('id')->paginate(24),
        );
    }

    public function show(Import $import): ImportResource
    {
        return new ImportResource($import->load('spot.location'));
    }

    public function store(StoreImportRequest $request): JsonResponse
    {
        $paths = array_map(
            fn ($file): string => (string) PhotoStore::store($file),
            $request->file('photos'),
        );
        $spotId = $request->spotId();

        $import = Import::create([
            'user_id' => $request->user()->id,
            'spot_id' => $spotId,
            'status' => ImportStatus::Pending,
            'photos' => $paths,
        ]);

        $request->user()->forceFill(['last_spot_id' => $spotId])->save();

        ExtractImport::dispatch($import);

        return (new ImportResource($import->load('spot.location')))
            ->response()
            ->setStatusCode(201);
    }

    public function retry(Import $import): ImportResource
    {
        abort_unless($import->status === ImportStatus::Failed, 409, __('Only failed imports can be restarted.'));

        $import->restart();

        return new ImportResource($import->load('spot.location'));
    }

    public function convert(ConvertImportRequest $request, Import $import): JsonResponse
    {
        abort_unless($import->isFinished() && ! $import->isRegenerating(), 409, __('The import cannot be turned into an item right now.'));

        $spotId = $request->spotId();

        $item = $import->convertToItem(
            $request->validated('title'),
            $request->validated('description'),
            $spotId,
            $request->validated('search_terms') ?? [],
        );

        $request->user()->forceFill(['last_spot_id' => $spotId])->save();

        return (new ItemResource($item->load('spot.location')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Import $import): Response
    {
        abort_unless($import->isFinished(), 409, __('The import is still running.'));

        $import->delete();

        return response()->noContent();
    }
}

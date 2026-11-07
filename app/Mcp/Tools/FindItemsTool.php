<?php

namespace App\Mcp\Tools;

use App\Ai\ItemSearch;
use App\Mcp\SpotSummary;
use App\Models\Item;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Sucht Gegenstände nach Stichwort oder Bedeutung und nennt für jeden den Lagerplatz und Lagerort.')]
#[IsReadOnly]
class FindItemsTool extends Tool
{
    private const int LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Wonach gesucht wird, zum Beispiel "Bohrmaschine".')->required(),
        ];
    }

    public function handle(Request $request, ItemSearch $search): ResponseFactory
    {
        $validated = $request->validate(['query' => ['required', 'string']]);

        $query = Item::query()->with('spot.location');
        $search->apply($query, $validated['query']);

        $items = $query->latest()->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (Item $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description === null ? null : Str::limit($item->description, 200),
                'spot' => SpotSummary::of($item->spot),
            ])
            ->all();

        if ($items === []) {
            return Response::make(Response::text('Nichts gefunden.'))->withStructuredContent(['items' => []]);
        }

        $lines = array_map(
            fn (array $item): string => "{$item['title']} ({$item['id']}): {$item['spot']['name']} ({$item['spot']['code']}), {$item['spot']['location']}",
            $items,
        );

        return Response::make(Response::text(implode("\n", $lines)))->withStructuredContent(['items' => $items]);
    }
}

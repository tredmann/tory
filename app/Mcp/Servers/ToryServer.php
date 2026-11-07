<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\FindItemsTool;
use App\Mcp\Tools\FindSpotsTool;
use App\Mcp\Tools\MoveItemTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Tory')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    Tory ist das Inventar eines Haushalts und beantwortet die Frage, wo etwas ist. Gegenstände liegen auf Lagerplätzen, Lagerplätze gehören zu Lagerorten (zum Beispiel ein Regal im Keller).
    Suche immer zuerst, bevor du etwas änderst. Passen mehrere Gegenstände oder Lagerplätze oder passt keiner, frage den Nutzer, statt selbst einen auszuwählen.
    Frage nach, bevor du einen neuen Lagerplatz anlegst.
    TEXT)]
class ToryServer extends Server
{
    protected array $tools = [
        FindItemsTool::class,
        FindSpotsTool::class,
        MoveItemTool::class,
    ];
}

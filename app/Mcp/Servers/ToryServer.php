<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateSpotTool;
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
    Tory is a household's inventory and answers the question of where something is. Items lie on spots, and spots belong to locations (for example a shelf in the cellar).
    Always search first before you change anything. If several items or spots match, or none does, ask the user instead of choosing one yourself.
    Ask before you create a new spot.
    TEXT)]
class ToryServer extends Server
{
    protected array $tools = [
        FindItemsTool::class,
        FindSpotsTool::class,
        MoveItemTool::class,
        CreateSpotTool::class,
    ];
}

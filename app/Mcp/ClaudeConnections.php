<?php

namespace App\Mcp;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;

class ClaudeConnections
{
    /**
     * One connection per client with a live access token of the user, newest first.
     *
     * @return Collection<int, ClaudeConnection>
     */
    public function for(User $user): Collection
    {
        return $user->oauthTokens()
            ->where('revoked', false)
            ->with('client')
            ->get()
            ->filter(fn (Token $token): bool => $token->client !== null)
            ->groupBy('client_id')
            ->map(function (Collection $tokens): ClaudeConnection {
                $first = $tokens->sortBy('created_at')->first();
                $redirect = $first->client->redirect_uris[0] ?? null;

                return new ClaudeConnection(
                    clientId: (string) $first->client_id,
                    name: $first->client->name,
                    host: $redirect === null ? null : (parse_url($redirect, PHP_URL_HOST) ?: null),
                    connectedAt: CarbonImmutable::instance($first->created_at),
                );
            })
            ->sortByDesc(fn (ClaudeConnection $connection) => $connection->connectedAt)
            ->values();
    }

    /** Revokes the user's access and refresh tokens for one client. */
    public function disconnect(User $user, string $clientId): void
    {
        DB::transaction(function () use ($user, $clientId): void {
            $user->oauthTokens()
                ->where('client_id', $clientId)
                ->with('refreshToken')
                ->get()
                ->each(function (Token $token): void {
                    $token->revoke();
                    $token->refreshToken?->revoke();
                });
        });
    }
}

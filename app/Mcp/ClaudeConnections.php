<?php

namespace App\Mcp;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Token;

class ClaudeConnections
{
    /**
     * One connection per client for which the user holds a non-revoked access token, newest first.
     * The connection date is the user's oldest token for the client, revoked or not, because a
     * refresh revokes the previous token.
     *
     * @return Collection<int, ClaudeConnection>
     */
    public function for(User $user): Collection
    {
        return $user->oauthTokens()
            ->with('client')
            ->get()
            ->filter(fn (Token $token): bool => $token->client !== null)
            ->groupBy('client_id')
            ->filter(fn (Collection $tokens): bool => $tokens->contains('revoked', false))
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
            ->sortByDesc(fn (ClaudeConnection $connection): CarbonImmutable => $connection->connectedAt)
            ->values();
    }

    /** Revokes the user's access tokens, refresh tokens and unexchanged authorization codes for one client. */
    public function disconnect(User $user, string $clientId): void
    {
        DB::transaction(function () use ($user, $clientId): void {
            AuthCode::query()
                ->where('user_id', $user->getKey())
                ->where('client_id', $clientId)
                ->update(['revoked' => true]);

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

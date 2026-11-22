<?php

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;

use Goemktg\Seat\SeatCustomPlugin\Support\DiscordId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Web\Http\Controllers\Controller;

/**
 * Which SeAT accounts a Discord user is linked to, for bots that hold a SeAT API token.
 *
 * Reads the seat-connector link table directly: its own API (GET /api/v2/seat-connector/users)
 * answers every field as null in seat-connector 3.1.2, because UserResource declares public
 * properties that hide JsonResource::__get. Only the account id goes out; names, e-mail
 * addresses and the other link columns stay in SeAT. The table is never written.
 *
 * GET /api/v2/custom-plugin/discord-links?discord_id=<digits>, behind SeAT's api.request and
 * api.auth middleware (X-Token header, the token's allowed source address).
 */
class DiscordLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $discordId = DiscordId::parse($request->query('discord_id'));
        if ($discordId === null) {
            return response()->json(['message' => 'discord_id must be 1 to 20 digits'], 422);
        }
        if (! Schema::hasTable('seat_connector_users')) {
            return response()->json(['message' => 'seat-connector is not installed'], 503);
        }

        // connector_type and connector_id are unique together, so this is at most one row
        $userIds = DB::table('seat_connector_users')
            ->where('connector_type', 'discord')
            ->where('connector_id', $discordId)
            ->orderBy('user_id')
            ->pluck('user_id');

        return response()->json([
            'data' => $userIds->map(static fn ($id): array => ['user_id' => (int) $id])->values()->all(),
        ]);
    }
}

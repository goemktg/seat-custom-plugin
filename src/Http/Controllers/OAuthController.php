<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;

use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\Client;
use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\OAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in with SeAT for the applications in the clients file: OAuth 2.0
 * authorization code grant with PKCE. Off (404) until
 * CUSTOM_PLUGIN_OAUTH_CLIENTS_FILE names a valid file.
 *
 * Any active user whose main character is theirs may sign in. An application
 * can narrow that with allowed_role_ids; otherwise it decides from the roles
 * in userinfo.
 *
 * Limits on token and userinfo are counted here, not by route middleware:
 * requests without valid credentials count per address, and requests with
 * them count per application (and user). Behind a proxy many callers can share
 * one address, and anonymous traffic must not use up an application's budget.
 * Credentials are therefore checked before the address budget; guessing is
 * stopped by their size (random secrets, 256-bit tokens), not by the limit.
 */
class OAuthController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

    /** Requests a minute, see the class comment. */
    private const FAILED_TOKEN_PER_ADDRESS = 30;
    private const FAILED_USERINFO_PER_ADDRESS = 60;
    private const TOKEN_PER_CLIENT = 120;
    private const USERINFO_PER_CLIENT_AND_USER = 60;
    private const AUTHORIZE_PER_CLIENT_AND_USER = 30;

    public function authorizeRequest(Request $request, OAuthServer $server): Response
    {
        abort_unless($server->enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        // per application and user, so a page that keeps bouncing the browser to one application
        // cannot use up the user's sign-in to the others
        $client = $server->client($request->query('client_id'));
        $key = 'custom-plugin-oauth-authorize:' . ($client?->id() ?? '-') . ':' . $user->id;
        if (RateLimiter::hit($key, 60) > self::AUTHORIZE_PER_CLIENT_AND_USER) {
            return response('Too many sign-in attempts. Try again in a minute.', 429, self::NO_STORE + [
                'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
            ]);
        }
        $result = $server->authorize(
            $request->query(),
            (int) $user->id,
            static fn (Client $client): bool => self::mayUse($user)
                && $client->admits($user->isAdmin(), self::roleIds($user)),
        );

        if ($result->isRejected()) {
            return response()->view('custom-plugin::oauth.error', ['message' => $result->reason()], 400)
                ->withHeaders(self::NO_STORE);
        }

        return redirect()->away($result->redirectUrl())->withHeaders(self::NO_STORE);
    }

    public function token(Request $request, OAuthServer $server): JsonResponse
    {
        abort_unless($server->enabled(), 404);

        $failedKey = 'custom-plugin-oauth-token-failed:' . sha1((string) $request->ip());

        // only a form body counts: a code or secret in the query string is ignored, and a JSON body is refused
        if ($request->isJson()) {
            return self::spend($failedKey, self::FAILED_TOKEN_PER_ADDRESS)
                ?? response()->json(['error' => 'invalid_request'], 400, self::NO_STORE);
        }
        $form = $request->request->all();
        $authentication = $server->authenticateClient($form, $request->header('Authorization'));
        $client = $authentication->client();
        $limited = $client !== null
            ? self::spend('custom-plugin-oauth-token-client:' . $client->id(), self::TOKEN_PER_CLIENT)
            : self::spend($failedKey, self::FAILED_TOKEN_PER_ADDRESS);
        if ($limited !== null) {
            return $limited;
        }
        $result = $server->token($form, $authentication);

        return response()->json($result->body(), $result->status(), self::NO_STORE + $result->headers());
    }

    public function userinfo(Request $request, OAuthServer $server): JsonResponse
    {
        abort_unless($server->enabled(), 404);

        $grant = $server->bearer($request->header('Authorization'));
        $user = $grant === null ? null : User::find($grant->userId());
        if ($grant === null || $user === null) {
            $failedKey = 'custom-plugin-oauth-userinfo-failed:' . sha1((string) $request->ip());

            return self::spend($failedKey, self::FAILED_USERINFO_PER_ADDRESS)
                ?? response()->json(['error' => 'invalid_token'], 401, self::NO_STORE + [
                    'WWW-Authenticate' => 'Bearer error="invalid_token"',
                ]);
        }
        $client = $grant->client();
        $userKey = 'custom-plugin-oauth-userinfo:' . $client->id() . ':' . $user->id;
        $limited = self::spend($userKey, self::USERINFO_PER_CLIENT_AND_USER);
        if ($limited !== null) {
            return $limited;
        }
        // checked again on every call: the account, its main character or its roles may have changed since sign-in
        $roles = $user->roles()->orderBy('roles.id')->get(['roles.id', 'roles.title']);
        $roleIds = $roles->map(static fn ($role): int => (int) $role->id)->all();
        if (! self::mayUse($user) || ! $client->admits($user->isAdmin(), $roleIds)) {
            return response()->json(['error' => 'access_denied'], 403, self::NO_STORE);
        }

        return response()->json([
            'sub' => (string) $user->id,
            'character_id' => (int) $user->main_character_id,
            'name' => (string) $user->main_character->name,
            'admin' => $user->isAdmin(),
            'roles' => $roles
                ->map(static fn ($role): array => ['id' => (int) $role->id, 'title' => (string) $role->title])
                ->all(),
        ], 200, self::NO_STORE);
    }

    /** An active account whose main character exists and is still linked to it. */
    private static function mayUse(User $user): bool
    {
        return $user->active
            && $user->main_character_id !== null
            // main_character has withDefault(): an empty model, not null, when the character is gone
            && $user->main_character->exists
            // SeAT can link the main character into another account and leave main_character_id behind;
            // the token row says who owns it (a soft-deleted row is a lapsed ESI token, still owned)
            && $user->refresh_tokens()->withTrashed()->whereKey($user->main_character_id)->exists();
    }

    /** @return list<int> the user's SeAT role ids, squad roles included (SeAT copies them into role_user) */
    private static function roleIds(User $user): array
    {
        // not map('intval'): Collection::map passes the key as a second argument, which intval reads as the base
        return $user->roles()->pluck('roles.id')->map(static fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * Counts one request against a per-minute budget; a 429 response once it is used up.
     * Counting first and comparing the returned count keeps concurrent requests from overshooting.
     */
    private static function spend(string $key, int $perMinute): ?JsonResponse
    {
        if (RateLimiter::hit($key, 60) <= $perMinute) {
            return null;
        }

        return response()->json(['error' => 'too_many_requests'], 429, self::NO_STORE + [
            'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
        ]);
    }
}

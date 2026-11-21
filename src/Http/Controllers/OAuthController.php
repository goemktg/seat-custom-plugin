<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;

use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\OAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in with SeAT for the intel archive: OAuth 2.0 authorization code
 * grant with PKCE. Off (404) until all three CUSTOM_PLUGIN_OAUTH_INTEL_* values are set.
 *
 * Limits on token and userinfo are counted here, not by route middleware:
 * requests without valid credentials count per address, and requests with
 * them count per client or per user. Behind a proxy many callers can share
 * one address, and anonymous traffic must not use up the archive's budget.
 * Credentials are therefore checked before the address budget; guessing is
 * stopped by their size (a secret of at least 32 characters, 256-bit tokens),
 * not by the limit.
 */
class OAuthController extends Controller
{
    /** Global permission a user needs to sign in to the intel archive. */
    public const ABILITY = 'global.intel_archive_view';

    private const NO_STORE = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

    /** Requests a minute, see the class comment. */
    private const FAILED_TOKEN_PER_ADDRESS = 30;
    private const FAILED_USERINFO_PER_ADDRESS = 60;
    private const TOKEN_PER_CLIENT = 120;
    private const USERINFO_PER_USER = 60;

    public function authorizeRequest(Request $request, OAuthServer $server): Response
    {
        abort_unless($server->enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        $result = $server->authorize($request->query(), (int) $user->id, self::mayUse($user));

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
        $limited = $server->authenticatesClient($form)
            ? self::spend('custom-plugin-oauth-token-client', self::TOKEN_PER_CLIENT)
            : self::spend($failedKey, self::FAILED_TOKEN_PER_ADDRESS);
        if ($limited !== null) {
            return $limited;
        }
        $result = $server->token($form);

        return response()->json($result->body(), $result->status(), self::NO_STORE);
    }

    public function userinfo(Request $request, OAuthServer $server): JsonResponse
    {
        abort_unless($server->enabled(), 404);

        $userId = $server->userIdFor($request->header('Authorization'));
        $user = $userId === null ? null : User::find($userId);
        if ($user === null) {
            return self::spend('custom-plugin-oauth-userinfo-failed:' . sha1((string) $request->ip()), self::FAILED_USERINFO_PER_ADDRESS)
                ?? response()->json(['error' => 'invalid_token'], 401, self::NO_STORE + [
                    'WWW-Authenticate' => 'Bearer error="invalid_token"',
                ]);
        }
        $limited = self::spend('custom-plugin-oauth-userinfo-user:' . $user->id, self::USERINFO_PER_USER);
        if ($limited !== null) {
            return $limited;
        }
        // checked again on every call: the permission may have been taken away since sign-in
        if (! self::mayUse($user)) {
            return response()->json(['error' => 'access_denied'], 403, self::NO_STORE);
        }

        return response()->json([
            'sub' => (string) $user->id,
            'character_id' => (int) $user->main_character_id,
            'name' => (string) $user->main_character->name,
        ], 200, self::NO_STORE);
    }

    private static function mayUse(User $user): bool
    {
        return $user->active
            && $user->main_character_id !== null
            // main_character has withDefault(): an empty model, not null, when the character is gone
            && $user->main_character->exists
            // SeAT can link the main character into another account and leave main_character_id behind;
            // the token row says who owns it (a soft-deleted row is a lapsed ESI token, still owned)
            && $user->refresh_tokens()->withTrashed()->whereKey($user->main_character_id)->exists()
            && Gate::forUser($user)->allows(self::ABILITY);
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

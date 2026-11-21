<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\ClientConfig;
use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\OAuthServer;
use Goemktg\Seat\SeatCustomPlugin\Tests\Fakes\InMemoryGrantStore;
use PHPUnit\Framework\TestCase;

final class OAuthServerTest extends TestCase
{
    private const CLIENT = [
        'client_id' => 'intel-archive',
        'client_secret' => 'a-long-random-secret-value-0123456789',
        'redirect_uri' => 'https://intel.nisuwaz.com/auth/callback',
    ];

    // RFC 7636 appendix B example pair
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private const USER = 42;

    private int $now = 1_800_000_000;
    private InMemoryGrantStore $store;
    private OAuthServer $server;

    protected function setUp(): void
    {
        $clock = fn () => $this->now;
        $this->store = new InMemoryGrantStore($clock);
        $this->server = new OAuthServer(ClientConfig::fromArray(self::CLIENT), $this->store, $clock);
    }

    private function query(array $override = []): array
    {
        return array_filter(array_merge([
            'response_type' => 'code',
            'client_id' => self::CLIENT['client_id'],
            'redirect_uri' => self::CLIENT['redirect_uri'],
            'state' => 'xyz-state',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ], $override), static fn ($v) => $v !== null);
    }

    /** @return array<string, string> query of the address the browser is sent back to */
    private function redirectQuery(?string $url): array
    {
        $this->assertNotNull($url);
        $this->assertStringStartsWith(self::CLIENT['redirect_uri'] . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function issueCode(): string
    {
        $result = $this->server->authorize($this->query(), self::USER, true);
        $this->assertFalse($result->isRejected());

        return $this->redirectQuery($result->redirectUrl())['code'];
    }

    private function tokenForm(string $code, array $override = []): array
    {
        return array_filter(array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::CLIENT['redirect_uri'],
            'client_id' => self::CLIENT['client_id'],
            'client_secret' => self::CLIENT['client_secret'],
            'code_verifier' => self::VERIFIER,
        ], $override), static fn ($v) => $v !== null);
    }

    private function accessToken(): string
    {
        $result = $this->server->token($this->tokenForm($this->issueCode()));
        $this->assertSame(200, $result->status());

        return $result->body()['access_token'];
    }

    private function stretchStoreLifetimes(): void
    {
        foreach ($this->store->items as &$item) {
            $item['until'] += 3600;
        }
        unset($item);
    }

    // ---- authorize ----

    public function testAuthorizeSendsACodeAndTheStateBack(): void
    {
        $result = $this->server->authorize($this->query(), self::USER, true);

        $query = $this->redirectQuery($result->redirectUrl());
        $this->assertSame(['code', 'state'], array_keys($query));
        $this->assertSame('xyz-state', $query['state']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code']);
    }

    public function testCodeIsKeptOnlyAsAHash(): void
    {
        $code = $this->issueCode();

        foreach ($this->store->items as $key => $item) {
            $this->assertStringNotContainsString($code, $key);
            $this->assertStringNotContainsString($code, serialize($item));
        }
        $this->assertContains('code:' . hash('sha256', $code), $this->store->keys());
    }

    public function testCodesDiffer(): void
    {
        $this->assertNotSame($this->issueCode(), $this->issueCode());
    }

    public function testUnknownClientOrRedirectIsRejectedWithoutRedirect(): void
    {
        foreach ([
            ['client_id' => 'other'],
            ['client_id' => null],
            ['client_id' => ['intel-archive']],
            ['redirect_uri' => 'https://evil.example/cb'],
            ['redirect_uri' => self::CLIENT['redirect_uri'] . '/'],
            ['redirect_uri' => self::CLIENT['redirect_uri'] . '?x=1'],
            ['redirect_uri' => null],
        ] as $override) {
            $result = $this->server->authorize($this->query($override), self::USER, true);
            $this->assertTrue($result->isRejected(), json_encode($override));
            $this->assertNull($result->redirectUrl());
        }
        $this->assertSame([], $this->store->keys());
    }

    public function testBadRequestsGoBackAsInvalidRequest(): void
    {
        foreach ([
            ['response_type' => 'token'],
            ['response_type' => null],
            ['code_challenge_method' => 'plain'],
            ['code_challenge_method' => null],
            ['code_challenge' => null],
            ['code_challenge' => 'too-short'],
            ['code_challenge' => self::CHALLENGE . '='],
            ['code_challenge' => [self::CHALLENGE]],
            ['code_challenge' => self::CHALLENGE . "\n"],
        ] as $override) {
            $result = $this->server->authorize($this->query($override), self::USER, true);
            $this->assertFalse($result->isRejected(), json_encode($override));
            $this->assertSame(['error' => 'invalid_request', 'state' => 'xyz-state'], $this->redirectQuery($result->redirectUrl()), json_encode($override));
        }
        $this->assertSame([], $this->store->keys());
    }

    public function testMissingOrOversizedStateGoesBackWithoutState(): void
    {
        foreach ([null, '', str_repeat('s', 501), ['a']] as $state) {
            $result = $this->server->authorize($this->query(['state' => $state]), self::USER, true);
            $this->assertSame(['error' => 'invalid_request'], $this->redirectQuery($result->redirectUrl()), var_export($state, true));
        }
        $this->assertSame([], $this->store->keys());
    }

    public function testUserWithoutPermissionGoesBackAsAccessDenied(): void
    {
        $result = $this->server->authorize($this->query(), self::USER, false);

        $this->assertSame(['error' => 'access_denied', 'state' => 'xyz-state'], $this->redirectQuery($result->redirectUrl()));
        $this->assertSame([], $this->store->keys());
    }

    public function testRegisteredRedirectWithItsOwnQueryKeepsIt(): void
    {
        $uri = 'https://intel.nisuwaz.com/cb?from=seat';
        $server = new OAuthServer(ClientConfig::fromArray(['redirect_uri' => $uri] + self::CLIENT), $this->store, fn () => $this->now);

        $url = $server->authorize($this->query(['redirect_uri' => $uri]), self::USER, true)->redirectUrl();

        $this->assertStringStartsWith($uri . '&code=', $url);
    }

    public function testStateIsEncodedNotInjected(): void
    {
        $result = $this->server->authorize($this->query(['state' => 'a&code=evil#frag']), self::USER, true);

        $query = $this->redirectQuery($result->redirectUrl());
        $this->assertSame('a&code=evil#frag', $query['state']);
        $this->assertStringNotContainsString('#', $result->redirectUrl());
    }

    public function testFeatureOffRejectsEverything(): void
    {
        $server = new OAuthServer(ClientConfig::fromArray([]), $this->store, fn () => $this->now);

        $this->assertFalse($server->enabled());
        $this->assertTrue($server->authorize($this->query(), self::USER, true)->isRejected());
        $this->assertSame(401, $server->token($this->tokenForm(str_repeat('a', 43)))->status());
        $this->assertNull($server->userIdFor('Bearer ' . str_repeat('a', 43)));
        $this->assertTrue($this->server->enabled());
    }

    // ---- token ----

    public function testCodeIsExchangedForAFiveMinuteBearerToken(): void
    {
        $result = $this->server->token($this->tokenForm($this->issueCode()));

        $this->assertSame(200, $result->status());
        $body = $result->body();
        $this->assertSame(['access_token', 'token_type', 'expires_in'], array_keys($body));
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertSame(300, $body['expires_in']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $body['access_token']);
        $this->assertSame(self::USER, $this->server->userIdFor('Bearer ' . $body['access_token']));
    }

    public function testTokenIsKeptOnlyAsAHash(): void
    {
        $token = $this->accessToken();

        foreach ($this->store->items as $key => $item) {
            $this->assertStringNotContainsString($token, $key);
            $this->assertStringNotContainsString($token, serialize($item));
        }
    }

    public function testWrongClientCredentialsAreInvalidClient(): void
    {
        $code = $this->issueCode();
        foreach ([
            ['client_secret' => 'wrong'],
            ['client_secret' => null],
            ['client_id' => 'other'],
            ['client_id' => null],
            ['client_secret' => [self::CLIENT['client_secret']]],
        ] as $override) {
            $result = $this->server->token($this->tokenForm($code, $override));
            $this->assertSame([401, ['error' => 'invalid_client']], [$result->status(), $result->body()], json_encode($override));
        }
        // a caller that failed to authenticate does not burn the code
        $this->assertSame(200, $this->server->token($this->tokenForm($code))->status());
    }

    public function testAuthenticatesClientChecksTheFormCredentials(): void
    {
        $this->assertTrue($this->server->authenticatesClient(['client_id' => self::CLIENT['client_id'], 'client_secret' => self::CLIENT['client_secret']]));
        $this->assertFalse($this->server->authenticatesClient(['client_id' => self::CLIENT['client_id'], 'client_secret' => 'wrong']));
        $this->assertFalse($this->server->authenticatesClient(['client_id' => self::CLIENT['client_id']]));
        $this->assertFalse($this->server->authenticatesClient(['client_id' => ['x'], 'client_secret' => self::CLIENT['client_secret']]));
        $this->assertFalse($this->server->authenticatesClient([]));
    }

    public function testOnlyTheAuthorizationCodeGrantIsSupported(): void
    {
        $code = $this->issueCode();

        $result = $this->server->token($this->tokenForm($code, ['grant_type' => 'client_credentials']));
        $this->assertSame([400, ['error' => 'unsupported_grant_type']], [$result->status(), $result->body()]);

        $result = $this->server->token($this->tokenForm($code, ['grant_type' => null]));
        $this->assertSame([400, ['error' => 'invalid_request']], [$result->status(), $result->body()]);
    }

    public function testMissingOrMalformedFieldsAreInvalidRequest(): void
    {
        $code = $this->issueCode();
        foreach ([
            ['code' => null],
            ['code' => [$code]],
            ['redirect_uri' => null],
            ['redirect_uri' => [self::CLIENT['redirect_uri']]],
            ['code_verifier' => null],
            ['code_verifier' => 'short'],
            ['code_verifier' => str_repeat('a', 129)],
            ['code_verifier' => str_repeat('a', 42) . '!'],
            ['code_verifier' => self::VERIFIER . "\n"],
        ] as $override) {
            $result = $this->server->token($this->tokenForm($code, $override));
            $this->assertSame([400, ['error' => 'invalid_request']], [$result->status(), $result->body()], json_encode($override));
        }
    }

    public function testCodeCanBeUsedOnlyOnceAndReuseRevokesItsToken(): void
    {
        $code = $this->issueCode();
        $token = $this->server->token($this->tokenForm($code))->body()['access_token'];

        $again = $this->server->token($this->tokenForm($code));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$again->status(), $again->body()]);
        $this->assertNull($this->server->userIdFor('Bearer ' . $token));
    }

    public function testReuseRacingTheFirstExchangeStillLeavesNoToken(): void
    {
        $code = $this->issueCode();
        $second = null;
        // the second request arrives after the first stored its token but before it answered
        $this->store->afterPutToken = function () use ($code, &$second): void {
            $second = $this->server->token($this->tokenForm($code));
        };

        $first = $this->server->token($this->tokenForm($code));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$second->status(), $second->body()]);
        $this->assertSame([400, ['error' => 'invalid_grant']], [$first->status(), $first->body()]);
        $this->assertSame([], array_values(array_filter($this->store->keys(), static fn (string $k) => str_starts_with($k, 'token:'))));
    }

    public function testFailedExchangeStillBurnsTheCode(): void
    {
        $code = $this->issueCode();

        $wrong = $this->server->token($this->tokenForm($code, ['code_verifier' => str_repeat('A', 43)]));
        $this->assertSame([400, ['error' => 'invalid_grant']], [$wrong->status(), $wrong->body()]);

        $this->assertSame(400, $this->server->token($this->tokenForm($code))->status());
    }

    public function testWrongVerifierIsInvalidGrant(): void
    {
        $result = $this->server->token($this->tokenForm($this->issueCode(), ['code_verifier' => strrev(self::VERIFIER)]));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$result->status(), $result->body()]);
    }

    public function testRedirectMustMatchTheOneTheCodeWasIssuedFor(): void
    {
        $result = $this->server->token($this->tokenForm($this->issueCode(), ['redirect_uri' => 'https://intel.nisuwaz.com/other']));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$result->status(), $result->body()]);
    }

    public function testCodeWithTrailingNewlineIsNotTheCode(): void
    {
        $code = $this->issueCode();

        $this->assertSame(400, $this->server->token($this->tokenForm($code . "\n"))->status());
    }

    public function testUnknownCodeIsInvalidGrant(): void
    {
        $result = $this->server->token($this->tokenForm(str_repeat('a', 43)));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$result->status(), $result->body()]);
    }

    public function testCodeExpiresAfterSixtySeconds(): void
    {
        $code = $this->issueCode();
        $this->now += 60;

        $result = $this->server->token($this->tokenForm($code));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$result->status(), $result->body()]);
    }

    public function testCodeStillWorksJustBeforeExpiry(): void
    {
        $code = $this->issueCode();
        $this->now += 59;

        $this->assertSame(200, $this->server->token($this->tokenForm($code))->status());
    }

    public function testCodeExpiryIsCheckedEvenIfTheStoreKeepsTheCode(): void
    {
        $code = $this->issueCode();
        $this->stretchStoreLifetimes();
        $this->now += 61;

        $this->assertSame(400, $this->server->token($this->tokenForm($code))->status());
    }

    // ---- userinfo ----

    public function testTokenExpiresAfterFiveMinutes(): void
    {
        $token = $this->accessToken();

        $this->now += 299;
        $this->assertSame(self::USER, $this->server->userIdFor('Bearer ' . $token));

        $this->now += 1;
        $this->assertNull($this->server->userIdFor('Bearer ' . $token));
    }

    public function testTokenExpiryIsCheckedEvenIfTheStoreKeepsIt(): void
    {
        $token = $this->accessToken();
        $this->stretchStoreLifetimes();
        $this->now += 301;

        $this->assertNull($this->server->userIdFor('Bearer ' . $token));
    }

    public function testBearerHeaderParsing(): void
    {
        $token = $this->accessToken();

        $this->assertSame(self::USER, $this->server->userIdFor('bearer ' . $token));
        foreach ([null, '', $token, 'Basic ' . $token, 'Bearer', 'Bearer ' . $token . 'x', 'Bearer ' . $token . ' extra', 'Bearer ' . $token . "\n", 'Bearer ' . str_repeat('a', 43)] as $header) {
            $this->assertNull($this->server->userIdFor($header), var_export($header, true));
        }
    }

    public function testTheCodeItselfIsNotAnAccessToken(): void
    {
        $code = $this->issueCode();

        $this->assertNull($this->server->userIdFor('Bearer ' . $code));
    }

    public function testTokenStopsWorkingWhenTheClientIsSwitchedOff(): void
    {
        $token = $this->accessToken();
        $off = new OAuthServer(ClientConfig::fromArray([]), $this->store, fn () => $this->now);

        $this->assertNull($off->userIdFor('Bearer ' . $token));
    }
}

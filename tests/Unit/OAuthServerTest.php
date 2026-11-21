<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\Client;
use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\ClientRegistry;
use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\OAuthServer;
use Goemktg\Seat\SeatCustomPlugin\Tests\Fakes\InMemoryGrantStore;
use PHPUnit\Framework\TestCase;

final class OAuthServerTest extends TestCase
{
    private const ID = 'intel-archive';
    private const SECRET = 'a-long-random-secret-value-0123456789';
    private const REDIRECT = 'https://intel.nisuwaz.com/auth/callback';
    private const OTHER_REDIRECT = 'https://intel.nisuwaz.com/second';

    private const SRP_SECRET = 'srp-secret-srp-secret-srp-secret-0000';
    private const SRP_REDIRECT = 'https://srp.nisuwaz.com/cb';

    // RFC 7636 appendix B example pair
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private const USER = 42;

    private int $now = 1_800_000_000;
    private InMemoryGrantStore $store;
    private OAuthServer $server;

    /** @return array<string, mixed> */
    private static function file(): array
    {
        return ['clients' => [
            ['client_id' => self::ID, 'name' => 'Intel Archive', 'secret_sha256' => hash('sha256', self::SECRET), 'redirect_uris' => [self::REDIRECT, self::OTHER_REDIRECT]],
            ['client_id' => 'srp', 'name' => 'SRP', 'secret_sha256' => hash('sha256', self::SRP_SECRET), 'redirect_uris' => [self::SRP_REDIRECT]],
        ]];
    }

    private function serverFor(array $file): OAuthServer
    {
        return new OAuthServer(ClientRegistry::fromArray($file), $this->store, fn () => $this->now);
    }

    protected function setUp(): void
    {
        $this->store = new InMemoryGrantStore(fn () => $this->now);
        $this->server = $this->serverFor(self::file());
    }

    private static function allow(): \Closure
    {
        return static fn (Client $client): bool => true;
    }

    private function query(array $override = []): array
    {
        return array_filter(array_merge([
            'response_type' => 'code',
            'client_id' => self::ID,
            'redirect_uri' => self::REDIRECT,
            'state' => 'xyz-state',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ], $override), static fn ($v) => $v !== null);
    }

    /** @return array<string, string> query of the address the browser is sent back to */
    private function redirectQuery(?string $url, string $base = self::REDIRECT): array
    {
        $this->assertNotNull($url);
        $this->assertStringStartsWith($base . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function issueCode(array $override = []): string
    {
        $result = $this->server->authorize($this->query($override), self::USER, self::allow());
        $this->assertFalse($result->isRejected());

        return $this->redirectQuery($result->redirectUrl(), $override['redirect_uri'] ?? self::REDIRECT)['code'];
    }

    private function tokenForm(string $code, array $override = []): array
    {
        return array_filter(array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'client_id' => self::ID,
            'client_secret' => self::SECRET,
            'code_verifier' => self::VERIFIER,
        ], $override), static fn ($v) => $v !== null);
    }

    private function exchange(array $form, ?string $authorization = null): \Goemktg\Seat\SeatCustomPlugin\Support\OAuth\TokenResult
    {
        return $this->server->token($form, $this->server->authenticateClient($form, $authorization));
    }

    private static function basic(string $id, string $secret): string
    {
        return 'Basic ' . base64_encode(urlencode($id) . ':' . urlencode($secret));
    }

    private function accessToken(): string
    {
        $result = $this->exchange($this->tokenForm($this->issueCode()));
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
        $result = $this->server->authorize($this->query(), self::USER, self::allow());

        $query = $this->redirectQuery($result->redirectUrl());
        $this->assertSame(['code', 'state'], array_keys($query));
        $this->assertSame('xyz-state', $query['state']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code']);
    }

    public function testEachRegisteredRedirectGetsItsOwnCodeBack(): void
    {
        $result = $this->server->authorize($this->query(['redirect_uri' => self::OTHER_REDIRECT]), self::USER, self::allow());
        $this->assertArrayHasKey('code', $this->redirectQuery($result->redirectUrl(), self::OTHER_REDIRECT));

        $result = $this->server->authorize($this->query(['client_id' => 'srp', 'redirect_uri' => self::SRP_REDIRECT]), self::USER, self::allow());
        $this->assertArrayHasKey('code', $this->redirectQuery($result->redirectUrl(), self::SRP_REDIRECT));
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
            ['client_id' => [self::ID]],
            ['redirect_uri' => 'https://evil.example/cb'],
            ['redirect_uri' => self::REDIRECT . '/'],
            ['redirect_uri' => self::REDIRECT . '?x=1'],
            ['redirect_uri' => null],
            // another application's address
            ['redirect_uri' => self::SRP_REDIRECT],
            ['client_id' => 'srp'],
        ] as $override) {
            $result = $this->server->authorize($this->query($override), self::USER, self::allow());
            $this->assertTrue($result->isRejected(), json_encode($override));
            $this->assertNull($result->redirectUrl());
        }
        $this->assertSame([], $this->store->keys());
    }

    public function testBadRequestsGoBackAsInvalidRequest(): void
    {
        foreach ([
            ['response_type' => null],
            ['response_type' => ['code']],
            ['code_challenge_method' => 'plain'],
            ['code_challenge_method' => null],
            ['code_challenge' => null],
            ['code_challenge' => 'too-short'],
            ['code_challenge' => self::CHALLENGE . '='],
            ['code_challenge' => [self::CHALLENGE]],
            ['code_challenge' => self::CHALLENGE . "\n"],
        ] as $override) {
            $result = $this->server->authorize($this->query($override), self::USER, self::allow());
            $this->assertFalse($result->isRejected(), json_encode($override));
            $this->assertSame(['error' => 'invalid_request', 'state' => 'xyz-state'], $this->redirectQuery($result->redirectUrl()), json_encode($override));
        }
        $this->assertSame([], $this->store->keys());
    }

    public function testOtherResponseTypesGoBackAsUnsupported(): void
    {
        foreach (['token', 'code id_token', 'CODE', ''] as $type) {
            $result = $this->server->authorize($this->query(['response_type' => $type]), self::USER, self::allow());
            $this->assertSame(['error' => 'unsupported_response_type', 'state' => 'xyz-state'], $this->redirectQuery($result->redirectUrl()), $type);
        }
        $this->assertSame([], $this->store->keys());
    }

    public function testMissingOrOversizedStateGoesBackWithoutState(): void
    {
        foreach ([null, '', str_repeat('s', 501), ['a']] as $state) {
            $result = $this->server->authorize($this->query(['state' => $state]), self::USER, self::allow());
            $this->assertSame(['error' => 'invalid_request'], $this->redirectQuery($result->redirectUrl()), var_export($state, true));
        }
        $this->assertSame([], $this->store->keys());
    }

    public function testRefusedUserGoesBackAsAccessDeniedAndTheCheckSeesTheClient(): void
    {
        $seen = null;
        $result = $this->server->authorize($this->query(['client_id' => 'srp', 'redirect_uri' => self::SRP_REDIRECT]), self::USER, function (Client $client) use (&$seen): bool {
            $seen = $client->id();

            return false;
        });

        $this->assertSame('srp', $seen);
        $this->assertSame(['error' => 'access_denied', 'state' => 'xyz-state'], $this->redirectQuery($result->redirectUrl(), self::SRP_REDIRECT));
        $this->assertSame([], $this->store->keys());
    }

    public function testRegisteredRedirectWithItsOwnQueryKeepsIt(): void
    {
        $uri = 'https://intel.nisuwaz.com/cb?from=seat';
        $file = self::file();
        $file['clients'][0]['redirect_uris'] = [$uri];
        $server = $this->serverFor($file);

        $url = $server->authorize($this->query(['redirect_uri' => $uri]), self::USER, self::allow())->redirectUrl();

        $this->assertStringStartsWith($uri . '&code=', $url);
    }

    public function testStateIsEncodedNotInjected(): void
    {
        $result = $this->server->authorize($this->query(['state' => 'a&code=evil#frag']), self::USER, self::allow());

        $query = $this->redirectQuery($result->redirectUrl());
        $this->assertSame('a&code=evil#frag', $query['state']);
        $this->assertStringNotContainsString('#', $result->redirectUrl());
    }

    public function testFeatureOffRejectsEverything(): void
    {
        $server = $this->serverFor([]);

        $this->assertFalse($server->enabled());
        $this->assertTrue($server->authorize($this->query(), self::USER, self::allow())->isRejected());
        $form = $this->tokenForm(str_repeat('a', 43));
        $this->assertSame(401, $server->token($form, $server->authenticateClient($form, null))->status());
        $this->assertNull($server->bearer('Bearer ' . str_repeat('a', 43)));
        $this->assertTrue($this->server->enabled());
    }

    // ---- token ----

    public function testCodeIsExchangedForAFiveMinuteBearerToken(): void
    {
        $result = $this->exchange($this->tokenForm($this->issueCode()));

        $this->assertSame(200, $result->status());
        $this->assertSame([], $result->headers());
        $body = $result->body();
        $this->assertSame(['access_token', 'token_type', 'expires_in'], array_keys($body));
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertSame(300, $body['expires_in']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $body['access_token']);
        $grant = $this->server->bearer('Bearer ' . $body['access_token']);
        $this->assertSame(self::USER, $grant?->userId());
        $this->assertSame(self::ID, $grant?->client()->id());
    }

    public function testTokenIsKeptOnlyAsAHash(): void
    {
        $token = $this->accessToken();

        foreach ($this->store->items as $key => $item) {
            $this->assertStringNotContainsString($token, $key);
            $this->assertStringNotContainsString($token, serialize($item));
        }
    }

    public function testHttpBasicClientAuthentication(): void
    {
        $code = $this->issueCode();
        $form = array_diff_key($this->tokenForm($code), ['client_id' => 1, 'client_secret' => 1]);

        $result = $this->exchange($form, self::basic(self::ID, self::SECRET));

        $this->assertSame(200, $result->status(), json_encode($result->body()));
    }

    public function testBasicCredentialsAreFormDecoded(): void
    {
        $secret = 'a+b:c%d e/f=g-0123456789-0123456789';
        $file = self::file();
        $file['clients'][0]['secret_sha256'] = hash('sha256', $secret);
        $this->server = $this->serverFor($file);
        $form = array_diff_key($this->tokenForm($this->issueCode()), ['client_id' => 1, 'client_secret' => 1]);

        $this->assertSame(200, $this->exchange($form, self::basic(self::ID, $secret))->status());
    }

    public function testBasicSchemeIsCaseInsensitiveAndToleratesExtraSpace(): void
    {
        $form = array_diff_key($this->tokenForm($this->issueCode()), ['client_id' => 1, 'client_secret' => 1]);

        $this->assertSame(200, $this->exchange($form, 'basic  ' . base64_encode(self::ID . ':' . self::SECRET))->status());
    }

    public function testBasicWithTheSameClientIdInTheFormIsAccepted(): void
    {
        $form = array_diff_key($this->tokenForm($this->issueCode()), ['client_secret' => 1]);

        $this->assertSame(200, $this->exchange($form, self::basic(self::ID, self::SECRET))->status());
    }

    public function testBothBasicAndAFormSecretIsInvalidRequest(): void
    {
        $code = $this->issueCode();
        foreach ([
            $this->tokenForm($code),
            array_diff_key($this->tokenForm($code), ['client_id' => 1]),
            $this->tokenForm($code, ['client_id' => 'srp', 'client_secret' => null]),
        ] as $form) {
            $result = $this->exchange($form, self::basic(self::ID, self::SECRET));
            $this->assertSame([400, ['error' => 'invalid_request']], [$result->status(), $result->body()], json_encode(array_keys($form)));
        }
        // the code was not burnt
        $this->assertSame(200, $this->exchange($this->tokenForm($code))->status());
    }

    public function testFailedBasicIsInvalidClientWithAChallenge(): void
    {
        $code = $this->issueCode();
        $form = array_diff_key($this->tokenForm($code), ['client_id' => 1, 'client_secret' => 1]);
        foreach ([
            self::basic(self::ID, 'wrong'),
            self::basic('srp', self::SECRET),
            self::basic('other', self::SECRET),
            'Basic ' . base64_encode(self::ID),
            'Basic ' . base64_encode(':' . self::SECRET),
            'Basic not*base64',
            'Basic ',
            'Basic',
            "Basic\t" . base64_encode(self::ID . ':wrong'),
            'Basic  ' . base64_encode(self::ID . ':wrong'),
        ] as $header) {
            $result = $this->exchange($form, $header);
            $this->assertSame([401, ['error' => 'invalid_client']], [$result->status(), $result->body()], $header);
            $this->assertSame(['WWW-Authenticate' => 'Basic realm="SeAT"'], $result->headers(), $header);
        }
        $this->assertSame(200, $this->exchange($this->tokenForm($code))->status());
    }

    public function testWrongFormCredentialsAreInvalidClientWithoutAChallenge(): void
    {
        $code = $this->issueCode();
        foreach ([
            ['client_secret' => 'wrong'],
            ['client_secret' => null],
            ['client_id' => 'other'],
            ['client_id' => 'srp'],
            ['client_id' => null],
            ['client_secret' => [self::SECRET]],
        ] as $override) {
            $result = $this->exchange($this->tokenForm($code, $override));
            $this->assertSame([401, ['error' => 'invalid_client']], [$result->status(), $result->body()], json_encode($override));
            $this->assertSame([], $result->headers());
        }
        // a caller that failed to authenticate does not burn the code
        $this->assertSame(200, $this->exchange($this->tokenForm($code))->status());
    }

    public function testAnotherApplicationCannotExchangeTheCode(): void
    {
        $code = $this->issueCode();

        $result = $this->exchange($this->tokenForm($code, ['client_id' => 'srp', 'client_secret' => self::SRP_SECRET, 'redirect_uri' => self::SRP_REDIRECT]));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$result->status(), $result->body()]);
    }

    public function testAuthenticateClientNamesTheClient(): void
    {
        $this->assertSame(self::ID, $this->server->authenticateClient(['client_id' => self::ID, 'client_secret' => self::SECRET], null)->client()?->id());
        $this->assertSame('srp', $this->server->authenticateClient([], self::basic('srp', self::SRP_SECRET))->client()?->id());
        $this->assertNull($this->server->authenticateClient(['client_id' => self::ID, 'client_secret' => 'wrong'], null)->client());
        $this->assertNull($this->server->authenticateClient(['client_id' => self::ID], null)->client());
        $this->assertNull($this->server->authenticateClient(['client_id' => ['x'], 'client_secret' => self::SECRET], null)->client());
        $this->assertNull($this->server->authenticateClient([], null)->client());
        $this->assertNull($this->server->authenticateClient(['client_secret' => self::SECRET], self::basic(self::ID, self::SECRET))->client());
    }

    public function testOnlyTheAuthorizationCodeGrantIsSupported(): void
    {
        $code = $this->issueCode();

        $result = $this->exchange($this->tokenForm($code, ['grant_type' => 'client_credentials']));
        $this->assertSame([400, ['error' => 'unsupported_grant_type']], [$result->status(), $result->body()]);

        $result = $this->exchange($this->tokenForm($code, ['grant_type' => null]));
        $this->assertSame([400, ['error' => 'invalid_request']], [$result->status(), $result->body()]);
    }

    public function testMissingOrMalformedFieldsAreInvalidRequest(): void
    {
        $code = $this->issueCode();
        foreach ([
            ['code' => null],
            ['code' => [$code]],
            ['redirect_uri' => null],
            ['redirect_uri' => [self::REDIRECT]],
            ['code_verifier' => null],
            ['code_verifier' => 'short'],
            ['code_verifier' => str_repeat('a', 129)],
            ['code_verifier' => str_repeat('a', 42) . '!'],
            ['code_verifier' => self::VERIFIER . "\n"],
        ] as $override) {
            $result = $this->exchange($this->tokenForm($code, $override));
            $this->assertSame([400, ['error' => 'invalid_request']], [$result->status(), $result->body()], json_encode($override));
        }
    }

    public function testCodeCanBeUsedOnlyOnceAndReuseRevokesItsToken(): void
    {
        $code = $this->issueCode();
        $token = $this->exchange($this->tokenForm($code))->body()['access_token'];

        $again = $this->exchange($this->tokenForm($code));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$again->status(), $again->body()]);
        $this->assertNull($this->server->bearer('Bearer ' . $token));
    }

    public function testReuseRacingTheFirstExchangeStillLeavesNoToken(): void
    {
        $code = $this->issueCode();
        $second = null;
        // the second request arrives after the first stored its token but before it answered
        $this->store->afterPutToken = function () use ($code, &$second): void {
            $second = $this->exchange($this->tokenForm($code));
        };

        $first = $this->exchange($this->tokenForm($code));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$second->status(), $second->body()]);
        $this->assertSame([400, ['error' => 'invalid_grant']], [$first->status(), $first->body()]);
        $this->assertSame([], array_values(array_filter($this->store->keys(), static fn (string $k) => str_starts_with($k, 'token:'))));
    }

    public function testFailedExchangeStillBurnsTheCode(): void
    {
        $code = $this->issueCode();

        $wrong = $this->exchange($this->tokenForm($code, ['code_verifier' => str_repeat('A', 43)]));
        $this->assertSame([400, ['error' => 'invalid_grant']], [$wrong->status(), $wrong->body()]);

        $this->assertSame(400, $this->exchange($this->tokenForm($code))->status());
    }

    public function testWrongVerifierIsInvalidGrant(): void
    {
        $result = $this->exchange($this->tokenForm($this->issueCode(), ['code_verifier' => strrev(self::VERIFIER)]));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$result->status(), $result->body()]);
    }

    public function testRedirectMustMatchTheOneTheCodeWasIssuedFor(): void
    {
        // the other address is registered too, but the code went to the first one
        $result = $this->exchange($this->tokenForm($this->issueCode(), ['redirect_uri' => self::OTHER_REDIRECT]));
        $this->assertSame([400, ['error' => 'invalid_grant']], [$result->status(), $result->body()]);

        $code = $this->issueCode(['redirect_uri' => self::OTHER_REDIRECT]);
        $this->assertSame(200, $this->exchange($this->tokenForm($code, ['redirect_uri' => self::OTHER_REDIRECT]))->status());
    }

    public function testCodeWithTrailingNewlineIsNotTheCode(): void
    {
        $code = $this->issueCode();

        $this->assertSame(400, $this->exchange($this->tokenForm($code . "\n"))->status());
    }

    public function testUnknownCodeIsInvalidGrant(): void
    {
        $result = $this->exchange($this->tokenForm(str_repeat('a', 43)));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$result->status(), $result->body()]);
    }

    public function testCodeExpiresAfterSixtySeconds(): void
    {
        $code = $this->issueCode();
        $this->now += 60;

        $result = $this->exchange($this->tokenForm($code));

        $this->assertSame([400, ['error' => 'invalid_grant']], [$result->status(), $result->body()]);
    }

    public function testCodeStillWorksJustBeforeExpiry(): void
    {
        $code = $this->issueCode();
        $this->now += 59;

        $this->assertSame(200, $this->exchange($this->tokenForm($code))->status());
    }

    public function testCodeExpiryIsCheckedEvenIfTheStoreKeepsTheCode(): void
    {
        $code = $this->issueCode();
        $this->stretchStoreLifetimes();
        $this->now += 61;

        $this->assertSame(400, $this->exchange($this->tokenForm($code))->status());
    }

    // ---- userinfo ----

    public function testTokenExpiresAfterFiveMinutes(): void
    {
        $token = $this->accessToken();

        $this->now += 299;
        $this->assertSame(self::USER, $this->server->bearer('Bearer ' . $token)?->userId());

        $this->now += 1;
        $this->assertNull($this->server->bearer('Bearer ' . $token));
    }

    public function testTokenExpiryIsCheckedEvenIfTheStoreKeepsIt(): void
    {
        $token = $this->accessToken();
        $this->stretchStoreLifetimes();
        $this->now += 301;

        $this->assertNull($this->server->bearer('Bearer ' . $token));
    }

    public function testBearerHeaderParsing(): void
    {
        $token = $this->accessToken();

        $this->assertSame(self::USER, $this->server->bearer('bearer ' . $token)?->userId());
        foreach ([null, '', $token, 'Basic ' . $token, 'Bearer', 'Bearer ' . $token . 'x', 'Bearer ' . $token . ' extra', 'Bearer ' . $token . "\n", 'Bearer ' . str_repeat('a', 43)] as $header) {
            $this->assertNull($this->server->bearer($header), var_export($header, true));
        }
    }

    public function testTheCodeItselfIsNotAnAccessToken(): void
    {
        $code = $this->issueCode();

        $this->assertNull($this->server->bearer('Bearer ' . $code));
    }

    public function testTokenStopsWorkingWhenTheFeatureIsSwitchedOff(): void
    {
        $token = $this->accessToken();

        $this->assertNull($this->serverFor([])->bearer('Bearer ' . $token));
    }

    public function testTokenStopsWorkingWhenItsApplicationIsRemoved(): void
    {
        $token = $this->accessToken();
        $file = self::file();
        array_shift($file['clients']);

        $this->assertNull($this->serverFor($file)->bearer('Bearer ' . $token));
    }
}

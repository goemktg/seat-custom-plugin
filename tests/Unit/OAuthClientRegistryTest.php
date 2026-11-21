<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\ClientRegistry;
use PHPUnit\Framework\TestCase;

final class OAuthClientRegistryTest extends TestCase
{
    private const SECRET = 'a-long-random-secret-value-0123456789';

    /** @return array<string, mixed> */
    private static function client(array $override = []): array
    {
        return array_filter(array_merge([
            'client_id' => 'intel-archive',
            'name' => 'Intel Archive',
            'secret_sha256' => hash('sha256', self::SECRET),
            'redirect_uris' => ['https://intel.nisuwaz.com/auth/callback'],
        ], $override), static fn ($v) => $v !== null);
    }

    private static function registry(array ...$clients): ClientRegistry
    {
        return ClientRegistry::fromArray(['clients' => $clients]);
    }

    public function testAValidFileTurnsTheFeatureOn(): void
    {
        $registry = self::registry(self::client(), self::client(['client_id' => 'srp', 'name' => 'SRP', 'redirect_uris' => ['https://srp.nisuwaz.com/cb', 'http://localhost:8080/cb']]));

        $this->assertTrue($registry->enabled());
        $this->assertSame('Intel Archive', $registry->find('intel-archive')?->name());
        $this->assertSame('srp', $registry->find('srp')?->id());
    }

    public function testFindIsExact(): void
    {
        $registry = self::registry(self::client());

        foreach (['Intel-Archive', 'intel-archive ', ' intel-archive', '', null, ['intel-archive'], 1] as $id) {
            $this->assertNull($registry->find($id), var_export($id, true));
        }
    }

    public function testAnyBadClientTurnsTheWholeFeatureOff(): void
    {
        foreach ([
            'id missing' => ['client_id' => null],
            'id empty' => ['client_id' => ''],
            'id with space' => ['client_id' => 'intel archive'],
            'id with colon' => ['client_id' => 'intel:archive'],
            'id too long' => ['client_id' => str_repeat('a', 65)],
            'id not a string' => ['client_id' => 7],
            'name missing' => ['name' => null],
            'name empty' => ['name' => '  '],
            'hash missing' => ['secret_sha256' => null],
            'hash is the secret' => ['secret_sha256' => self::SECRET],
            'hash upper case' => ['secret_sha256' => strtoupper(hash('sha256', self::SECRET))],
            'hash too short' => ['secret_sha256' => substr(hash('sha256', self::SECRET), 1)],
            'no redirect' => ['redirect_uris' => []],
            'redirect not a list' => ['redirect_uris' => 'https://intel.nisuwaz.com/auth/callback'],
            'redirect map' => ['redirect_uris' => ['a' => 'https://intel.nisuwaz.com/auth/callback']],
            'redirect duplicated' => ['redirect_uris' => ['https://intel.nisuwaz.com/cb', 'https://intel.nisuwaz.com/cb']],
            'plain http' => ['redirect_uris' => ['http://intel.nisuwaz.com/cb']],
            'no scheme' => ['redirect_uris' => ['intel.nisuwaz.com/cb']],
            'javascript' => ['redirect_uris' => ['javascript:alert(1)']],
            'fragment' => ['redirect_uris' => ['https://intel.nisuwaz.com/cb#x']],
            'empty fragment' => ['redirect_uris' => ['https://intel.nisuwaz.com/cb#']],
            'no host' => ['redirect_uris' => ['https:///cb']],
            'ftp' => ['redirect_uris' => ['ftp://intel.nisuwaz.com/cb']],
            'user info' => ['redirect_uris' => ['https://user:pw@intel.nisuwaz.com/cb']],
            'user only' => ['redirect_uris' => ['https://user@intel.nisuwaz.com/cb']],
            'space' => ['redirect_uris' => ['https://intel.nisuwaz.com/c b']],
            'backslash' => ['redirect_uris' => ['https://intel.nisuwaz.com\\@evil.example/cb']],
            'control character' => ['redirect_uris' => ["https://intel.nisuwaz.com/cb\n"]],
            'redirect not a string' => ['redirect_uris' => [['https://intel.nisuwaz.com/cb']]],
            'roles not a list' => ['allowed_role_ids' => 3],
            'role id zero' => ['allowed_role_ids' => [0]],
            'role id string' => ['allowed_role_ids' => ['3']],
            'unknown key' => ['allowed_roles' => [3]],
        ] as $label => $override) {
            $client = self::client($override);
            if (array_key_exists('client_id', $override) && $override['client_id'] === null) {
                unset($client['client_id']);
            }
            $registry = self::registry($client, self::client(['client_id' => 'srp']));
            $this->assertFalse($registry->enabled(), $label);
            $this->assertNull($registry->find('srp'), $label . ': the good client is off too');
        }
    }

    public function testBadFileShapesTurnTheFeatureOff(): void
    {
        foreach ([null, [], ['clients' => []], ['clients' => 'x'], ['clients' => ['a' => self::client()]], ['clients' => [self::client()], 'extra' => 1], 'text', 5] as $decoded) {
            $this->assertFalse(ClientRegistry::fromArray($decoded)->enabled(), json_encode($decoded));
        }
        $this->assertFalse(self::registry(self::client(), self::client())->enabled(), 'duplicate client_id');
    }

    public function testAnOffRegistrySaysWhyWithoutTheSecrets(): void
    {
        $this->assertNull(self::registry(self::client())->problem());
        $this->assertSame('no clients file configured', ClientRegistry::fromFile(null)->problem());
        $this->assertSame('client 1 is invalid', self::registry(self::client(), self::client(['client_id' => 'srp', 'redirect_uris' => ['http://x.example/cb']]))->problem());
        $this->assertSame('client 1 repeats a client_id', self::registry(self::client(), self::client())->problem());
        $this->assertSame('the file must hold exactly one key, "clients", with a non-empty list', ClientRegistry::fromArray([])->problem());
        $problem = (string) self::registry(self::client(['secret_sha256' => 'x']))->problem();
        $this->assertStringNotContainsString(hash('sha256', self::SECRET), $problem);
    }

    public function testARepeatedKeyAnywhereInTheFileTurnsItOff(): void
    {
        $good = json_encode(self::client());
        $dir = sys_get_temp_dir() . '/oauth-dup-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $file = $dir . '/clients.json';
        try {
            foreach ([
                'role list given twice' => '{"clients": [' . substr($good, 0, -1) . ', "allowed_role_ids": [7], "allowed_role_ids": []}]}',
                'client_id twice' => '{"clients": [' . substr($good, 0, -1) . ', "client_id": "other"}]}',
                'clients twice' => '{"clients": [' . $good . '], "clients": [' . $good . ']}',
                // chr(92) is a backslash: "n" . "\\" . "u0061me" is the JSON escape for "name"
                'same key spelt with an escape' => '{"clients": [' . substr($good, 0, -1) . ', "n' . chr(92) . 'u0061me": "Other"}]}',
                'role list repeated with an escape' => '{"clients": [' . substr($good, 0, -1) . ', "allowed_role_ids": [7], "allowed_role_id' . chr(92) . 'u0073": []}]}',
            ] as $label => $json) {
                file_put_contents($file, $json);
                $registry = ClientRegistry::fromFile($file);
                $this->assertFalse($registry->enabled(), $label);
                $this->assertSame('the file repeats a key inside one object', $registry->problem(), $label);
            }
            // the same key in two different objects is fine, and so are keys inside string values
            file_put_contents($file, '{"clients": [' . $good . ', ' . json_encode(self::client(['client_id' => 'srp', 'name' => 'say \"client_id\": {x}'])) . ']}');
            $this->assertTrue(ClientRegistry::fromFile($file)->enabled());
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function testANullRoleListIsAnErrorNotNoRestriction(): void
    {
        $client = self::client();
        $client['allowed_role_ids'] = null;

        $this->assertFalse(self::registry($client)->enabled());
    }

    public function testRegistrationsThatStayOn(): void
    {
        foreach ([
            ['redirect_uris' => ['http://localhost:8080/cb']],
            ['redirect_uris' => ['http://127.0.0.1/cb']],
            ['redirect_uris' => ['https://intel.nisuwaz.com/cb?from=seat']],
            ['allowed_role_ids' => []],
            ['allowed_role_ids' => [3, 12]],
            ['client_id' => 'srp.app_1-x'],
        ] as $override) {
            $this->assertTrue(self::registry(self::client($override))->enabled(), json_encode($override));
        }
    }

    public function testRedirectMustBeOneOfTheRegisteredOnesExactly(): void
    {
        $client = self::registry(self::client(['redirect_uris' => ['https://intel.nisuwaz.com/auth/callback', 'https://intel.nisuwaz.com/other']]))->find('intel-archive');

        $this->assertTrue($client->hasRedirect('https://intel.nisuwaz.com/auth/callback'));
        $this->assertTrue($client->hasRedirect('https://intel.nisuwaz.com/other'));
        foreach (['https://intel.nisuwaz.com/auth/callback/', 'https://intel.nisuwaz.com/auth/callback?x=1', 'HTTPS://intel.nisuwaz.com/auth/callback', 'https://intel.nisuwaz.com.evil.example/auth/callback', '', null, ['https://intel.nisuwaz.com/other']] as $uri) {
            $this->assertFalse($client->hasRedirect($uri), var_export($uri, true));
        }
    }

    public function testSecretCheck(): void
    {
        $registry = self::registry(self::client(), self::client(['client_id' => 'srp', 'secret_sha256' => hash('sha256', 'srp-secret-srp-secret-srp-secret-0000')]));

        $this->assertSame('intel-archive', $registry->authenticate('intel-archive', self::SECRET)?->id());
        $this->assertSame('srp', $registry->authenticate('srp', 'srp-secret-srp-secret-srp-secret-0000')?->id());
        foreach ([
            ['intel-archive', 'wrong'],
            ['srp', self::SECRET],
            ['other', self::SECRET],
            ['intel-archive', null],
            ['intel-archive', [self::SECRET]],
            ['intel-archive', self::SECRET . "\n"],
            ['intel-archive', substr(self::SECRET, 0, -1)],
            ['intel-archive', hash('sha256', self::SECRET)],
            [null, self::SECRET],
        ] as [$id, $secret]) {
            $this->assertNull($registry->authenticate($id, $secret), json_encode([$id, $secret]));
        }
    }

    public function testAllowedRoles(): void
    {
        $open = self::registry(self::client())->find('intel-archive');
        $this->assertTrue($open->admits(false, []));

        $closed = self::registry(self::client(['allowed_role_ids' => [3, 12]]))->find('intel-archive');
        $this->assertTrue($closed->admits(false, [1, 12]));
        $this->assertTrue($closed->admits(true, []), 'a SeAT administrator passes');
        $this->assertFalse($closed->admits(false, []));
        $this->assertFalse($closed->admits(false, [1, 4]));
    }

    public function testAnOffRegistryMatchesNothing(): void
    {
        $registry = ClientRegistry::fromArray(['clients' => [self::client(['secret_sha256' => 'x'])]]);

        $this->assertNull($registry->find('intel-archive'));
        $this->assertNull($registry->authenticate('intel-archive', self::SECRET));
    }

    public function testFromFile(): void
    {
        $dir = sys_get_temp_dir() . '/oauth-registry-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $file = $dir . '/clients.json';
        try {
            file_put_contents($file, json_encode(['clients' => [self::client()]]));
            $this->assertTrue(ClientRegistry::fromFile($file)->enabled());

            file_put_contents($file, '{"clients": [');
            $this->assertFalse(ClientRegistry::fromFile($file)->enabled(), 'broken JSON');
            $this->assertSame('the file is not valid JSON', ClientRegistry::fromFile($file)->problem());

            file_put_contents($file, json_encode(['clients' => [self::client()]]) . str_repeat(' ', 70_000));
            $this->assertFalse(ClientRegistry::fromFile($file)->enabled(), 'over 64 KiB');

            $this->assertFalse(ClientRegistry::fromFile($dir . '/missing.json')->enabled());
            $this->assertSame('the file is missing or unreadable', ClientRegistry::fromFile($dir . '/missing.json')->problem());
            $this->assertFalse(ClientRegistry::fromFile($dir)->enabled(), 'a directory');
            $this->assertFalse(ClientRegistry::fromFile(null)->enabled());
            $this->assertFalse(ClientRegistry::fromFile('')->enabled());
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}

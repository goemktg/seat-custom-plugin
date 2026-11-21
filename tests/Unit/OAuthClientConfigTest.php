<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\OAuth\ClientConfig;
use PHPUnit\Framework\TestCase;

final class OAuthClientConfigTest extends TestCase
{
    private const VALID = [
        'client_id' => 'intel-archive',
        'client_secret' => 'a-long-random-secret-value-0123456789',
        'redirect_uri' => 'https://intel.nisuwaz.com/auth/callback',
    ];

    public function testAllThreeValuesTurnTheClientOn(): void
    {
        $this->assertTrue(ClientConfig::fromArray(self::VALID)->enabled());
    }

    public function testAnyEmptyOrMissingValueTurnsItOff(): void
    {
        foreach (array_keys(self::VALID) as $key) {
            foreach ([null, '', '   '] as $value) {
                $config = self::VALID;
                $config[$key] = $value;
                $this->assertFalse(ClientConfig::fromArray($config)->enabled(), $key . '=' . var_export($value, true));
            }
            $config = self::VALID;
            unset($config[$key]);
            $this->assertFalse(ClientConfig::fromArray($config)->enabled(), $key . ' missing');
        }
        $this->assertFalse(ClientConfig::fromArray([])->enabled());
    }

    public function testShortSecretTurnsItOff(): void
    {
        $this->assertFalse(ClientConfig::fromArray(['client_secret' => str_repeat('x', 31)] + self::VALID)->enabled());
        $this->assertTrue(ClientConfig::fromArray(['client_secret' => str_repeat('x', 32)] + self::VALID)->enabled());
    }

    public function testRedirectAddressMustBeHttpsOrLocalHttpWithoutFragment(): void
    {
        foreach (['http://intel.nisuwaz.com/cb', 'intel.nisuwaz.com/cb', 'javascript:alert(1)', 'https://intel.nisuwaz.com/cb#x', 'https:///cb', 'ftp://intel.nisuwaz.com/cb', 'https://user:pw@intel.nisuwaz.com/cb'] as $uri) {
            $this->assertFalse(ClientConfig::fromArray(['redirect_uri' => $uri] + self::VALID)->enabled(), $uri);
        }
        foreach (['http://localhost:8080/cb', 'http://127.0.0.1/cb', 'https://intel.nisuwaz.com/cb?from=seat'] as $uri) {
            $this->assertTrue(ClientConfig::fromArray(['redirect_uri' => $uri] + self::VALID)->enabled(), $uri);
        }
    }

    public function testClientAndRedirectMustMatchExactly(): void
    {
        $config = ClientConfig::fromArray(self::VALID);
        $this->assertTrue($config->matches('intel-archive', 'https://intel.nisuwaz.com/auth/callback'));
        $this->assertFalse($config->matches('intel-archive ', 'https://intel.nisuwaz.com/auth/callback'));
        $this->assertFalse($config->matches('Intel-Archive', 'https://intel.nisuwaz.com/auth/callback'));
        $this->assertFalse($config->matches('intel-archive', 'https://intel.nisuwaz.com/auth/callback/'));
        $this->assertFalse($config->matches('intel-archive', 'https://intel.nisuwaz.com/auth/callback?x=1'));
        $this->assertFalse($config->matches('intel-archive', 'https://intel.nisuwaz.com.evil.example/auth/callback'));
        $this->assertFalse($config->matches(null, 'https://intel.nisuwaz.com/auth/callback'));
        $this->assertFalse($config->matches(['intel-archive'], 'https://intel.nisuwaz.com/auth/callback'));
        $this->assertFalse($config->matches('intel-archive', null));
    }

    public function testSecretCheck(): void
    {
        $config = ClientConfig::fromArray(self::VALID);
        $this->assertTrue($config->authenticates('intel-archive', self::VALID['client_secret']));
        $this->assertFalse($config->authenticates('intel-archive', 'wrong'));
        $this->assertFalse($config->authenticates('other', self::VALID['client_secret']));
        $this->assertFalse($config->authenticates('intel-archive', null));
        $this->assertFalse($config->authenticates('intel-archive', [self::VALID['client_secret']]));
        $this->assertFalse($config->authenticates('intel-archive', self::VALID['client_secret'] . "\n"));
        $this->assertFalse($config->authenticates('intel-archive', substr(self::VALID['client_secret'], 0, -1)));
    }

    public function testAnOffClientNeverMatches(): void
    {
        $config = ClientConfig::fromArray(['client_secret' => ''] + self::VALID);
        $this->assertFalse($config->matches('intel-archive', 'https://intel.nisuwaz.com/auth/callback'));
        $this->assertFalse($config->authenticates('intel-archive', ''));
    }
}

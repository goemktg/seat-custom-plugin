<?php
// Boots the real SeAT 5 app with this plugin and drives the three OAuth addresses
// (authorize, token, userinfo) through the HTTP kernel. No network, no Redis, no MySQL:
// array cache and session, an in-memory sqlite database with the few tables used.
// Not part of the PHPUnit suite; run it inside the SeAT image (see README).
putenv('CACHE_DRIVER=array'); putenv('SESSION_DRIVER=array'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('APP_KEY=base64:' . base64_encode(random_bytes(32))); putenv('APP_ENV=testing'); putenv('APP_DEBUG=true');
foreach (['CACHE_DRIVER','SESSION_DRIVER','DB_CONNECTION','DB_DATABASE','APP_KEY','APP_ENV','APP_DEBUG'] as $k) { $_ENV[$k] = $_SERVER[$k] = getenv($k); }
require '/var/www/seat/vendor/autoload.php';
spl_autoload_register(function ($c) { $p = 'Goemktg\\Seat\\SeatCustomPlugin\\'; if (str_starts_with($c, $p)) { $f = '/plugin/src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) require $f; } });
$app = require '/var/www/seat/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
// as in production: the plugin registers before SeAT boots and turns permissions into gates
$app->booting(fn () => $app->register(Goemktg\Seat\SeatCustomPlugin\SeatCustomPluginServiceProvider::class));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// stand-in for the SeAT layout, whose sidebar needs the full SeAT schema
$views = sys_get_temp_dir() . '/oauth-smoke-views';
@mkdir($views . '/layouts/grids', 0700, true);
file_put_contents($views . '/layouts/grids/12.blade.php', "<html><title>@yield('title')</title><body>@yield('full')</body></html>");
Illuminate\Support\Facades\View::prependNamespace('web', $views);

use Illuminate\Support\Facades\Schema; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Gate; use Illuminate\Http\Request;
Schema::create('users', function ($t) { $t->increments('id'); $t->string('name'); $t->bigInteger('main_character_id')->nullable(); $t->boolean('active')->default(true); $t->boolean('admin')->default(false); $t->string('remember_token')->nullable(); $t->dateTime('last_login')->nullable(); $t->string('last_login_source')->nullable(); $t->timestamps(); });
Schema::create('character_infos', function ($t) { $t->bigInteger('character_id')->primary(); $t->string('name'); $t->timestamps(); });
Schema::create('user_settings', function ($t) { $t->increments('id'); $t->integer('user_id'); $t->string('name'); $t->text('value'); $t->timestamps(); });
Schema::create('global_settings', function ($t) { $t->increments('id'); $t->string('name'); $t->text('value'); $t->timestamps(); });
Schema::create('refresh_tokens', function ($t) { $t->bigInteger('character_id')->primary(); $t->integer('user_id'); $t->text('refresh_token')->default(''); $t->text('scopes')->default('[]'); $t->dateTime('expires_on')->nullable(); $t->string('token')->default(''); $t->unsignedInteger('scopes_profile')->default(0); $t->timestamps(); $t->softDeletes(); });
DB::table('character_infos')->insert(['character_id' => 90000001, 'name' => 'Test Pilot']);
$uid = DB::table('users')->insertGetId(['name' => 'Test Pilot', 'main_character_id' => 90000001, 'admin' => true]);
$noChar = DB::table('users')->insertGetId(['name' => 'Ghost', 'main_character_id' => 90000099, 'admin' => true]);
DB::table('refresh_tokens')->insert(['character_id' => 90000001, 'user_id' => $uid]);

$fail = 0;
function check($label, $ok, $extra = '') { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok ? '' : '  ' . $extra) . "\n"; if (!$ok) $fail++; }
function send($method, $uri, $params = [], $headers = []) {
    global $kernel; $server = [];
    foreach ($headers as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    $r = Request::create($uri, $method, $params, [], [], $server + ['REMOTE_ADDR' => '10.0.0.' . random_int(1, 250)]);
    $res = $kernel->handle($r); $kernel->terminate($r, $res); return $res;
}
$client = ['client_id' => 'intel-archive', 'client_secret' => str_repeat('s3cr3t', 8) /* 48 characters */, 'redirect_uri' => 'https://intel.nisuwaz.com/auth/callback'];
$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'; $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
$q = ['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => $client['redirect_uri'], 'state' => 'st1', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
$authUrl = '/custom-plugin/oauth/authorize?' . http_build_query($q);
$form = fn ($code, $o = []) => $o + ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $client['redirect_uri'], 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret'], 'code_verifier' => $verifier];

// signed out
$r = send('GET', $authUrl);
check('signed out authorize goes to SeAT login', $r->getStatusCode() === 302 && str_contains($r->headers->get('Location'), 'auth/login'), $r->getStatusCode() . ' ' . $r->headers->get('Location'));

$user = Seat\Web\Models\User::find($uid);
$app['auth']->guard('web')->setUser($user);

// feature off
check('off: authorize 404', send('GET', $authUrl)->getStatusCode() === 404);
check('off: token 404', send('POST', '/custom-plugin/oauth/token', $form('x'))->getStatusCode() === 404);
check('off: userinfo 404', send('GET', '/custom-plugin/oauth/userinfo', [], ['Authorization' => 'Bearer ' . str_repeat('a', 43)])->getStatusCode() === 404);

config(['custom-plugin.oauth.intel' => $client]);

check('permission registered as global', isset(config('seat.permissions.global')['intel_archive_view']), json_encode(array_keys(config('seat.permissions.global') ?? [])));

$r = send('GET', '/custom-plugin/oauth/authorize?' . http_build_query(['redirect_uri' => 'https://evil.example/cb'] + $q));
check('bad redirect: 400 page, no redirect', $r->getStatusCode() === 400 && !$r->headers->has('Location'), $r->getStatusCode() . ' ' . substr((string) $r->getContent(), 0, 300));
check('bad redirect page shows reason', str_contains((string) $r->getContent(), 'not registered with SeAT'));

$r = send('GET', '/custom-plugin/oauth/authorize?' . http_build_query(['code_challenge_method' => 'plain'] + $q));
check('plain PKCE: back with invalid_request', $r->getStatusCode() === 302 && $r->headers->get('Location') === $client['redirect_uri'] . '?error=invalid_request&state=st1', $r->headers->get('Location'));

$r = send('GET', $authUrl);
$loc = (string) $r->headers->get('Location'); parse_str((string) parse_url($loc, PHP_URL_QUERY), $back);
check('authorize 302 with code+state', $r->getStatusCode() === 302 && str_starts_with($loc, $client['redirect_uri'] . '?code=') && ($back['state'] ?? '') === 'st1', $r->getStatusCode() . ' ' . $loc);
check('authorize no-store', str_contains((string) $r->headers->get('Cache-Control'), 'no-store'), (string) $r->headers->get('Cache-Control'));
$code = $back['code'] ?? '';

$r = send('POST', '/custom-plugin/oauth/token?client_secret=' . $client['client_secret'], array_diff_key($form($code), ['client_secret' => 1]));
check('secret only in query string is refused (401)', $r->getStatusCode() === 401 && json_decode($r->getContent(), true) === ['error' => 'invalid_client'], $r->getStatusCode() . ' ' . $r->getContent());

$req = Request::create('/custom-plugin/oauth/token', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.1.1'], json_encode($form($code)));
$r = $kernel->handle($req);
check('JSON body refused (400 invalid_request) without burning the code', $r->getStatusCode() === 400 && json_decode($r->getContent(), true) === ['error' => 'invalid_request'], $r->getStatusCode() . ' ' . $r->getContent());

$r = send('POST', '/custom-plugin/oauth/token', $form($code));
$body = json_decode((string) $r->getContent(), true);
check('token 200 without CSRF token', $r->getStatusCode() === 200 && ($body['token_type'] ?? '') === 'Bearer' && ($body['expires_in'] ?? 0) === 300, $r->getStatusCode() . ' ' . $r->getContent());
check('token no-store', str_contains((string) $r->headers->get('Cache-Control'), 'no-store'));
check('token response sets no cookie', count($r->headers->getCookies()) === 0, json_encode(array_map('strval', $r->headers->getCookies())));
$token = $body['access_token'] ?? '';

$r = send('GET', '/custom-plugin/oauth/userinfo', [], ['Authorization' => 'Bearer ' . $token]);
check('userinfo 200', $r->getStatusCode() === 200 && json_decode($r->getContent(), true) === ['sub' => (string) $uid, 'character_id' => 90000001, 'name' => 'Test Pilot'], $r->getStatusCode() . ' ' . $r->getContent());

$r = send('POST', '/custom-plugin/oauth/token', $form($code));
check('code reuse 400 invalid_grant', $r->getStatusCode() === 400 && json_decode($r->getContent(), true) === ['error' => 'invalid_grant'], $r->getContent());
$r = send('GET', '/custom-plugin/oauth/userinfo', [], ['Authorization' => 'Bearer ' . $token]);
check('token revoked after reuse: 401 + WWW-Authenticate', $r->getStatusCode() === 401 && str_contains((string) $r->headers->get('WWW-Authenticate'), 'invalid_token'), $r->getStatusCode());

// a fresh token, then the permission goes away
parse_str((string) parse_url(send('GET', $authUrl)->headers->get('Location'), PHP_URL_QUERY), $back);
$token = json_decode(send('POST', '/custom-plugin/oauth/token', $form($back['code']))->getContent(), true)['access_token'];
Gate::before(fn () => false);
$r = send('GET', '/custom-plugin/oauth/userinfo', [], ['Authorization' => 'Bearer ' . $token]);
check('permission removed: userinfo 403', $r->getStatusCode() === 403 && json_decode($r->getContent(), true) === ['error' => 'access_denied'], $r->getStatusCode() . ' ' . $r->getContent());
$r = send('GET', $authUrl);
check('no permission: back with access_denied', $r->headers->get('Location') === $client['redirect_uri'] . '?error=access_denied&state=st1', (string) $r->headers->get('Location'));

// permission back, but a user whose main character row is missing
$app['auth']->guard('web')->setUser(Seat\Web\Models\User::find($noChar));
$gate = $app->make(Illuminate\Contracts\Auth\Access\Gate::class);
$rp = new ReflectionProperty($gate, 'beforeCallbacks'); $rp->setValue($gate, []);
$r = send('GET', $authUrl);
check('no main character: access_denied', $r->headers->get('Location') === $client['redirect_uri'] . '?error=access_denied&state=st1', (string) $r->headers->get('Location'));


// inactive account with a live token
$app['auth']->guard('web')->setUser(Seat\Web\Models\User::find($uid));
parse_str((string) parse_url(send('GET', $authUrl)->headers->get('Location'), PHP_URL_QUERY), $back);
$token = json_decode(send('POST', '/custom-plugin/oauth/token', $form($back['code']))->getContent(), true)['access_token'];
DB::table('users')->where('id', $uid)->update(['active' => false]);
$r = send('GET', '/custom-plugin/oauth/userinfo', [], ['Authorization' => 'Bearer ' . $token]);
check('inactive account: userinfo 403', $r->getStatusCode() === 403, $r->getStatusCode());

DB::table('users')->where('id', $uid)->update(['active' => true]);

// the main character's ESI token lapsed (SeAT soft-deletes it) but the user still owns it: still allowed
$app['auth']->guard('web')->setUser(Seat\Web\Models\User::find($uid));
DB::table('refresh_tokens')->where('character_id', 90000001)->update(['deleted_at' => now()]);
parse_str((string) parse_url(send('GET', $authUrl)->headers->get('Location'), PHP_URL_QUERY), $back);
check('lapsed ESI token of own main: still gets a code', isset($back['code']), json_encode($back));
DB::table('refresh_tokens')->where('character_id', 90000001)->update(['deleted_at' => null]);

// the main character is linked into another SeAT account: the old account no longer owns it
$token = json_decode(send('POST', '/custom-plugin/oauth/token', $form($back['code']))->getContent(), true)['access_token'] ?? '';
$other = DB::table('users')->insertGetId(['name' => 'New Owner', 'main_character_id' => 90000002, 'admin' => true]);
DB::table('refresh_tokens')->where('character_id', 90000001)->update(['user_id' => $other]);
$r = send('GET', '/custom-plugin/oauth/userinfo', [], ['Authorization' => 'Bearer ' . $token]);
check('main moved to another account: userinfo 403', $r->getStatusCode() === 403 && json_decode($r->getContent(), true) === ['error' => 'access_denied'], $r->getStatusCode() . ' ' . $r->getContent());
$r = send('GET', $authUrl);
check('main moved to another account: back with access_denied', $r->headers->get('Location') === $client['redirect_uri'] . '?error=access_denied&state=st1', (string) $r->headers->get('Location'));
DB::table('refresh_tokens')->where('character_id', 90000001)->update(['user_id' => $uid]);

// the main character has no token row at all
$row = (array) DB::table('refresh_tokens')->where('character_id', 90000001)->first();
DB::table('refresh_tokens')->where('character_id', 90000001)->delete();
$r = send('GET', $authUrl);
check('main without a token row: back with access_denied', $r->headers->get('Location') === $client['redirect_uri'] . '?error=access_denied&state=st1', (string) $r->headers->get('Location'));
DB::table('refresh_tokens')->insert($row);

// a fresh token for the checks below
parse_str((string) parse_url(send('GET', $authUrl)->headers->get('Location'), PHP_URL_QUERY), $back);
$token = json_decode(send('POST', '/custom-plugin/oauth/token', $form($back['code']))->getContent(), true)['access_token'] ?? '';
$app['auth']->forgetGuards();
$at = fn ($method, $uri, $params = [], $server = []) => $kernel->handle(Request::create($uri, $method, $params, [], [], $server + ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '10.9.9.9']));

// limits: requests without the client secret are counted per address, and the archive is not locked out by them
$codes = [];
for ($i = 0; $i < 31; $i++) {
    $codes[] = $at('POST', '/custom-plugin/oauth/token', array_diff_key($form(str_repeat('a', 43)), ['client_secret' => 1]))->getStatusCode();
}
check('token without secret: 30 answered 401, 31st is 429', count(array_filter($codes, fn ($c) => $c === 401)) === 30 && end($codes) === 429, json_encode(array_count_values($codes)));
$r = $at('POST', '/custom-plugin/oauth/token', $form(str_repeat('a', 43)));
check('token with the secret from the same address is still answered', $r->getStatusCode() === 400 && json_decode($r->getContent(), true) === ['error' => 'invalid_grant'], $r->getStatusCode() . ' ' . $r->getContent());
$rr = $at('POST', '/custom-plugin/oauth/token', $form(str_repeat('a', 43), ['client_secret' => str_repeat('x', 48)]));
check('wrong secret from that address: 429, no-store, Retry-After', $rr->getStatusCode() === 429 && (int) $rr->headers->get('Retry-After') > 0 && str_contains((string) $rr->headers->get('Cache-Control'), 'no-store'), $rr->getStatusCode() . ' ' . json_encode($rr->headers->all()));
$req = Request::create('/custom-plugin/oauth/token', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '10.9.9.9'], '{}');
check('JSON body counts against the address too', $kernel->handle($req)->getStatusCode() === 429);
$r = $at('POST', '/custom-plugin/oauth/token', array_diff_key($form('x'), ['client_secret' => 1]), ['HTTP_X_FORWARDED_FOR' => '10.9.9.8']);
check('the address is the one the proxy appended (another address is answered)', $r->getStatusCode() === 401, (string) $r->getStatusCode());

$codes = [];
for ($i = 0; $i < 61; $i++) {
    $codes[] = $at('GET', '/custom-plugin/oauth/userinfo', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('b', 43)])->getStatusCode();
}
check('userinfo with a bad token: 60 answered 401, 61st is 429', count(array_filter($codes, fn ($c) => $c === 401)) === 60 && end($codes) === 429, json_encode(array_count_values($codes)));
$r = $at('GET', '/custom-plugin/oauth/userinfo', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
check('userinfo with a live token from the same address is still answered', $r->getStatusCode() === 200, $r->getStatusCode() . ' ' . $r->getContent());

// requests with a live token have their own budget per user
Illuminate\Support\Facades\RateLimiter::clear('custom-plugin-oauth-userinfo-user:' . $uid);
$codes = [];
for ($i = 0; $i < 61; $i++) {
    $codes[] = send('GET', '/custom-plugin/oauth/userinfo', [], ['Authorization' => 'Bearer ' . $token])->getStatusCode();
}
check('userinfo with a live token: 60 answered, 61st is 429', count(array_filter($codes, fn ($c) => $c === 200)) === 60 && end($codes) === 429, json_encode(array_count_values($codes)));

// requests with the client secret have their own budget per client, whatever the address
Illuminate\Support\Facades\RateLimiter::clear('custom-plugin-oauth-token-client');
$codes = [];
for ($i = 0; $i < 121; $i++) {
    $codes[] = send('POST', '/custom-plugin/oauth/token', $form(str_repeat('a', 43)))->getStatusCode();
}
check('token with the secret: 120 answered, 121st is 429', count(array_filter($codes, fn ($c) => $c === 400)) === 120 && end($codes) === 429, json_encode(array_count_values($codes)));

echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);

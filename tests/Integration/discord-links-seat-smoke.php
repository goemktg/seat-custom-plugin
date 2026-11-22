<?php
// Boots the real SeAT 5 app with this plugin and drives GET /api/v2/custom-plugin/discord-links
// through the HTTP kernel, with SeAT's own api.request and api.auth middleware in front.
// No network, no Redis, no MySQL: array cache and session, an in-memory sqlite database.
// Not part of the PHPUnit suite; run it inside the SeAT image (see README).
putenv('CACHE_DRIVER=array'); putenv('SESSION_DRIVER=array'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('APP_KEY=base64:' . base64_encode(random_bytes(32))); putenv('APP_ENV=testing'); putenv('APP_DEBUG=true');
foreach (['CACHE_DRIVER','SESSION_DRIVER','DB_CONNECTION','DB_DATABASE','APP_KEY','APP_ENV','APP_DEBUG'] as $k) { $_ENV[$k] = $_SERVER[$k] = getenv($k); }
require '/var/www/seat/vendor/autoload.php';
spl_autoload_register(function ($c) { $p = 'Goemktg\\Seat\\SeatCustomPlugin\\'; if (str_starts_with($c, $p)) { $f = '/plugin/src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) require $f; } });
$app = require '/var/www/seat/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->booting(fn () => $app->register(Goemktg\Seat\SeatCustomPlugin\SeatCustomPluginServiceProvider::class));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema; use Illuminate\Support\Facades\DB; use Illuminate\Http\Request;
Schema::create('api_tokens', function ($t) { $t->increments('id'); $t->string('token'); $t->string('allowed_src'); $t->string('comment')->nullable(); $t->timestamps(); });
Schema::create('api_token_logs', function ($t) { $t->increments('id'); $t->integer('api_token_id')->nullable(); $t->string('action'); $t->string('method', 10)->nullable(); $t->string('request_path'); $t->string('src_ip'); $t->timestamps(); });
DB::table('api_tokens')->insert([['token' => 'open-token', 'allowed_src' => '0.0.0.0'], ['token' => 'pinned-token', 'allowed_src' => '10.9.9.9']]);

$fail = 0;
function check($label, $ok, $extra = '') { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok ? '' : '  ' . $extra) . "\n"; if (!$ok) $fail++; }
function send($uri, $headers = ['X-Token' => 'open-token'], $ip = '10.0.0.7') {
    global $kernel; $server = [];
    foreach ($headers as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    $r = Request::create($uri, 'GET', [], [], [], $server + ['REMOTE_ADDR' => $ip]);
    // Request::create always sets an Accept header; the bot sends none, so drop it unless the test sets one
    if (! array_key_exists('Accept', $headers)) { $r->headers->remove('Accept'); $r->server->remove('HTTP_ACCEPT'); }
    $res = $kernel->handle($r); $kernel->terminate($r, $res); return $res;
}
$url = fn ($id) => '/api/v2/custom-plugin/discord-links?' . (is_array($id) ? http_build_query(['discord_id' => $id]) : 'discord_id=' . rawurlencode($id));
$show = fn ($r) => $r->getStatusCode() . ' ' . $r->getContent();

// seat-connector not installed yet
$r = send($url('123'));
check('no link table: 503', $r->getStatusCode() === 503 && json_decode($r->getContent(), true) === ['message' => 'seat-connector is not installed'], $show($r));

Schema::create('seat_connector_users', function ($t) { $t->increments('id'); $t->string('connector_type'); $t->string('connector_id'); $t->string('connector_name'); $t->string('name_override')->nullable(); $t->string('unique_id'); $t->unsignedInteger('user_id'); $t->timestamps(); $t->unique(['connector_type', 'connector_id']); });
DB::table('seat_connector_users')->insert([
    ['connector_type' => 'discord', 'connector_id' => '123456789012345678', 'connector_name' => 'pilot', 'unique_id' => 'pilot@example.invalid', 'user_id' => 42],
    ['connector_type' => 'teamspeak', 'connector_id' => '555', 'connector_name' => 'ts', 'unique_id' => 'ts-uid', 'user_id' => 43],
    ['connector_type' => 'discord', 'connector_id' => '0555', 'connector_name' => 'zero', 'unique_id' => 'zero', 'user_id' => 44],
]);

// authentication comes from SeAT's middleware
check('no token: 401', send($url('123456789012345678'), [])->getStatusCode() === 401);
check('wrong token: 401', send($url('123456789012345678'), ['X-Token' => 'nope'])->getStatusCode() === 401);
check('token pinned to another address: 401', send($url('123456789012345678'), ['X-Token' => 'pinned-token'])->getStatusCode() === 401);
check('token pinned to this address: 200', send($url('123456789012345678'), ['X-Token' => 'pinned-token'], '10.9.9.9')->getStatusCode() === 200);
check('Accept text/html: 406', send($url('123456789012345678'), ['X-Token' => 'open-token', 'Accept' => 'text/html'])->getStatusCode() === 406);
check('denied calls are logged', DB::table('api_token_logs')->where('action', 'deny')->count() === 3);

// lookups (the bot sends no Accept header; also check application/json)
$r = send($url('123456789012345678'));
check('linked user, no Accept header: only the account id', $r->getStatusCode() === 200 && $r->getContent() === '{"data":[{"user_id":42}]}', $show($r));
$r = send($url('123456789012345678'), ['X-Token' => 'open-token', 'Accept' => 'application/json']);
check('linked user, Accept application/json', $r->getStatusCode() === 200 && $r->getContent() === '{"data":[{"user_id":42}]}', $show($r));
$r = send($url('999'));
check('unknown id: empty list', $r->getStatusCode() === 200 && $r->getContent() === '{"data":[]}', $show($r));
$r = send($url('555'));
check('another connector with the same id is not a Discord link', $r->getContent() === '{"data":[]}', $show($r));
check('leading zeros are not trimmed', send($url('0555'))->getContent() === '{"data":[{"user_id":44}]}' && send($url('555'))->getContent() === '{"data":[]}');

// input check
foreach (['missing' => null, 'empty' => '', 'letters' => 'abc', 'negative' => '-1', '21 digits' => str_repeat('1', 21), 'inner space' => '12 3', 'sql' => "1' OR '1'='1", 'array' => ['1']] as $label => $value) {
    $r = $value === null ? send('/api/v2/custom-plugin/discord-links') : send($url($value));
    check("422 for $label", $r->getStatusCode() === 422 && json_decode($r->getContent(), true) === ['message' => 'discord_id must be 1 to 20 digits'], $show($r));
}

// SeAT's global TrimStrings middleware trims query values first, so surrounding whitespace is dropped
check('surrounding whitespace is trimmed by SeAT, then looked up', send($url(" 123456789012345678\n"))->getContent() === '{"data":[{"user_id":42}]}');

// read only
check('the link table is unchanged', DB::table('seat_connector_users')->count() === 3);

echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);

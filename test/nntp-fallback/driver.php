<?php
/*
 * Exercises Services_Nntp_FailoverTransport inside the built image against
 * fake-nntp-server.php instances. Run by test/nntp-fallback.sh.
 *
 * Ports: 11901 has only <b>, 11902 has <a>,<b>,<c>, 11903 is closed.
 */

$home = getenv('SPOTWEB_HOME') ?: '/var/www/spotweb';
require $home.'/vendor/autoload.php';
require_once $home.'/lib/services/Nntp/Services_Nntp_ClientPool.php';

putenv('SPOTWEB_NNTP_FAILOVER_QUIET=1');

$failures = 0;
function check($name, $ok, $detail = '')
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ').$name.($ok || $detail === '' ? '' : ' -- '.$detail).PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function server($port, array $extra = [])
{
    return $extra + ['host' => '127.0.0.1', 'port' => $port, 'enc' => false, 'user' => '', 'pass' => '', 'verifyname' => false];
}

/* Fresh penalty state and config per scenario. */
function transport($primaryPort, array $fallbacks, $role = 'bin', $primaryPriority = 0)
{
    static $n = 0;
    $n++;
    putenv('SPOTWEB_NNTP_PENALTY_FILE=/tmp/penalty-'.$n.'.json');
    $cfg = '/tmp/fallback-'.$n.'.json';
    file_put_contents($cfg, json_encode(['primary_priority' => $primaryPriority, 'servers' => $fallbacks]));
    $primary = server($primaryPort);

    return Services_Nntp_FailoverTransport::wrap(
        new Services_Nntp_PipelinedTransport($primary, 2, $role),
        $primary,
        $role,
        $cfg
    );
}

function servedBy(array $lines)
{
    foreach ($lines as $line) {
        if (preg_match('/^X-Served-By: (\d+)/', $line, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/from (\d+)$/', $line, $m)) {
            return (int) $m[1];
        }
    }

    return null;
}

$fallbackB = server(11902, ['priority' => 1]);

// 1. Missing article on the primary is fetched from the fallback.
$t = transport(11901, [$fallbackB]);
check('wrap returns the failover transport', $t instanceof Services_Nntp_FailoverTransport);
check('failover transport is still a PipelinedTransport', $t instanceof Services_Nntp_PipelinedTransport);
$body = $t->getBody('<a>');
check('430 on primary falls back for BODY', servedBy($body) === 11902, json_encode($body));
$body = $t->getBody('b');
check('article present on primary comes from primary', servedBy($body) === 11901, json_encode($body));
$t->quit();

// 2. Primary down: connect goes to the fallback and the primary is penalised.
$t = transport(11903, [$fallbackB]);
$body = $t->getBody('b');
check('unreachable primary falls back', servedBy($body) === 11902, json_encode($body));
$penalty = json_decode((string) @file_get_contents(getenv('SPOTWEB_NNTP_PENALTY_FILE')), true) ?: [];
check('unreachable primary is in the penalty box', isset($penalty['127.0.0.1:11903:']), json_encode($penalty));
$t->quit();

// 3. Pipelined fetch fills the gaps from the fallback and keeps the order.
$t = transport(11901, [$fallbackB]);
$results = $t->fetchArticlesPipelined(['<a>', '<b>', '<c>'], 4);
$ids = array_map(function ($r) { return $r->messageId; }, $results);
check('pipelined fetch returns all ids in order', $ids === ['a', 'b', 'c'], json_encode($ids));
$found = array_map(function ($r) { return $r->found(); }, $results);
check('pipelined fetch found all three', $found === [true, true, true], json_encode($found));
$from = array_map(function ($r) { return servedBy($r->header); }, $results);
check('pipelined fetch uses primary first', $from === [11902, 11901, 11902], json_encode($from));
$t->quit();

// 4. Missing everywhere: the 430 reaches the caller.
$t = transport(11901, [$fallbackB]);
try {
    $t->getBody('<zzz>');
    check('missing article everywhere raises 430', false, 'no exception');
} catch (NntpException $x) {
    check('missing article everywhere raises 430', $x->getCode() === 430, $x->getMessage());
}
$results = $t->fetchArticlesPipelined(['zzz', 'a'], 4);
check('pipelined fetch reports 430 for an id nobody has', count($results) === 2 && $results[0]->code === 430 && $results[1]->found());
$t->quit();

// 5. Group commands stick to the first server that accepts GROUP.
$t = transport(11903, [$fallbackB], 'hdr');
$group = $t->selectGroup('test');
check('GROUP fails over when the primary is down', $t->getActiveServer() === '127.0.0.1:11902', (string) $t->getActiveServer());
check('XHDR runs on the active server', $t->getMessageIdByArticleNumber($group['first']) === 'a');
$t->quit();

// 6. Priority order: a fallback with a better priority than the primary goes first.
$t = transport(11901, [server(11902, ['priority' => 0])], 'bin', 5);
$body = $t->getBody('b');
check('primary_priority lets a fallback go first', servedBy($body) === 11902, json_encode($body));
$t->quit();

// 7. Bad credentials on a fallback: skipped and penalised, the next one serves.
$t = transport(11903, [server(11902, ['priority' => 1, 'user' => 'bad', 'pass' => 'x']), server(11902, ['priority' => 2, 'name' => 'good'])]);
$body = $t->getBody('c');
$penalty = json_decode((string) @file_get_contents(getenv('SPOTWEB_NNTP_PENALTY_FILE')), true) ?: [];
check('auth failure moves on to the next server', servedBy($body) === 11902);
check('auth failure is penalised for 10 minutes', isset($penalty['127.0.0.1:11902:bad']) && $penalty['127.0.0.1:11902:bad'] - time() > 500, json_encode($penalty));
$t->quit();

// 8. Roles and the no-config path leave upstream behaviour untouched.
$plain = new Services_Nntp_PipelinedTransport(server(11901), 2, 'bin');
check('no config file returns the plain transport', Services_Nntp_FailoverTransport::wrap($plain, server(11901), 'bin', '/nonexistent.json') === $plain);
file_put_contents('/tmp/post-only.json', json_encode(['servers' => [server(11902, ['roles' => 'post'])]]));
check('fallback for another role is ignored', Services_Nntp_FailoverTransport::wrap($plain, server(11901), 'bin', '/tmp/post-only.json') === $plain);
file_put_contents('/tmp/disabled.json', json_encode(['servers' => [server(11902, ['enable' => 'false'])]]));
check('disabled fallback is ignored', Services_Nntp_FailoverTransport::wrap($plain, server(11901), 'bin', '/tmp/disabled.json') === $plain);

// 9. Posting never retries once connected.
$t = transport(11901, [$fallbackB]);
try {
    $t->post([['Subject: x'], ['body']]);
    check('post failure is not retried elsewhere', false, 'no exception');
} catch (Exception $x) {
    $log = (string) @file_get_contents('/tmp/fake-nntp-11902.log');
    check('post failure is not retried elsewhere', strpos($log, 'POST') === false);
}
$t->quit();

// 10. ClientPool hands out the failover transport per role, through Spotweb's own settings.
class FallbackTestSettingsSource implements Services_Settings_IContainer
{
    private $_settings;

    public function __construct(array $settings)
    {
        $this->_settings = $settings;
    }

    public function initialize(array $cfg)
    {
    }

    public function getAllSettings()
    {
        return $this->_settings;
    }

    public function remove($name)
    {
        unset($this->_settings[$name]);
    }

    public function set($name, $value)
    {
        $this->_settings[$name] = $value;
    }
}

$pool = file_get_contents($home.'/lib/services/Nntp/Services_Nntp_ClientPool.php');
check('ClientPool is patched', substr_count($pool, 'Services_Nntp_FailoverTransport::wrap(') === 3);

file_put_contents('/tmp/pool.json', json_encode(['servers' => [server(11902, ['roles' => 'bin'])]]));
putenv('SPOTWEB_NNTP_FALLBACK_FILE=/tmp/pool.json');
$settings = new Services_Settings_Container();
$settings->addSource(new FallbackTestSettingsSource([
    'nntp_hdr'  => server(11901, ['buggy' => false]),
    'nntp_nzb'  => server(11901, ['buggy' => false]),
    'nntp_post' => server(11901, ['buggy' => false]),
]));
$bin = Services_Nntp_ClientPool::pool($settings, 'bin');
$hdr = Services_Nntp_ClientPool::pool($settings, 'hdr');
check('ClientPool wraps a role that has a fallback', $bin instanceof Services_Nntp_FailoverTransport);
check('ClientPool leaves a role without fallback alone', get_class($hdr) === 'Services_Nntp_PipelinedTransport');
check('ClientPool bin transport falls back', servedBy($bin->getBody('a')) === 11902);
$reader = new Services_Nntp_SpotReader($bin);
check('SpotReader accepts the failover transport', $reader instanceof Services_Nntp_SpotReader);
$bin->quit();

echo PHP_EOL.($failures === 0 ? 'All fallback tests passed' : $failures.' fallback test(s) failed').PHP_EOL;
exit($failures === 0 ? 0 : 1);

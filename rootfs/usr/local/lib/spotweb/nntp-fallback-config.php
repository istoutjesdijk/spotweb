<?php
/*
 * Builds the fallback newsserver list for Services_Nntp_FailoverTransport from
 * SPOTWEB_NNTP_FALLBACK_<n>_* environment variables and prints it as JSON.
 * Called by the entrypoint; prints nothing when no fallback is configured.
 *
 *   SPOTWEB_NNTP_FALLBACK_<n>_HOST        required
 *   SPOTWEB_NNTP_FALLBACK_<n>_PORT        default 563 with ssl/tls, else 119
 *   SPOTWEB_NNTP_FALLBACK_<n>_USER / _PASS
 *   SPOTWEB_NNTP_FALLBACK_<n>_ENC         ssl, tls, starttls or empty
 *   SPOTWEB_NNTP_FALLBACK_<n>_VERIFYNAME  default true
 *   SPOTWEB_NNTP_FALLBACK_<n>_PRIORITY    0-99, 0 is highest; default 1
 *   SPOTWEB_NNTP_FALLBACK_<n>_ROLES       hdr,bin,post; default hdr,bin
 *   SPOTWEB_NNTP_FALLBACK_<n>_ENABLE      default true
 *   SPOTWEB_NNTP_FALLBACK_<n>_NAME        label in the logs
 *   SPOTWEB_NNTP_PRIMARY_PRIORITY         priority of the server in Spotweb's settings, default 0
 */

$env = getenv();
$numbers = [];
foreach (array_keys($env) as $key) {
    if (preg_match('/^SPOTWEB_NNTP_FALLBACK_(\d+)_HOST$/', $key, $m) && trim($env[$key]) !== '') {
        $numbers[] = (int) $m[1];
    }
}
sort($numbers);

if (empty($numbers)) {
    exit(0);
}

$get = function ($n, $field, $default = null) use ($env) {
    $key = 'SPOTWEB_NNTP_FALLBACK_'.$n.'_'.$field;

    return (isset($env[$key]) && $env[$key] !== '') ? $env[$key] : $default;
};

$servers = [];
foreach ($numbers as $n) {
    $server = [
        'name'       => $get($n, 'NAME', 'fallback-'.$n),
        'host'       => trim($get($n, 'HOST')),
        'user'       => $get($n, 'USER', ''),
        'pass'       => $get($n, 'PASS', ''),
        'enc'        => $get($n, 'ENC', false),
        'verifyname' => $get($n, 'VERIFYNAME', 'true'),
        'priority'   => (int) $get($n, 'PRIORITY', 1),
        'roles'      => $get($n, 'ROLES', 'hdr,bin'),
        'enable'     => $get($n, 'ENABLE', 'true'),
    ];
    if ($get($n, 'PORT') !== null) {
        $server['port'] = (int) $get($n, 'PORT');
    }
    $servers[] = $server;
}

echo json_encode([
    'primary_priority' => (int) (getenv('SPOTWEB_NNTP_PRIMARY_PRIORITY') ?: 0),
    'servers'          => $servers,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

<?php
/*
 * Minimal NNTP server for the fallback tests. Serves several clients at once
 * (the failover transport keeps connections to more than one server open).
 *
 *   php fake-nntp-server.php <port> <comma-separated message-ids> [first-article-number]
 *
 * Every listed message-id is an article in group "test"; anything else gets 430.
 * AUTHINFO USER "bad" is refused with 481. Each command is appended to
 * /tmp/fake-nntp-<port>.log so the driver can see which server was asked.
 */

[$self, $port, $ids] = $argv + [null, null, ''];
$first = isset($argv[3]) ? (int) $argv[3] : 1;
$ids = array_values(array_filter(explode(',', $ids)));
$log = '/tmp/fake-nntp-'.$port.'.log';
@unlink($log);

$server = stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}

$clients = [];
$buffers = [];
$users = [];

$send = function ($c, $text) {
    fwrite($c, $text);
};

$article = function ($id, $part) use ($port) {
    $header = "Message-ID: <$id>\r\nSubject: $id\r\nX-Served-By: $port\r\n";
    $body = "body of $id from $port\r\n..dot-stuffed line\r\n";
    switch ($part) {
        case 'HEAD': return $header;
        case 'BODY': return $body;
        default: return $header."\r\n".$body;
    }
};

while (true) {
    $read = array_merge([$server], $clients);
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 60) === false) {
        break;
    }

    foreach ($read as $sock) {
        if ($sock === $server) {
            $c = @stream_socket_accept($server, 1);
            if ($c) {
                $clients[(int) $c] = $c;
                $buffers[(int) $c] = '';
                $send($c, "200 fake-nntp $port ready\r\n");
            }
            continue;
        }

        $chunk = @fread($sock, 8192);
        if ($chunk === '' || $chunk === false) {
            unset($clients[(int) $sock], $buffers[(int) $sock]);
            @fclose($sock);
            continue;
        }
        $buffers[(int) $sock] .= $chunk;

        while (($pos = strpos($buffers[(int) $sock], "\n")) !== false) {
            $line = rtrim(substr($buffers[(int) $sock], 0, $pos), "\r\n");
            $buffers[(int) $sock] = substr($buffers[(int) $sock], $pos + 1);
            file_put_contents($log, $line."\n", FILE_APPEND);

            $parts = preg_split('/\s+/', $line, 3);
            $cmd = strtoupper($parts[0]);
            $arg = $parts[1] ?? '';

            switch ($cmd) {
                case 'AUTHINFO':
                    if (strtoupper($arg) === 'USER') {
                        $users[(int) $sock] = $parts[2] ?? '';
                        $send($sock, ($users[(int) $sock] === 'bad') ? "481 bad credentials\r\n" : "381 password required\r\n");
                    } else {
                        $send($sock, "281 ok\r\n");
                    }
                    break;
                case 'GROUP':
                    $last = $first + count($ids) - 1;
                    $send($sock, '211 '.count($ids)." $first $last $arg\r\n");
                    break;
                case 'XHDR':
                    [$a, $b] = array_map('intval', explode('-', $parts[2] ?? '0-0'));
                    $out = "221 Message-ID follows\r\n";
                    foreach ($ids as $k => $id) {
                        $nr = $first + $k;
                        if ($nr >= $a && $nr <= $b) {
                            $out .= "$nr <$id>\r\n";
                        }
                    }
                    $send($sock, $out.".\r\n");
                    break;
                case 'ARTICLE':
                case 'HEAD':
                case 'BODY':
                    $id = trim($arg, '<>');
                    if (!in_array($id, $ids, true)) {
                        $send($sock, "430 no such article\r\n");
                        break;
                    }
                    $code = ['ARTICLE' => 220, 'HEAD' => 221, 'BODY' => 222][$cmd];
                    $send($sock, "$code 0 <$id>\r\n".$article($id, $cmd).".\r\n");
                    break;
                case 'POST':
                    $send($sock, "440 posting not allowed\r\n");
                    break;
                case 'NOOP':
                    $send($sock, "200 ok\r\n");
                    break;
                case 'QUIT':
                    $send($sock, "205 bye\r\n");
                    unset($clients[(int) $sock], $buffers[(int) $sock]);
                    @fclose($sock);
                    continue 3;
                default:
                    $send($sock, "500 unknown command\r\n");
            }
        }
    }
}

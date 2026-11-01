<?php

/*
 * Fallback newsservers with a priority, modelled on SABnzbd.
 *
 * Not part of upstream Spotweb: the istoutjesdijk/spotweb image adds this file
 * and hooks it into Services_Nntp_ClientPool with a small patch.
 *
 * The primary server is the one configured in Spotweb's own settings. Extra
 * servers come from a JSON file (default /etc/spotweb/nntp-fallback.json, which
 * the container entrypoint writes). Servers are tried in priority order, where
 * 0 is the highest priority:
 *
 * - Reads by message-id (HEAD, BODY, ARTICLE and the pipelined ARTICLE fetch)
 *   use a try-list per article. When a server answers 430 or fails, the next
 *   server is asked for the articles that are still missing.
 * - Reads by article number (GROUP, XOVER, XHDR) depend on per-server
 *   numbering, so they stick to the first server that accepts the GROUP. The
 *   server is never switched halfway through a session.
 * - POST only moves on when connecting or logging in fails, so an article is
 *   never posted twice.
 *
 * A server that cannot be connected to is skipped for a few minutes. That
 * state is shared between PHP processes through a small file.
 */

require_once __DIR__.'/Services_Nntp_PipelinedTransport.php';
require_once __DIR__.'/Services_Nntp_PipelinedFetchException.php';
require_once __DIR__.'/Services_Nntp_PipelineDepth.php';

class Services_Nntp_ServerPenaltyBox
{
    /* Minutes, as in SABnzbd's downloader. */
    const PENALTY_PERM = 10;
    const PENALTY_TIMEOUT = 10;
    const PENALTY_UNKNOWN = 3;

    private $_file;

    public function __construct($file = null)
    {
        if ($file === null) {
            $file = getenv('SPOTWEB_NNTP_PENALTY_FILE');
        }
        if (empty($file)) {
            $file = rtrim(sys_get_temp_dir(), '/').'/spotweb-nntp-penalty.json';
        }
        $this->_file = $file;
    }

    public static function minutesFor(Exception $x)
    {
        $code = (int) $x->getCode();
        if (in_array($code, [481, 482, 502], true)) {
            return self::PENALTY_PERM;
        }
        if (stripos($x->getMessage(), 'timed out') !== false) {
            return self::PENALTY_TIMEOUT;
        }

        return self::PENALTY_UNKNOWN;
    }

    public function isPenalized($key)
    {
        $state = $this->read();

        return isset($state[$key]) && ($state[$key] > time());
    }

    public function penalize($key, $minutes)
    {
        $this->update(function (array $state) use ($key, $minutes) {
            $state[$key] = time() + (int) round($minutes * 60);

            return $state;
        });
    }

    public function clear($key)
    {
        if (!$this->isPenalized($key)) {
            return;
        }
        $this->update(function (array $state) use ($key) {
            unset($state[$key]);

            return $state;
        });
    }

    private function read()
    {
        $raw = @file_get_contents($this->_file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $state = json_decode($raw, true);

        return is_array($state) ? $state : [];
    }

    private function update($mutator)
    {
        $fh = @fopen($this->_file, 'c+');
        if ($fh === false) {
            return;
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($fh);
            $state = json_decode((string) $raw, true);
            if (!is_array($state)) {
                $state = [];
            }
            $now = time();
            $state = array_filter($mutator($state), function ($until) use ($now) {
                return $until > $now;
            });
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state));
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
    }
}

class Services_Nntp_FailoverTransport extends Services_Nntp_PipelinedTransport
{
    const DEFAULT_CONFIG_FILE = '/etc/spotweb/nntp-fallback.json';
    const DEFAULT_ROLES = ['hdr', 'bin'];

    /* @var Services_Nntp_PipelinedTransport[] in priority order */
    private $_children = [];
    /* @var array[] server descriptions, same keys as $_children */
    private $_servers = [];
    private $_used = [];
    /* Index of the server that serves article-number commands, or null. */
    private $_active = null;
    private $_role;
    private $_penalties;

    /*
     * Returns $primary unchanged when no fallback applies to $role, so a
     * container without fallback configuration behaves exactly like upstream.
     */
    public static function wrap(Services_Nntp_PipelinedTransport $primary, array $primaryServer, $role, $configFile = null)
    {
        if ($primary instanceof self) {
            return $primary;
        }

        $config = self::loadConfig($configFile);
        $fallbacks = [];
        foreach ($config['servers'] as $server) {
            if (in_array($role, $server['roles'], true)) {
                $fallbacks[] = $server;
            }
        }
        if (empty($fallbacks)) {
            return $primary;
        }

        $primaryServer['priority'] = $config['primary_priority'];
        $primaryServer['name'] = 'primary';

        $entries = [['server' => $primaryServer, 'transport' => $primary, 'order' => 0]];
        foreach ($fallbacks as $i => $server) {
            $entries[] = [
                'server'    => $server,
                'transport' => new Services_Nntp_PipelinedTransport($server, self::DEFAULT_TIMEOUT, $role),
                'order'     => $i + 1,
            ];
        }

        /* Priority first, then the order in which they were configured. */
        usort($entries, function ($a, $b) {
            if ($a['server']['priority'] !== $b['server']['priority']) {
                return $a['server']['priority'] < $b['server']['priority'] ? -1 : 1;
            }

            return $a['order'] - $b['order'];
        });

        return new self($entries, $role);
    }

    public static function loadConfig($configFile = null)
    {
        $empty = ['primary_priority' => 0, 'servers' => []];

        if ($configFile === null) {
            $configFile = getenv('SPOTWEB_NNTP_FALLBACK_FILE');
            if (empty($configFile)) {
                $configFile = self::DEFAULT_CONFIG_FILE;
            }
        }
        if (!is_readable($configFile)) {
            return $empty;
        }

        $config = json_decode((string) file_get_contents($configFile), true);
        if (!is_array($config)) {
            SpotDebug::msg(SpotDebug::ERROR, 'nntp.failover.config_invalid', ['file' => $configFile]);

            return $empty;
        }

        $result = [
            'primary_priority' => isset($config['primary_priority']) ? (int) $config['primary_priority'] : 0,
            'servers'          => [],
        ];

        $servers = isset($config['servers']) && is_array($config['servers']) ? $config['servers'] : [];
        foreach ($servers as $server) {
            if (!is_array($server) || empty($server['host'])) {
                continue;
            }
            if (isset($server['enable']) && !self::bool($server['enable'])) {
                continue;
            }

            $enc = Services_Nntp_Encryption::normalize(isset($server['enc']) ? $server['enc'] : false);
            if ($enc === null) {
                SpotDebug::msg(SpotDebug::ERROR, 'nntp.failover.config_invalid_enc', ['host' => $server['host']]);
                continue;
            }
            $roles = isset($server['roles']) ? $server['roles'] : self::DEFAULT_ROLES;
            if (is_string($roles)) {
                $roles = preg_split('/[\s,]+/', $roles, -1, PREG_SPLIT_NO_EMPTY);
            }

            $result['servers'][] = [
                'name'                   => isset($server['name']) ? (string) $server['name'] : (string) $server['host'],
                'host'                   => trim((string) $server['host']),
                'port'                   => isset($server['port']) ? (int) $server['port'] : ($enc === Services_Nntp_Encryption::ImplicitTls ? 563 : 119),
                'user'                   => isset($server['user']) ? (string) $server['user'] : '',
                'pass'                   => isset($server['pass']) ? (string) $server['pass'] : '',
                'enc'                    => $enc,
                'verifyname'             => isset($server['verifyname']) ? self::bool($server['verifyname']) : true,
                'buggy'                  => false,
                'article_pipeline_depth' => isset($server['article_pipeline_depth']) ? (int) $server['article_pipeline_depth'] : Services_Nntp_PipelineDepth::DefaultDepth,
                'priority'               => isset($server['priority']) ? (int) $server['priority'] : 1,
                'roles'                  => array_values(array_map('strtolower', (array) $roles)),
            ];
        }

        return $result;
    }

    private static function bool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function __construct(array $entries, $role = 'direct', ?Services_Nntp_ServerPenaltyBox $penalties = null)
    {
        parent::__construct($entries[0]['server'], self::DEFAULT_TIMEOUT, $role);
        foreach ($entries as $entry) {
            $this->_children[] = $entry['transport'];
            $this->_servers[] = $entry['server'];
        }
        $this->_role = $role;
        $this->_penalties = $penalties ?: new Services_Nntp_ServerPenaltyBox();
    }

    /* Server list in priority order, without credentials. For logging and tests. */
    public function describeServers()
    {
        $list = [];
        foreach ($this->_servers as $server) {
            $list[] = $this->label($server).' (priority '.$server['priority'].')';
        }

        return $list;
    }

    public function getActiveServer()
    {
        return ($this->_active === null) ? null : $this->label($this->_servers[$this->_active]);
    }

    public function getOpenedConnectionCount()
    {
        $count = 0;
        foreach ($this->_children as $child) {
            $count += $child->getOpenedConnectionCount();
        }

        return $count;
    }

    /* ---- connection management -------------------------------------- */

    public function connect()
    {
        if ($this->_active !== null) {
            $this->_children[$this->_active]->connect();

            return;
        }

        $this->firstConnected();
    }

    public function disconnect()
    {
        foreach ($this->_children as $child) {
            $child->disconnect();
        }
        $this->_active = null;
        $this->_used = [];
    }

    public function quit()
    {
        foreach (array_keys($this->_used) as $i) {
            $this->_children[$i]->quit();
        }
        $this->_active = null;
        $this->_used = [];
    }

    public function withFreshConnection()
    {
        foreach (array_keys($this->_used) as $i) {
            if ($i === $this->_active) {
                $this->_children[$i]->withFreshConnection();
            } else {
                $this->_children[$i]->disconnect();
                unset($this->_used[$i]);
            }
        }
    }

    public function resetErrorCount()
    {
    }

    public function validateServer($group = 'free.pt')
    {
        $this->selectGroup($group);
        $this->quit();
    }

    /* ---- article-number commands: sticky per session ---------------- */

    public function selectGroup($group)
    {
        if ($this->_active !== null) {
            return $this->_children[$this->_active]->selectGroup($group);
        }

        $last = null;
        foreach ($this->candidates() as $i) {
            if (!$this->ensureConnected($i, $last)) {
                continue;
            }

            try {
                $result = $this->_children[$i]->selectGroup($group);
                $this->_active = $i;
                if ($i !== 0) {
                    $this->notice('nntp.failover.group', 'Using '.$this->label($this->_servers[$i]).' for '.$group);
                }

                return $result;
            } catch (Exception $x) {
                $last = $x;
                $this->notice('nntp.failover.group_failed', $this->label($this->_servers[$i]).' refused GROUP '.$group.': '.$x->getMessage());
            }
        }

        throw $this->exhausted($last);
    }

    public function getOverview($first, $last)
    {
        return $this->activeChild()->getOverview($first, $last);
    }

    public function getMessageIdByArticleNumber($articleNumber)
    {
        return $this->activeChild()->getMessageIdByArticleNumber($articleNumber);
    }

    public function getMessageIdList($first, $last)
    {
        return $this->activeChild()->getMessageIdList($first, $last);
    }

    public function sendNoop()
    {
        $this->activeChild()->sendNoop();
    }

    /* ---- message-id reads: per-article try-list --------------------- */

    public function getHeader($messageId)
    {
        return $this->tryEach('head', $messageId, function ($child) use ($messageId) {
            return $child->getHeader($messageId);
        });
    }

    public function getBody($messageId)
    {
        return $this->tryEach('body', $messageId, function ($child) use ($messageId) {
            return $child->getBody($messageId);
        });
    }

    public function getArticle($messageId)
    {
        return $this->tryEach('article', $messageId, function ($child) use ($messageId) {
            return $child->getArticle($messageId);
        });
    }

    public function fetchArticlesPipelined(array $messageIds, $window = self::DEFAULT_PIPELINE_WINDOW)
    {
        $order = [];
        foreach ($messageIds as $messageId) {
            $order[] = self::strip($messageId);
        }

        $found = [];       // id => result (220)
        $missing = [];     // id => last 430 result
        $remaining = $order;
        $lastError = null;

        foreach ($this->candidates() as $i) {
            if (empty($remaining)) {
                break;
            }
            if (!$this->ensureConnected($i, $lastError)) {
                continue;
            }

            $results = [];
            $unresolved = [];
            try {
                $results = $this->_children[$i]->fetchArticlesPipelined($remaining, $window);
            } catch (Services_Nntp_PipelinedFetchException $x) {
                $results = $x->terminalResults();
                $unresolved = $x->unresolvedMessageIds();
                $lastError = $x;
                $this->notice('nntp.failover.pipeline_failed', $this->label($this->_servers[$i]).': '.$x->getMessage().', '.count($unresolved).' unresolved');
                $this->recover($i);
            } catch (Exception $x) {
                $unresolved = $remaining;
                $lastError = $x;
                $this->notice('nntp.failover.pipeline_failed', $this->label($this->_servers[$i]).': '.$x->getMessage());
                $this->recover($i);
            }

            $next = [];
            foreach ($results as $result) {
                $id = self::strip($result->messageId);
                if ($result->found()) {
                    $found[$id] = $result;
                    unset($missing[$id]);
                } else {
                    $missing[$id] = $result;
                    $next[] = $id;
                }
            }
            foreach ($unresolved as $id) {
                $next[] = self::strip($id);
            }

            if (!empty($found) && $i !== 0 && count($next) < count($remaining)) {
                SpotDebug::msg(SpotDebug::DEBUG, 'nntp.failover.pipeline_filled', [
                    'role'   => $this->_role,
                    'server' => $this->label($this->_servers[$i]),
                    'filled' => count($remaining) - count($next),
                ]);
            }

            $remaining = array_values(array_unique($next));
        }

        /* Original order. An id that one server reported as 430 and no server delivered counts as
         * missing; an id no server answered for at all stays unresolved. */
        $results = [];
        $unresolvedIds = [];
        foreach ($order as $id) {
            if (isset($found[$id])) {
                $results[] = $found[$id];
            } elseif (isset($missing[$id])) {
                $results[] = $missing[$id];
            } else {
                $unresolvedIds[] = $id;
            }
        }

        if (!empty($unresolvedIds)) {
            $x = $lastError ?: new NntpException('No usable newsserver for role '.$this->_role, -1);

            throw new Services_Nntp_PipelinedFetchException(
                $x->getMessage(),
                $x->getCode(),
                $results,
                $unresolvedIds,
                Services_Nntp_PipelinedFetchException::classify($x, 'transport')
            );
        }

        return $results;
    }

    /* ---- posting: fail over only before anything was sent ----------- */

    public function post(array $article)
    {
        $last = null;
        foreach ($this->candidates() as $i) {
            if (!$this->ensureConnected($i, $last)) {
                continue;
            }

            /* Connected and logged in: whatever happens now is final. */
            return $this->_children[$i]->post($article);
        }

        throw $this->exhausted($last);
    }

    /* ---- helpers ----------------------------------------------------- */

    /*
     * Server indexes in priority order, skipping servers in the penalty box.
     * When every server is penalised, all of them are returned: trying is
     * better than failing without a single attempt.
     */
    private function candidates()
    {
        $list = [];
        foreach (array_keys($this->_children) as $i) {
            if (isset($this->_used[$i]) || !$this->_penalties->isPenalized($this->key($i))) {
                $list[] = $i;
            }
        }

        return empty($list) ? array_keys($this->_children) : $list;
    }

    private function ensureConnected($i, &$lastError)
    {
        try {
            $this->_children[$i]->connect();
            if (!isset($this->_used[$i])) {
                $this->_used[$i] = true;
                $this->_penalties->clear($this->key($i));
            }

            return true;
        } catch (Exception $x) {
            $lastError = $x;
            $minutes = Services_Nntp_ServerPenaltyBox::minutesFor($x);
            $this->_penalties->penalize($this->key($i), $minutes);
            unset($this->_used[$i]);
            $this->notice('nntp.failover.connect_failed', $this->label($this->_servers[$i]).' unavailable ('.$x->getMessage().'), skipped for '.$minutes.' min');

            return false;
        }
    }

    /* A pipeline that broke off leaves unread responses on the socket; start that server afresh. */
    private function recover($i)
    {
        try {
            $this->_children[$i]->withFreshConnection();
        } catch (Exception $x) {
            $this->_children[$i]->disconnect();
            unset($this->_used[$i]);
            if ($this->_active === $i) {
                $this->_active = null;
            }
        }
    }

    private function firstConnected()
    {
        $last = null;
        foreach ($this->candidates() as $i) {
            if ($this->ensureConnected($i, $last)) {
                return $i;
            }
        }

        throw $this->exhausted($last);
    }

    private function activeChild()
    {
        if ($this->_active === null) {
            /* No GROUP yet: article numbers are meaningless, but keep upstream's behaviour of
             * letting the server answer. Pin the first server that connects. */
            $this->_active = $this->firstConnected();
        }

        return $this->_children[$this->_active];
    }

    private function tryEach($operation, $messageId, $callback)
    {
        $last = null;
        $missing = null;
        foreach ($this->candidates() as $i) {
            if (!$this->ensureConnected($i, $last)) {
                continue;
            }

            try {
                $result = $callback($this->_children[$i]);
                if ($i !== 0) {
                    SpotDebug::msg(SpotDebug::DEBUG, 'nntp.failover.read', [
                        'role'      => $this->_role,
                        'operation' => $operation,
                        'server'    => $this->label($this->_servers[$i]),
                    ]);
                }

                return $result;
            } catch (Exception $x) {
                if ((int) $x->getCode() === 430) {
                    $missing = $x;
                } else {
                    $last = $x;
                    $this->notice('nntp.failover.read_failed', $this->label($this->_servers[$i]).' '.$operation.' failed: '.$x->getMessage());
                }
            }
        }

        /* Prefer "article missing" over a transport error: that is what the caller handles. */
        throw $missing ?: $this->exhausted($last);
    }

    private function exhausted($last)
    {
        if ($last instanceof Exception) {
            return $last;
        }

        return new NntpException('No usable newsserver for role '.$this->_role, -1);
    }

    private function key($i)
    {
        $s = $this->_servers[$i];

        return strtolower($s['host']).':'.(int) $s['port'].':'.(isset($s['user']) ? $s['user'] : '');
    }

    private function label(array $server)
    {
        $name = isset($server['name']) ? $server['name'] : '';
        $host = $server['host'].':'.(int) $server['port'];

        return ($name !== '' && $name !== $server['host']) ? $name.' ('.$host.')' : $host;
    }

    private function notice($event, $message)
    {
        SpotDebug::msg(SpotDebug::WARN, $event, ['role' => $this->_role, 'message' => $message]);
        if (PHP_SAPI === 'cli' && getenv('SPOTWEB_NNTP_FAILOVER_QUIET') === false) {
            fwrite(STDERR, '[nntp-failover] '.$this->_role.': '.$message.PHP_EOL);
        }
    }

    private static function strip($messageId)
    {
        $messageId = trim((string) $messageId);
        if ((strlen($messageId) >= 2) && ($messageId[0] === '<') && (substr($messageId, -1) === '>')) {
            return substr($messageId, 1, -1);
        }

        return $messageId;
    }
}

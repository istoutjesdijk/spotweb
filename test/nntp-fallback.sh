#!/usr/bin/env bash
# Tests the fallback newsserver support inside the built image against fake
# NNTP servers, so no Usenet account is needed. Called from smoke.sh and can be
# run on its own:
#
#   SPOTWEB_IMAGE=spotweb:test ./test/nntp-fallback.sh
#
set -euo pipefail

cd "$(dirname "$0")"
IMAGE="${SPOTWEB_IMAGE:-spotweb:test}"

echo "Running fallback newsserver tests in $IMAGE"
docker run --rm -v "$PWD/nntp-fallback:/t:ro" --entrypoint sh "$IMAGE" -c '
    set -e
    php /t/fake-nntp-server.php 11901 b 1 &
    php /t/fake-nntp-server.php 11902 a,b,c 1 &
    sleep 1
    php /t/driver.php
'

echo "Checking the entrypoint config generator"
docker run --rm --entrypoint sh \
    -e SPOTWEB_NNTP_FALLBACK_1_HOST=news.example.invalid \
    -e SPOTWEB_NNTP_FALLBACK_1_ENC=ssl \
    -e 'SPOTWEB_NNTP_FALLBACK_1_PASS=p"a$s' \
    -e SPOTWEB_NNTP_FALLBACK_2_HOST=block.example.invalid \
    -e SPOTWEB_NNTP_FALLBACK_2_PRIORITY=5 \
    -e SPOTWEB_NNTP_FALLBACK_2_ROLES=bin \
    "$IMAGE" -c '
    php /usr/local/lib/spotweb/nntp-fallback-config.php > /tmp/c.json
    php -r "
        require \"/var/www/spotweb/vendor/autoload.php\";
        require_once \"/var/www/spotweb/lib/services/Nntp/Services_Nntp_FailoverTransport.php\";
        \$c = Services_Nntp_FailoverTransport::loadConfig(\"/tmp/c.json\");
        \$s = \$c[\"servers\"];
        \$ok = count(\$s) === 2
            && \$s[0][\"port\"] === 563 && \$s[0][\"pass\"] === \"p\\\"a\\\$s\" && \$s[0][\"roles\"] === [\"hdr\", \"bin\"]
            && \$s[1][\"port\"] === 119 && \$s[1][\"priority\"] === 5 && \$s[1][\"roles\"] === [\"bin\"];
        if (!\$ok) { echo json_encode(\$c), PHP_EOL; exit(1); }
        echo \"ok   config generator\", PHP_EOL;
    "
'

echo "Fallback newsserver tests passed"

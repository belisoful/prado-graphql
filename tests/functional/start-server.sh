#!/usr/bin/env bash
# Starts the PHP built-in server for Playwright functional tests on port 8037
set -euo pipefail
PHP_PID=""
cleanup() {
    if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
        kill "$PHP_PID" 2>/dev/null || true
        wait "$PHP_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT SIGTERM SIGINT SIGHUP
php -q -S 127.0.0.1:8037 -t ./ &
PHP_PID=$!
PARENT_PID=$PPID
while kill -0 "$PHP_PID" 2>/dev/null; do
    if ! kill -0 "$PARENT_PID" 2>/dev/null; then
        exit 0
    fi
    sleep 0.1
done

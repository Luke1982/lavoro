#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
DATA="/tmp/claude-1000/-home-guido-nvme0n1p1-code-lavoro/a0589882-9c03-425e-bdbc-d16eaacf1bdb/scratchpad/mysql/data"
SOCK=/tmp/lavoro-mt.sock

up()  { mysqladmin --no-defaults -u root -S "$SOCK" ping >/dev/null 2>&1; }
serving() { curl -s -o /dev/null http://127.0.0.1:8123/login 2>/dev/null; }

case "${1:-status}" in
  start)
    if up; then echo "mysql: already running"; else
      [ -d "$DATA" ] || { echo "datadir is gone: $DATA" >&2; exit 1; }
      nohup /usr/sbin/mysqld --no-defaults --datadir="$DATA" --socket="$SOCK" \
        --port=3307 --mysqlx=0 --pid-file=/tmp/lavoro-mt.pid \
        --log-error=/tmp/lavoro-mt-error.log --secure-file-priv="" >/dev/null 2>&1 &
      disown
      for i in $(seq 1 30); do up && break; sleep 1; done
      up && echo "mysql: started on 3307" || { echo "mysql failed, see /tmp/lavoro-mt-error.log" >&2; exit 1; }
    fi
    if serving; then echo "app: already running"; else
      nohup php artisan serve --host=127.0.0.1 --port=8123 >/tmp/lavoro-serve.log 2>&1 &
      disown
      for i in $(seq 1 20); do serving && break; sleep 1; done
      echo "app: http://127.0.0.1:8123  (info@speetotaaltechniek.nl / tenancytest)"
    fi ;;
  stop)
    pkill -f "port=8123" 2>/dev/null || true
    pkill -f "artisan serve" 2>/dev/null || true
    mysqladmin --no-defaults -u root -S "$SOCK" shutdown 2>/dev/null || true
    echo "stopped" ;;
  status)
    up && echo "mysql: up (3307)" || echo "mysql: down"
    serving && echo "app:   up (8123)" || echo "app:   down" ;;
  *) echo "usage: ./trial.sh {start|stop|status}" >&2; exit 2 ;;
esac

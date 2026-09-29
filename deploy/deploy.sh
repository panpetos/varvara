#!/usr/bin/env bash
# Деплой статики thousandli.ru на хостинг Рег.ру по SSH (rsync).
# Запускать с машины, у которой есть прямой доступ к серверу по SSH.
#
# Использование:
#   1) cp deploy/.env.example deploy/.env  &&  заполнить пароль в deploy/.env
#   2) bash deploy/deploy.sh
#
# Требуется: rsync + ssh (+ sshpass, если пароль, а не ключ).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck disable=SC1091
source "$SCRIPT_DIR/.env"

: "${SSH_HOST:?}"; : "${SSH_USER:?}"; : "${REMOTE_DIR:?}"
SSH_PORT="${SSH_PORT:-22}"

SSH_CMD=(ssh -p "$SSH_PORT" -o StrictHostKeyChecking=accept-new)
RSYNC_PREFIX=()
if [ -n "${SSH_PASSWORD:-}" ]; then
  command -v sshpass >/dev/null || { echo "Нужен sshpass для входа по паролю"; exit 1; }
  RSYNC_PREFIX=(sshpass -p "$SSH_PASSWORD")
  SSH_CMD=(sshpass -p "$SSH_PASSWORD" ssh -p "$SSH_PORT" -o StrictHostKeyChecking=accept-new)
fi

echo ">> Создаю папку на сервере: $REMOTE_DIR"
"${SSH_CMD[@]}" "$SSH_USER@$SSH_HOST" "mkdir -p '$REMOTE_DIR'"

echo ">> Заливаю файлы через rsync"
"${RSYNC_PREFIX[@]}" rsync -avz --delete \
  --exclude '.git' \
  --exclude 'deploy' \
  --exclude '.gitignore' \
  --exclude 'README.md' \
  -e "ssh -p $SSH_PORT -o StrictHostKeyChecking=accept-new" \
  "$PROJECT_DIR/" "$SSH_USER@$SSH_HOST:$REMOTE_DIR/"

echo ">> Готово. Проверь https://thousandli.ru/"

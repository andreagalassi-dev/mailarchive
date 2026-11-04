#!/usr/bin/env bash
# Assembla in release/ la cartella pronta da caricare sulla document root del server:
# backend PHP + frontend compilato. Non include mai .env, database o storage reale.
set -euo pipefail
cd "$(dirname "$0")/.."

(cd frontend && npm install --no-audit --no-fund && npm run build)

rm -rf release && mkdir release
rsync -a \
  --include='storage/*/.gitkeep' --exclude='storage/*/*' \
  --exclude='.env' --exclude='.DS_Store' --exclude='scripts/dev-router.php' \
  backend/ release/
cp -R frontend/dist/. release/

echo "Pronto: release/ (carica il contenuto nella document root, poi crea .env da .env.example)"

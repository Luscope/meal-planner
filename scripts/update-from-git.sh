#!/bin/sh
set -e

cd "$(dirname "$0")/.."

git pull --ff-only

docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml run --rm migrate

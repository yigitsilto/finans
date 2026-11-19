#!/bin/sh
# cPanel için yüklenebilir paket üretir: build/zafir-finans-cpanel.zip
#
# Docker'daki app container'ı (PHP 8.3 + Composer) kullanılır; bağımlılıklar --no-dev ile
# sunucudakiyle aynı PHP sürümüne göre kurulur. .env, testler, Docker dosyaları ve
# git geçmişi pakete GİRMEZ.
#
# Kullanım: sh scripts/build-cpanel.sh [klasör-adı]   (varsayılan: zafir-finans)
#   ör. public_html/finans için: sh scripts/build-cpanel.sh finans
set -eu
APP_DIR="${1:-zafir-finans}"

cd "$(dirname "$0")/.."
mkdir -p build

docker compose exec -T -e APP_DIR="$APP_DIR" app sh -eu -c '
    rm -rf /tmp/zafir-build && mkdir -p /tmp/zafir-build/$APP_DIR
    tar -C /var/www/html \
        --exclude=./.git --exclude=./.env --exclude="./.env.*" --exclude=./build --exclude=./vendor \
        --exclude=./node_modules --exclude=./tests --exclude=./docker --exclude=./Dockerfile \
        --exclude="./docker-compose*.yml" --exclude=./.dockerignore --exclude=./phpunit.xml \
        --exclude=./.phpunit.result.cache --exclude=./.phpunit.cache --exclude=./.claude --exclude=./.idea --exclude=./scripts --exclude=./phpunit.mariadb.xml \
        --exclude="./storage/logs/*" --exclude="./storage/framework/cache/data/*" \
        --exclude="./storage/framework/sessions/*" --exclude="./storage/framework/views/*" \
        --exclude="./storage/app/private/*" --exclude=./database/database.sqlite \
        -cf - . | tar -C /tmp/zafir-build/$APP_DIR -xf -
    cp /var/www/html/.env.cpanel.example /tmp/zafir-build/$APP_DIR/.env.cpanel.example

    cd /tmp/zafir-build/$APP_DIR
    composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet
    mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
    rm -f bootstrap/cache/*.php

    php -r "
        \$zip = new ZipArchive();
        \$target = \"/var/www/html/build/$APP_DIR-cpanel.zip\";
        @unlink(\$target);
        \$zip->open(\$target, ZipArchive::CREATE);
        \$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\"/tmp/zafir-build\", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach (\$it as \$file) {
            \$local = substr(\$file->getPathname(), strlen(\"/tmp/zafir-build/\"));
            \$file->isDir() ? \$zip->addEmptyDir(\$local) : \$zip->addFile(\$file->getPathname(), \$local);
        }
        \$zip->close();
    "
    rm -rf /tmp/zafir-build
'

ls -lh "build/$APP_DIR-cpanel.zip"

#!/bin/sh
# cPanel için yüklenebilir paket üretir: build/zafir-finans-cpanel.zip
#
# Docker'daki app container'ı (PHP 8.3 + Composer) kullanılır; bağımlılıklar --no-dev ile
# sunucudakiyle aynı PHP sürümüne göre kurulur. .env, testler, Docker dosyaları ve
# git geçmişi pakete GİRMEZ.
#
# Kullanım: sh scripts/build-cpanel.sh
set -eu

cd "$(dirname "$0")/.."
mkdir -p build

docker compose exec -T app sh -eu -c '
    rm -rf /tmp/zafir-build && mkdir -p /tmp/zafir-build/zafir-finans
    tar -C /var/www/html \
        --exclude=./.git --exclude=./.env --exclude="./.env.*" --exclude=./build --exclude=./vendor \
        --exclude=./node_modules --exclude=./tests --exclude=./docker --exclude=./Dockerfile \
        --exclude="./docker-compose*.yml" --exclude=./.dockerignore --exclude=./phpunit.xml \
        --exclude=./.phpunit.result.cache --exclude=./.phpunit.cache --exclude=./.claude --exclude=./.idea --exclude=./scripts \
        --exclude="./storage/logs/*" --exclude="./storage/framework/cache/data/*" \
        --exclude="./storage/framework/sessions/*" --exclude="./storage/framework/views/*" \
        --exclude="./storage/app/private/*" --exclude=./database/database.sqlite \
        -cf - . | tar -C /tmp/zafir-build/zafir-finans -xf -
    cp /var/www/html/.env.cpanel.example /tmp/zafir-build/zafir-finans/.env.cpanel.example

    cd /tmp/zafir-build/zafir-finans
    composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet
    mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
    rm -f bootstrap/cache/*.php

    php -r "
        \$zip = new ZipArchive();
        \$target = \"/var/www/html/build/zafir-finans-cpanel.zip\";
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

ls -lh build/zafir-finans-cpanel.zip

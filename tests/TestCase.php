<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Güvenlik kilidi: testler asla geliştirme/production veritabanına dokunmamalı.
        $database = config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException("Testler yalnızca *_test veritabanında çalışır. Mevcut: {$database}");
        }
    }
}

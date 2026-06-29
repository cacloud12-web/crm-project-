<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\PreparesCrmDatabase;

abstract class TestCase extends BaseTestCase
{
    use PreparesCrmDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareCrmDatabaseForTesting();
        Auth::logout();
    }
}

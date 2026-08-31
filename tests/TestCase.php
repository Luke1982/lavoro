<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\RefreshesTenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshesTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenancy();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();

        parent::tearDown();
    }
}

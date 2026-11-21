<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Locale the test application boots under; every URL starts with it.
     */
    public const LOCALE = 'bn';
}

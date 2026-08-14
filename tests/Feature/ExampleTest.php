<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function testBasicTest()
    {
        // The legacy index.php has been migrated to the Laravel home route;
        // unauthenticated visitors are sent to the login page.
        $response = $this->get('/');

        $response->assertStatus(302);
        $response->assertLocation('http://localhost/login.php?returnto=http%3A%2F%2Flocalhost');
    }
}

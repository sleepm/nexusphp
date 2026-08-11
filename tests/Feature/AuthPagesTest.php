<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthPagesTest extends TestCase
{
    public function testLoginPage()
    {
        $this->get('/login.php')->assertStatus(200)->assertSee('login-form', false);
        $this->get('/login')->assertStatus(200);
    }

    public function testSignupPage()
    {
        $this->get('/signup.php')->assertStatus(200);
        $this->get('/signup')->assertStatus(200);
    }

    public function testRecoverPage()
    {
        $this->get('/recover.php')->assertStatus(200);
    }

    public function testResetPage()
    {
        $this->get('/reset.php')->assertStatus(200);
    }

    public function testConfirmResendPage()
    {
        $this->get('/confirm_resend.php')->assertStatus(200);
    }

    public function testConfirmInvalidRedirects()
    {
        $this->get('/confirm.php')->assertStatus(404);
        $this->get('/confirm.php?id=99999&secret=abc')->assertStatus(404);
        $this->get('/confirmemail.php/99999/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/a%40b.com')->assertStatus(404);
    }

    public function testAuthenticatedPagesRequireLogin()
    {
        $this->get('/self-enable.php')->assertRedirect();
        $this->get('/checkuser.php?id=1')->assertRedirect();
        $this->get('/maxlogin.php')->assertRedirect();
    }

    public function testLogoutRedirects()
    {
        $this->get('/logout.php')->assertRedirect();
    }
}

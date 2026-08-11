<?php

namespace App\Models;

class LoginAttempt extends NexusModel
{
    public $timestamps = false;

    protected $table = 'loginattempts';

    protected $fillable = ['ip', 'added', 'banned', 'attempts', 'type'];
}

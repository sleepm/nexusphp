<?php

namespace App\Models;

class Bitbucket extends NexusModel
{
    protected $table = 'bitbucket';

    protected $fillable = [
        'owner', 'name', 'added', 'public',
    ];

    protected $casts = [
        'added' => 'datetime',
    ];
}

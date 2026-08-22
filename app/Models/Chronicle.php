<?php

namespace App\Models;


class Chronicle extends NexusModel
{
    protected $table = 'chronicle';

    protected $fillable = [
        'userid', 'added', 'txt',
    ];
}

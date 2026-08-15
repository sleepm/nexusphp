<?php

namespace App\Models;

class Suggest extends NexusModel
{
    protected $table = 'suggest';

    protected $fillable = [
        'keywords', 'userid', 'adddate',
    ];
}
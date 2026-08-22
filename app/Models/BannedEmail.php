<?php

namespace App\Models;

class BannedEmail extends NexusModel
{
    protected $table = 'bannedemails';

    protected $fillable = [
        'value',
    ];
}
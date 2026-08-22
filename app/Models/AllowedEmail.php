<?php

namespace App\Models;

class AllowedEmail extends NexusModel
{
    protected $table = 'allowedemails';

    protected $fillable = [
        'value',
    ];
}
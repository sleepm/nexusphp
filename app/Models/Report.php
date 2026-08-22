<?php

namespace App\Models;

class Report extends NexusModel
{
    protected $table = 'reports';

    protected $fillable = [
        'addedby', 'added', 'reportid', 'type', 'reason', 'dealtby', 'dealtwith',
    ];

    protected $casts = [
        'added' => 'datetime',
    ];
}
<?php

namespace App\Models;

class Location extends NexusModel
{
    protected $fillable = [
        'name', 'flagpic', 'location_main', 'location_sub',
        'start_ip', 'end_ip',
        'theory_upspeed', 'practical_upspeed',
        'theory_downspeed', 'practical_downspeed',
    ];
}

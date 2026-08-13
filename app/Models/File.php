<?php

namespace App\Models;


class File extends NexusModel
{
    protected $table = 'files';

    protected $fillable = ['torrent', 'filename', 'size'];
}

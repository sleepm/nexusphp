<?php

namespace App\Models;

class Attachment extends NexusModel
{
    const IMG_EXTENSIONS = ['jpeg', 'jpg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic'];

    protected $fillable = ['userid', 'width', 'added', 'filename', 'filetype', 'filesize', 'location', 'dlkey', 'downloads', 'isimage', 'thumb', 'driver'];
}

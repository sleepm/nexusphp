<?php

namespace App\Filament\Resources\TorrentCustomFields\Pages;

use App\Filament\CreateRedirectIndexTrait;
use App\Filament\Resources\TorrentCustomFields\TorrentCustomFieldResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTorrentCustomField extends CreateRecord
{
    use CreateRedirectIndexTrait;

    protected static string $resource = TorrentCustomFieldResource::class;
}

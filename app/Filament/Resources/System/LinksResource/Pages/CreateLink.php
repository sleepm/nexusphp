<?php

namespace App\Filament\Resources\System\LinksResource\Pages;

use App\Filament\CreateRedirectIndexTrait;
use App\Filament\Resources\System\LinksResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLink extends CreateRecord
{
    use CreateRedirectIndexTrait;

    protected static string $resource = LinksResource::class;
}

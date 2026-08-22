<?php

namespace App\Filament\Resources\System\LinksResource\Pages;

use App\Filament\Resources\System\LinksResource;
use Filament\Actions\CreateAction;

class ListLinks extends \App\Filament\PageList
{
    protected static string $resource = LinksResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

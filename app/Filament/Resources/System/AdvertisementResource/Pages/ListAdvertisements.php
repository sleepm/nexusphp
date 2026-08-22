<?php

namespace App\Filament\Resources\System\AdvertisementResource\Pages;

use App\Filament\Resources\System\AdvertisementResource;
use Filament\Resources\Pages\ListRecords;

class ListAdvertisements extends \App\Filament\PageList
{
    protected static string $resource = AdvertisementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}

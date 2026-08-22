<?php

namespace App\Filament\Resources\System\AdvertisementResource\Pages;

use App\Filament\CreateRedirectIndexTrait;
use App\Filament\Resources\System\AdvertisementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdvertisement extends CreateRecord
{
    use CreateRedirectIndexTrait;

    protected static string $resource = AdvertisementResource::class;

    protected function afterCreate()
    {
        $this->record->regenerateCode();
        $this->record->save();
    }
}

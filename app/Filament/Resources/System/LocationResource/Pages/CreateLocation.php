<?php

namespace App\Filament\Resources\System\LocationResource\Pages;

use App\Filament\CreateRedirectIndexTrait;
use App\Filament\Resources\System\LocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    use CreateRedirectIndexTrait;

    protected static string $resource = LocationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        foreach (['theory_upspeed', 'practical_upspeed', 'theory_downspeed', 'practical_downspeed'] as $field) {
            $data[$field] = (int) ($data[$field] ?? 10);
        }

        return $data;
    }
}

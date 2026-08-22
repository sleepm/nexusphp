<?php

namespace App\Filament\Resources\System\RuleResource\Pages;

use App\Filament\CreateRedirectIndexTrait;
use App\Filament\Resources\System\RuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRule extends CreateRecord
{
    use CreateRedirectIndexTrait;

    protected static string $resource = RuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['lang_id'] = intval($data['lang_id'] ?? 6);

        return $data;
    }
}

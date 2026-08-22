<?php

namespace App\Filament\Resources\System\RuleResource\Pages;

use App\Filament\EditRedirectIndexTrait;
use App\Filament\Resources\System\RuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRule extends EditRecord
{
    use EditRedirectIndexTrait;

    protected static string $resource = RuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['lang_id'] = intval($data['lang_id'] ?? $this->record->lang_id);

        return $data;
    }
}

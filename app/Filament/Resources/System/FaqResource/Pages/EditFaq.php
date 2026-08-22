<?php

namespace App\Filament\Resources\System\FaqResource\Pages;

use App\Filament\EditRedirectIndexTrait;
use App\Filament\Resources\System\FaqResource;
use App\Models\Faq;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFaq extends EditRecord
{
    use EditRedirectIndexTrait;

    protected static string $resource = FaqResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = parent::mutateFormDataBeforeSave($data);
        if (($data['type'] ?? $this->record->type) === Faq::TYPE_CATEG) {
            $data['categ'] = 0;
            $data['answer'] = '';
        }
        return $data;
    }
}

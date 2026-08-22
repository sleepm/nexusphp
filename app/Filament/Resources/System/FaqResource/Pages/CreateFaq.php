<?php

namespace App\Filament\Resources\System\FaqResource\Pages;

use App\Filament\CreateRedirectIndexTrait;
use App\Filament\Resources\System\FaqResource;
use App\Models\Faq;
use Filament\Resources\Pages\CreateRecord;

class CreateFaq extends CreateRecord
{
    use CreateRedirectIndexTrait;

    protected static string $resource = FaqResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);
        $type = $data['type'] ?? Faq::TYPE_ITEM;
        $langId = intval($data['lang_id'] ?? 0);
        $query = Faq::query()->where('type', $type)->where('lang_id', $langId);
        if ($type === Faq::TYPE_ITEM) {
            $query->where('categ', intval($data['categ'] ?? 0));
        }
        $max = $query->selectRaw('MAX(`order`) AS max_order, MAX(`link_id`) AS max_link_id')->first();
        $data['order'] = intval($data['order'] ?? 0) ?: (intval($max->max_order ?? 0) + 1);
        $data['link_id'] = intval($max->max_link_id ?? 0) + 1;
        if ($type === Faq::TYPE_CATEG) {
            $data['categ'] = 0;
            $data['answer'] = '';
        }
        return $data;
    }
}

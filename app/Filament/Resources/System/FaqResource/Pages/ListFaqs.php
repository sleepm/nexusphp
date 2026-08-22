<?php

namespace App\Filament\Resources\System\FaqResource\Pages;

use App\Filament\Resources\System\FaqResource;
use Filament\Resources\Pages\ListRecords;

class ListFaqs extends \App\Filament\PageList
{
    protected static string $resource = FaqResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}

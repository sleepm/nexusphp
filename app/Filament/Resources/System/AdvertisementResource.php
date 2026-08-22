<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\System\AdvertisementResource\Pages\ListAdvertisements;
use App\Filament\Resources\System\AdvertisementResource\Pages\CreateAdvertisement;
use App\Filament\Resources\System\AdvertisementResource\Pages\EditAdvertisement;
use App\Filament\Resources\System\AdvertisementResource\Pages;
use App\Filament\Resources\System\AdvertisementResource\RelationManagers;
use App\Models\Advertisement;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AdvertisementResource extends Resource
{
    protected static ?string $model = Advertisement::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-megaphone';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.advertisements');
    }

    public static function getBreadcrumb(): string
    {
        return self::getNavigationLabel();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->label(__('label.advertisement.name')),
                        Select::make('position')
                            ->options(Advertisement::listPositions())
                            ->required()
                            ->label(__('label.advertisement.position'))
                        ,
                        Select::make('type')
                            ->options(Advertisement::listTypes())
                            ->required()
                            ->live()
                            ->label(__('label.advertisement.type'))
                        ,
                        TextInput::make('displayorder')
                            ->integer()
                            ->default(0)
                            ->label(__('label.priority'))
                        ,
                        Toggle::make('enabled')->default(true)->label(__('label.enabled')),
                        DateTimePicker::make('starttime')->label(__('label.advertisement.start_time')),
                        DateTimePicker::make('endtime')->label(__('label.advertisement.end_time')),
                    ]),
                Section::make(__('label.advertisement.parameters'))
                    ->visible(fn (Get $get) => in_array($get('type'), [
                        Advertisement::TYPE_IMAGE, Advertisement::TYPE_TEXT, Advertisement::TYPE_BBCODES,
                        Advertisement::TYPE_XHTML, Advertisement::TYPE_FLASH,
                    ]))
                    ->schema([
                        TextInput::make('parameters.image.url')
                            ->label(__('label.advertisement.image_url'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_IMAGE)
                        ,
                        TextInput::make('parameters.image.link')
                            ->label(__('label.advertisement.link'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_IMAGE)
                        ,
                        TextInput::make('parameters.image.width')
                            ->numeric()
                            ->label(__('label.advertisement.image_width'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_IMAGE)
                        ,
                        TextInput::make('parameters.image.height')
                            ->numeric()
                            ->label(__('label.advertisement.image_height'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_IMAGE)
                        ,
                        TextInput::make('parameters.image.title')
                            ->label(__('label.advertisement.image_title'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_IMAGE)
                        ,
                        TextInput::make('parameters.text.content')
                            ->label(__('label.advertisement.text_content'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_TEXT)
                        ,
                        TextInput::make('parameters.text.link')
                            ->label(__('label.advertisement.link'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_TEXT)
                        ,
                        TextInput::make('parameters.text.size')
                            ->label(__('label.advertisement.text_size'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_TEXT)
                        ,
                        Textarea::make('parameters.bbcodes.code')
                            ->rows(6)
                            ->label(__('label.advertisement.code'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_BBCODES)
                        ,
                        Textarea::make('parameters.xhtml.code')
                            ->rows(6)
                            ->label(__('label.advertisement.code'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_XHTML)
                        ,
                        TextInput::make('parameters.flash.url')
                            ->label(__('label.advertisement.flash_url'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_FLASH)
                        ,
                        TextInput::make('parameters.flash.width')
                            ->numeric()
                            ->label(__('label.advertisement.flash_width'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_FLASH)
                        ,
                        TextInput::make('parameters.flash.height')
                            ->numeric()
                            ->label(__('label.advertisement.flash_height'))
                            ->visible(fn (Get $get) => $get('type') === Advertisement::TYPE_FLASH)
                        ,
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                IconColumn::make('enabled')->boolean()->label(__('label.enabled')),
                TextColumn::make('name')->searchable()->label(__('label.advertisement.name')),
                TextColumn::make('position')->label(__('label.advertisement.position')),
                TextColumn::make('displayorder')->label(__('label.priority'))->sortable(),
                TextColumn::make('type')->label(__('label.advertisement.type')),
                TextColumn::make('starttime')->dateTime()->label(__('label.advertisement.start_time')),
                TextColumn::make('endtime')->dateTime()->label(__('label.advertisement.end_time')),
                TextColumn::make('clicks_count')->label(__('label.advertisement.clicks'))->counts('clicks')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('position')->options(Advertisement::listPositions())->label(__('label.advertisement.position')),
                SelectFilter::make('type')->options(Advertisement::listTypes())->label(__('label.advertisement.type')),
                SelectFilter::make('enabled')->options([1 => __('label.enabled'), 0 => __('label.disabled')])->label(__('label.enabled')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdvertisements::route('/'),
            'create' => CreateAdvertisement::route('/create'),
            'edit' => EditAdvertisement::route('/{record}/edit'),
        ];
    }
}

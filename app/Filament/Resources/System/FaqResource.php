<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\System\FaqResource\Pages\ListFaqs;
use App\Filament\Resources\System\FaqResource\Pages\CreateFaq;
use App\Filament\Resources\System\FaqResource\Pages\EditFaq;
use App\Filament\Resources\System\FaqResource\Pages;
use App\Filament\Resources\System\FaqResource\RelationManagers;
use App\Models\Faq;
use App\Models\Language;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FaqResource extends Resource
{
    protected static ?string $model = Faq::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.faq');
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
                        Select::make('type')
                            ->options([
                                Faq::TYPE_CATEG => __('label.faq.section'),
                                Faq::TYPE_ITEM => __('label.faq.item'),
                            ])
                            ->required()
                            ->live()
                            ->label(__('label.faq.type'))
                        ,
                        Select::make('lang_id')
                            ->options(Language::query()->pluck('lang_name', 'id'))
                            ->required()
                            ->live()
                            ->label(__('label.language'))
                        ,
                        TextInput::make('question')->required()->label(__('label.faq.question')),
                        Textarea::make('answer')
                            ->rows(6)
                            ->label(__('label.faq.answer'))
                            ->hidden(fn (Get $get) => $get('type') === Faq::TYPE_CATEG)
                        ,
                        Select::make('flag')
                            ->options(Faq::$flags)
                            ->default(Faq::FLAG_NORMAL)
                            ->label(__('label.faq.flag'))
                        ,
                        Select::make('categ')
                            ->options(fn (Get $get) => Faq::query()
                                ->where('type', Faq::TYPE_CATEG)
                                ->where('lang_id', $get('lang_id'))
                                ->pluck('question', 'link_id'))
                            ->label(__('label.faq.parent_section'))
                            ->hidden(fn (Get $get) => $get('type') === Faq::TYPE_CATEG)
                        ,
                        TextInput::make('order')
                            ->integer()
                            ->default(0)
                            ->label(__('label.priority'))
                        ,
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('language.lang_name')->label(__('label.language')),
                TextColumn::make('type')
                    ->label(__('label.faq.type'))
                    ->formatStateUsing(fn (string $state) => $state === Faq::TYPE_CATEG ? __('label.faq.section') : __('label.faq.item'))
                ,
                TextColumn::make('question')->label(__('label.faq.question'))->limit(60)->searchable(),
                TextColumn::make('flag')
                    ->label(__('label.faq.flag'))
                    ->formatStateUsing(fn (int $state) => Faq::$flags[$state] ?? $state)
                ,
                TextColumn::make('order')->label(__('label.priority'))->sortable(),
            ])
            ->defaultSort('lang_id', 'asc')
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        Faq::TYPE_CATEG => __('label.faq.section'),
                        Faq::TYPE_ITEM => __('label.faq.item'),
                    ])
                    ->label(__('label.faq.type'))
                ,
                SelectFilter::make('lang_id')
                    ->options(Language::query()->pluck('lang_name', 'id'))
                    ->label(__('label.language'))
                ,
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
            'index' => ListFaqs::route('/'),
            'create' => CreateFaq::route('/create'),
            'edit' => EditFaq::route('/{record}/edit'),
        ];
    }
}
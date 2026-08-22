<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\RuleResource\Pages;
use App\Filament\Resources\System\RuleResource\RelationManagers;
use App\Models\Language;
use App\Models\Rule;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RuleResource extends Resource
{
    protected static ?string $model = Rule::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 18;

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.rules');
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
                        Select::make('lang_id')
                            ->options(Language::query()->pluck('lang_name', 'id'))
                            ->required()
                            ->default(6)
                            ->label(__('label.language')),
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->label(__('label.rules.title')),
                        Textarea::make('text')
                            ->required()
                            ->rows(12)
                            ->columnSpanFull()
                            ->label(__('label.rules.text')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('language.lang_name')->label(__('label.language')),
                TextColumn::make('title')->label(__('label.rules.title'))->searchable()->limit(60),
                TextColumn::make('text')->label(__('label.rules.text'))->limit(60),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('lang_id')
                    ->options(Language::query()->pluck('lang_name', 'id'))
                    ->label(__('label.language')),
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
            'index' => Pages\ListRules::route('/'),
            'create' => Pages\CreateRule::route('/create'),
            'edit' => Pages\EditRule::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\BansResource\Pages;
use App\Filament\Resources\System\BansResource\RelationManagers;
use App\Models\Ban;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class BansResource extends Resource
{
    protected static ?string $model = Ban::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 14;

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.bans');
    }

    public static function getBreadcrumb(): string
    {
        return self::getNavigationLabel();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('first')
                            ->label(__('label.ban.first'))
                            ->required()
                            ->placeholder('0.0.0.0'),
                        Forms\Components\TextInput::make('last')
                            ->label(__('label.ban.last'))
                            ->required()
                            ->placeholder('255.255.255.255'),
                        Forms\Components\TextInput::make('comment')
                            ->label(__('label.comment'))
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('added')
                    ->label(__('label.added'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('first')
                    ->label(__('label.ban.first'))
                    ->formatStateUsing(fn ($record) => long2ip($record->first)),
                TextColumn::make('last')
                    ->label(__('label.ban.last'))
                    ->formatStateUsing(fn ($record) => long2ip($record->last)),
                TextColumn::make('addedby')
                    ->label(__('label.ban.addedby'))
                    ->formatStateUsing(fn ($record) => new HtmlString(get_username($record->addedby, false, true, true, true))),
                TextColumn::make('comment')
                    ->label(__('label.comment'))
                    ->limit(60),
            ])
            ->defaultSort('added', 'desc')
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
            'index' => Pages\ListBans::route('/'),
            'create' => Pages\CreateBan::route('/create'),
            'edit' => Pages\EditBan::route('/{record}/edit'),
        ];
    }
}

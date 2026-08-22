<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\UserBanLogResource\Pages;
use App\Filament\Resources\System\UserBanLogResource\RelationManagers;
use App\Models\UserBanLog;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class UserBanLogResource extends Resource
{
    protected static ?string $model = UserBanLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 17;

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.user_ban_log');
    }

    public static function getBreadcrumb(): string
    {
        return self::getNavigationLabel();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('uid')
                    ->label(__('label.user_ban_log.uid'))
                    ->formatStateUsing(fn ($state) => username_for_admin($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label(__('label.username'))
                    ->searchable(),
                TextColumn::make('operator')
                    ->label(__('label.operator'))
                    ->formatStateUsing(fn ($state) => username_for_admin($state)),
                TextColumn::make('reason')
                    ->label(__('label.reason'))
                    ->limit(60),
                TextColumn::make('created_at')
                    ->label(__('label.created_at'))
                    ->formatStateUsing(fn ($state) => format_datetime($state))
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Filter::make('uid')
                    ->schema([
                        TextInput::make('uid')
                            ->label(__('label.user_ban_log.uid'))
                            ->placeholder('UID'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query->when($data['uid'], fn (Builder $query, $value) => $query->where('uid', $value));
                    }),
            ])
            ->recordActions([
//                Tables\Actions\EditAction::make(),
//                Tables\Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
//                Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ManageUserBanLogs::route('/'),
        ];
    }
}

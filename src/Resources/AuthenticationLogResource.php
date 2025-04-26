<?php

namespace Tapp\FilamentAuthenticationLog\Resources;

use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;
use Rappasoft\LaravelAuthenticationLog\Models\AuthenticationLog;
use Tapp\FilamentAuthenticationLog\FilamentAuthenticationLogPlugin;
use Tapp\FilamentAuthenticationLog\Resources\AuthenticationLogResource\Pages;

class AuthenticationLogResource extends Resource
{
    protected static ?string $model = AuthenticationLog::class;

    public static function shouldRegisterNavigation(): bool
    {
        return config('filament-authentication-log.navigation.authentication-log.register', true);
    }

    public static function getNavigationIcon(): string
    {
        return config('filament-authentication-log.navigation.authentication-log.icon');
    }

    public static function getNavigationSort(): ?int
    {
        return config('filament-authentication-log.navigation.authentication-log.sort');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('filament-authentication-log.navigation.authentication-log.group', __('filament-authentication-log::filament-authentication-log.navigation.group'));
    }

    public static function getLabel(): string
    {
        return __('filament-authentication-log::filament-authentication-log.navigation.authentication-log.label');
    }

    public static function getPluralLabel(): string
    {
        return __('filament-authentication-log::filament-authentication-log.navigation.authentication-log.plural-label');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\MorphToSelect::make('authenticable')
                    ->types(self::authenticableResources())
                    ->required(),
                Forms\Components\TextInput::make('Ip Address'),
                Forms\Components\TextInput::make('User Agent'),
                Forms\Components\DateTimePicker::make('Login At'),
                Forms\Components\Toggle::make('Login Successful'),
                Forms\Components\DateTimePicker::make('Logout At'),
                Forms\Components\Toggle::make('Cleared By User'),
                Forms\Components\KeyValue::make('Location'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('authenticatable'))
            ->defaultSort(
                config('filament-authentication-log.sort.column'),
                config('filament-authentication-log.sort.direction'),
            )
            ->columns([
                Tables\Columns\TextColumn::make('authenticatable')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.authenticatable'))
                    ->formatStateUsing(function (?string $state, Model $record) {
                        $authenticatableFieldToDisplay = config('filament-authentication-log.authenticatable.field-to-display');

                        $authenticatableDisplay = $authenticatableFieldToDisplay !== null ? $record->authenticatable->{$authenticatableFieldToDisplay} : class_basename($record->authenticatable::class);

                        if (! $record->authenticatable_id) {
                            return new HtmlString('&mdash;');
                        }

                        $authenticableEditRoute = '#';

                        $routeName = 'filament.'.FilamentAuthenticationLogPlugin::get()->getPanelName().'.resources.'.Str::plural((Str::lower(class_basename($record->authenticatable::class)))).'.edit';

                        if (Route::has($routeName)) {
                            $authenticableEditRoute = route($routeName, ['record' => $record->authenticatable_id]);
                        } else if (config('filament-authentication-log.user-resource')) {
                            $authenticableEditRoute = self::getCustomUserRoute($record);
                        }

                        return new HtmlString('<a href="'.$authenticableEditRoute.'" class="inline-flex items-center justify-center text-sm font-medium hover:underline focus:outline-none focus:underline filament-tables-link text-primary-600 hover:text-primary-500 filament-tables-link-action">'.$authenticatableDisplay.'</a>');
                    })
                    ->sortable(['authenticatable_id']),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.ip_address'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user_agent')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.user_agent'))
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();

                        if (strlen($state) <= $column->getCharacterLimit()) {
                            return null;
                        }

                        return $state;
                    }),
                Tables\Columns\TextColumn::make('login_at')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.login_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\IconColumn::make('login_successful')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.login_successful'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('logout_at')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.logout_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\IconColumn::make('cleared_by_user')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.cleared_by_user'))
                    ->boolean()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('location'),
            ])
            ->actions([
                //
            ])
            ->filters([
                Filter::make('login_successful')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('login_successful', true)),
                Filter::make('login_at')
                    ->form([
                        DatePicker::make('login_from'),
                        DatePicker::make('login_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['login_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('login_at', '>=', $date),
                            )
                            ->when(
                                $data['login_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('login_at', '<=', $date),
                            );
                    }),
                Filter::make('cleared_by_user')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('cleared_by_user', true)),
            ]);
    }

    protected static function getCustomUserRoute($record)
    {
        $authenticableEditRoute = '#';

        $userResource = config('filament-authentication-log.user-resource');

        // Check if the resource exists and has an edit page
        if (method_exists($userResource, 'getUrl') &&
            method_exists($userResource, 'hasPage') &&
            $userResource::hasPage('edit'))
        {
            $authenticableEditRoute = $userResource::getUrl('edit', ['record' => $record->authenticatable_id]);
        }

        return $authenticableEditRoute;
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
            'index' => Pages\ListAuthenticationLogs::route('/'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            //
        ];
    }

    public static function authenticableResources(): array
    {
        return config('filament-authentication-log.authenticable-resources', [
            \App\Models\User::class,
        ]);
    }
}

<?php

namespace App\Providers;

use Filament\Actions\CreateAction;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Column;
use Illuminate\Support\ServiceProvider;
use Filament\Schemas\Components\Component;

class FilamentUIServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // When a field has multiple words like "due_date", the label changes from "Due date" to "Due Date".
        Field::configureUsing(function (Field $field) {
            $field->label(function (Component $component) {
                return str($component->getName())
                    ->afterLast('.')
                    ->kebab()
                    ->replace(['-', '_'], ' ')
                    ->ucwords();
            });

            $field->validationAttribute(function (Component $component) {
                return $component->getLabel();
            });

            return $field;
        });

        // When a table column has multiple words like "due_date", the label changes from "Due date" to "Due Date".
        Column::configureUsing(function (Column $column) {
            $column->label(function (Column $column) {
                return str($column->getName())
                    ->afterLast('.')
                    ->kebab()
                    ->replace(['-', '_'], ' ')
                    ->ucwords();
            });

            return $column;
        });

        // When a text entry has multiple words like "due_date", the label changes from "Due date" to "Due Date".
        Entry::configureUsing(function (Entry $entry) {
            $entry->label(function (Entry $entry) {
                return str($entry->getName())
                    ->afterLast('.')
                    ->kebab()
                    ->replace(['-', '_'], ' ')
                    ->ucwords();
            });

            return $entry;
        });

        \Filament\Forms\Components\Select::configureUsing(function (\Filament\Forms\Components\Select $field) {
            return $field
                ->searchable()
                ->preload();
        });

        // capitalize the model name in a create action label
        \Filament\Actions\CreateAction::configureUsing(function (CreateAction $action) {
            $action
                ->label(fn(): string => __('filament-actions::create.single.label', ['label' => ucwords($action->getModelLabel())]));
        });

        // Set the date display
        \Filament\Schemas\Schema::configureUsing(function (\Filament\Schemas\Schema $schema) {
            return $schema
                ->defaultDateDisplayFormat('m/d/Y')
                ->defaultDateTimeDisplayFormat('h:i A')
                ->defaultTimeDisplayFormat('m/d/Y h:i A');
        });

        // if an action is a modal, do not close by clicking away and default to slideover
        \Filament\Actions\Action::configureUsing(function (\Filament\Actions\Action $action) {
            $action
                ->closeModalByClickingAway(false)
                ->modalWidth(Width::Medium);
        });
    }
}

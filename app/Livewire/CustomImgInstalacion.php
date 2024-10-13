<?php

namespace App\Livewire;

use App\Models\Imagen;
use Livewire\Component;
use Filament\Actions\Action;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Actions\Concerns\InteractsWithActions;

class CustomImgInstalacion extends Component implements HasForms, HasActions
{
    use InteractsWithActions;
    use InteractsWithForms;

    public $linea;
    public $edit;

    public function deleteAction(): Action
    {
        return Action::make('delete')
        ->label('Borrar')
        ->icon('heroicon-m-trash')
        ->size('xs')
        ->action(function (array $arguments) {
            Imagen::findOrFail($arguments['imagenId'])->delete();
            Notification::make()
            ->success()
            ->title('Imagen eliminada')
            ->send();
        });
    }
    public function render()
    {
        return view('livewire.custom-img-instalacion', [
            'instalacion' => $this->linea,
            'edit' => $this->edit,
        ]);
    }
}

<?php

namespace App\Observers;

use App\Models\Domaine;
use App\Models\Organisation;
use App\Models\Sis;

class SisObserver
{
    public function created(Sis $sis): void
    {
        $this->sync($sis);
    }

    public function updated(Sis $sis): void
    {
        $this->sync($sis);
    }

    private function sync(Sis $sis): void
    {
        $domaineId = Domaine::where('code', 'sis')->firstOrFail()->id;
        $organisation = Organisation::firstOrNew(['id' => $sis->id]);
        $organisation->id = $sis->id; // affectation directe — id n'est pas fillable
        $organisation->fill([
            'domaine_id' => $domaineId,
            'nom' => $sis->nom,
            'org_key' => $sis->api_key,
        ]);
        $organisation->save();
    }
}

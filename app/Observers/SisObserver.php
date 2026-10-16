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
            // ?? : un Sis créé sans préciser abreviation/mobile (les deux sont
            // fillable mais pas required) laisse l'attribut PHP à null, alors
            // que les deux colonnes sont NOT NULL avec un défaut côté SQL que
            // seule une colonne omise de l'INSERT recevrait -- on retombe sur
            // les mêmes valeurs par défaut explicitement plutôt que d'y laisser
            // passer null.
            'abreviation' => $sis->abreviation ?? '',
            'mobile' => $sis->mobile ?? false,
        ]);
        $organisation->save();
    }
}

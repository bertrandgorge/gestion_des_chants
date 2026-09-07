<?php

/**
 * @var string $paroisseNom
 * @var array{top:array,parSection:array,nouveaux:array} $paroisse
 * @var array{top:array,parSection:array,nouveaux:array} $globale
 */
use App\SectionTypes;

// Ordre d'affichage des sections : celui d'une feuille de messe, puis alleluia
// (type du répertoire sans section dédiée), puis les sections personnalisées
// restantes par ordre alphabétique.
$ordreTypes = array_merge(SectionTypes::typesDefaut(), ['alleluia']);
$ordonnerParType = static function (array $groupes) use ($ordreTypes): array {
    $ordonnes = [];
    foreach ($ordreTypes as $t) {
        if (isset($groupes[$t])) {
            $ordonnes[$t] = $groupes[$t];
        }
    }
    $autres = array_diff(array_keys($groupes), array_keys($ordonnes));
    sort($autres);
    foreach ($autres as $t) {
        $ordonnes[$t] = $groupes[$t];
    }

    return $ordonnes;
};

$paroisse['parSection'] = $ordonnerParType($paroisse['parSection']);
$paroisse['nouveaux']   = $ordonnerParType($paroisse['nouveaux']);
$globale['parSection']  = $ordonnerParType($globale['parSection']);
$globale['nouveaux']    = $ordonnerParType($globale['nouveaux']);
?>
<h1 class="h4 mb-3">Statistiques</h1>

<div class="row g-4">
    <div class="col-lg-6">
        <?= view('statistiques/_colonne', [
            'nom'   => $paroisseNom !== '' ? $paroisseNom : 'Cette paroisse',
            'stats' => $paroisse,
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('statistiques/_colonne', [
            'nom'   => 'Toutes les paroisses',
            'stats' => $globale,
        ]) ?>
    </div>
</div>

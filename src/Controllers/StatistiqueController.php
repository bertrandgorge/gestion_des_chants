<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Models\Paroisse;
use App\Models\Statistique;

final class StatistiqueController
{
    public function index(): void
    {
        Auth::requireLogin();
        $paroisseId = Auth::paroisseId();
        $paroisse = Paroisse::find((int) $paroisseId);

        render('chantre', 'statistiques/index', [
            'titre'        => 'Statistiques',
            'section'      => 'statistiques',
            'paroisseNom'  => $paroisse['nom'] ?? '',
            'paroisse'     => [
                'top'        => Statistique::topGeneral($paroisseId, 10),
                'parSection' => Statistique::topParSection($paroisseId, 5),
                'nouveaux'   => Statistique::nouveauxParSection($paroisseId, 30),
            ],
            'globale' => [
                'top'        => Statistique::topGeneral(null, 10),
                'parSection' => Statistique::topParSection(null, 5),
                'nouveaux'   => Statistique::nouveauxParSection(null, 30),
            ],
        ]);
    }
}

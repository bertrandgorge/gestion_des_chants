<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Chant;
use App\Models\Clocher;
use App\Models\FeuilleChant;
use App\Models\Paroisse;

final class PublicController
{
    private const FENETRE_MINUTES = 30;

    public function show(array $params): void
    {
        $clocher = Clocher::findBySlugs($params['paroisse'], $params['clocher']);
        if ($clocher === null) {
            http_response_code(404);
            echo view('layout/public', ['content' => view('public/introuvable'), 'theme' => $this->theme(), 'scale' => $this->scale()]);

            return;
        }

        $paroisse = Paroisse::find((int) $clocher['paroisse_id']);
        $feuille = null;

        if (!empty($params['datetime'])) {
            $dt = parse_datetime_slug($params['datetime']);
            if ($dt !== null) {
                $feuille = FeuilleChant::atForClocher((int) $clocher['id'], $dt->format('Y-m-d H:i:s'));
            }
        } else {
            $feuille = FeuilleChant::nearestForClocher(
                (int) $clocher['id'],
                date('Y-m-d H:i:s'),
                self::FENETRE_MINUTES
            );
        }

        if ($feuille !== null) {
            $this->afficherFeuille($feuille, $paroisse);

            return;
        }

        $this->afficherListe($clocher, $paroisse);
    }

    private function afficherFeuille(array $feuille, array $paroisse): void
    {
        $sections = array_filter(
            Chant::forFeuille((int) $feuille['id']),
            static fn ($s) => trim((string) $s['chant']) !== ''
                || trim((string) $s['contenu']) !== ''
                || trim((string) $s['titre']) !== ''
        );

        echo view('layout/public', [
            'content'  => view('public/sheet', [
                'feuille'  => $feuille,
                'paroisse' => $paroisse,
                'sections' => $sections,
            ]),
            'theme'    => $this->theme(),
            'scale'    => $this->scale(),
            'pageTitle' => $feuille['titre_liturgique'] ?: ('Feuille de messe — ' . $feuille['clocher_nom']),
        ]);
    }

    private function afficherListe(array $clocher, array $paroisse): void
    {
        $feuilles = FeuilleChant::upcomingForClocher((int) $clocher['id']);
        echo view('layout/public', [
            'content'  => view('public/list', [
                'clocher'  => $clocher,
                'paroisse' => $paroisse,
                'feuilles' => $feuilles,
            ]),
            'theme'    => $this->theme(),
            'scale'    => $this->scale(),
            'pageTitle' => $clocher['nom'] . ' — ' . $paroisse['nom'],
        ]);
    }

    private function theme(): string
    {
        $t = $_COOKIE['theme'] ?? '';

        return in_array($t, ['clair', 'sombre'], true) ? $t : 'auto';
    }

    private function scale(): float
    {
        $s = (float) ($_COOKIE['text_scale'] ?? 1);

        return max(0.9, min(1.8, $s ?: 1.0));
    }
}

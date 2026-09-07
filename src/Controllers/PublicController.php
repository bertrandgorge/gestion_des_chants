<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Chant;
use App\Models\Clocher;
use App\Models\FeuilleChant;
use App\Models\Paroisse;
use App\SectionTypes;

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
        $feuille = $this->resoudreFeuille($clocher, $params);

        if ($feuille !== null) {
            $this->afficherFeuille($feuille, $paroisse);

            return;
        }

        $this->afficherListe($clocher, $paroisse);
    }

    /**
     * Feuille de chant prête à imprimer (deux colonnes, format A5 recto/verso),
     * même gabarit que côté chantre mais sans choix des sections : on respecte
     * la sélection mémorisée par la paroisse (App\Controllers\ChantController::imprimer).
     */
    public function imprimer(array $params): void
    {
        $clocher = Clocher::findBySlugs($params['paroisse'], $params['clocher']);
        $feuille = $clocher !== null ? $this->resoudreFeuille($clocher, $params) : null;

        if ($feuille === null) {
            http_response_code(404);
            echo view('errors/404');

            return;
        }

        $toutes = Chant::forFeuille((int) $feuille['id']);
        $selection = SectionTypes::selectionImpression(
            Paroisse::impressionSections((int) $clocher['paroisse_id']),
            $toutes
        );

        $sections = array_values(array_filter(
            $toutes,
            static fn ($s) => !empty($selection[(int) $s['id']])
                && (trim((string) $s['chant']) !== ''
                    || trim((string) $s['contenu']) !== ''
                    || trim((string) $s['titre']) !== '')
        ));

        echo view('layout/impression', [
            'content'  => view('impression/feuille', [
                'feuille'  => $feuille,
                'sections' => $sections,
            ]),
            'pageTitle' => 'Feuille de chant — ' . format_date_fr($feuille['date_heure'], false),
        ]);
    }

    private function resoudreFeuille(array $clocher, array $params): ?array
    {
        if (!empty($params['datetime'])) {
            $dt = parse_datetime_slug($params['datetime']);

            return $dt !== null
                ? FeuilleChant::atForClocher((int) $clocher['id'], $dt->format('Y-m-d H:i:s'))
                : null;
        }

        return FeuilleChant::nearestForClocher(
            (int) $clocher['id'],
            date('Y-m-d H:i:s'),
            self::FENETRE_MINUTES
        );
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
            'presentation' => true,
            'sections' => $sections,
            'printUrl' => feuille_public_url($feuille) . '/imprimer',
            'chantLiens' => $this->chantLiens(),
            'pageTitle' => $feuille['clocher_nom'] . ($feuille['semaine'] ? ' — ' . $feuille['semaine'] : ''),
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

    private function chantLiens(): bool
    {
        return ($_COOKIE['chant_liens'] ?? '') === '1';
    }
}

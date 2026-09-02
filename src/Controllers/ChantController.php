<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Models\Chant;
use App\Models\FeuilleChant;
use App\SectionTypes;

final class ChantController
{
    public function editSheet(array $params): void
    {
        Auth::requireLogin();
        $feuille = $this->ownFeuille((int) $params['id']);
        $sections = Chant::forFeuille((int) $feuille['id']);

        render('chantre', 'chant/sheet', [
            'feuille'  => $feuille,
            'sections' => $sections,
            'titre'    => 'Feuille du ' . format_date_fr($feuille['date_heure']),
        ]);
    }

    /** AJAX : add / remove / reorder. */
    public function sections(array $params): void
    {
        Auth::requireLogin();
        $feuille = $this->ownFeuille((int) $params['id']);
        $action = input('action', '');

        if ($action === 'add') {
            $nom = trim((string) input('nom', ''));
            if ($nom === '') {
                json_response(['error' => 'Nom de section requis.'], 422);
            }
            $existants = array_column(Chant::forFeuille((int) $feuille['id']), 'type');
            $type = SectionTypes::slugPersonnalise($nom, $existants);
            $id = Chant::create([
                'feuille_id' => (int) $feuille['id'],
                'nom'        => $nom,
                'type'       => $type,
                'position'   => Chant::nextPosition((int) $feuille['id']),
            ]);
            json_response(['ok' => true, 'id' => $id]);
        }

        if ($action === 'remove') {
            $section = $this->ownSection((int) input('section_id', '0'), (int) $feuille['id']);
            Chant::delete((int) $section['id']);
            json_response(['ok' => true]);
        }

        if ($action === 'reorder') {
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            $valides = array_column(Chant::forFeuille((int) $feuille['id']), 'id');
            Database::transaction(function () use ($ids, $valides) {
                $pos = 0;
                foreach ($ids as $id) {
                    if (in_array($id, array_map('intval', $valides), true)) {
                        Chant::update($id, ['position' => $pos++]);
                    }
                }
            });
            json_response(['ok' => true]);
        }

        json_response(['error' => 'Action inconnue.'], 400);
    }

    public function editSection(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);
        $feuille = FeuilleChant::find((int) $section['feuille_id']);

        render('chantre', 'chant/section_form', [
            'section'      => $section,
            'feuille'      => $feuille,
            'comportement' => SectionTypes::comportement($section['type']),
            'titre'        => $section['nom'],
        ]);
    }

    public function saveSection(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);
        $comportement = SectionTypes::comportement($section['type']);

        $data = [];
        $champsParComportement = [
            'chant'     => ['titre', 'auteur', 'code', 'chant'],
            'ordinaire' => ['titre', 'auteur', 'code', 'chant'],
            'lecture'   => ['titre', 'reference', 'introduction', 'contenu'],
            'psaume'    => ['titre', 'reference', 'chant'],
            'evangile'  => ['acclamation', 'introduction', 'reference', 'contenu'],
            'priere'    => ['contenu'],
        ];
        foreach ($champsParComportement[$comportement] ?? ['titre', 'chant'] as $champ) {
            $data[$champ] = (string) ($_POST[$champ] ?? '');
        }

        Chant::update((int) $section['id'], $data);
        flash('success', 'Section enregistrée.');
        redirect('/app/feuilles/' . $section['feuille_id'] . '#section-' . $section['id']);
    }

    /** AJAX JSON : recherche dans l'historique des chants de la paroisse. */
    public function search(): void
    {
        Auth::requireLogin();
        $q = trim((string) ($_GET['q'] ?? ''));
        $type = isset($_GET['type']) ? (string) $_GET['type'] : null;
        if (mb_strlen($q) < 2) {
            json_response([]);
        }
        json_response(Chant::historique(Auth::paroisseId(), $q, $type));
    }

    /**
     * AJAX JSON : reprise groupée de l'ordinaire.
     *
     * L'utilisateur édite une section d'ordinaire et choisit un chant issu d'une
     * feuille passée : on recopie les autres sections d'ordinaire (non vides) de
     * cette feuille dans les sections d'ordinaire encore vides de la feuille courante.
     */
    public function reprendreOrdinaire(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);
        if (!SectionTypes::estOrdinaire($section['type'])) {
            json_response(['error' => 'section hors ordinaire'], 422);
        }

        $source = FeuilleChant::findForParoisse((int) input('source_feuille_id', '0'), Auth::paroisseId());
        if ($source === null) {
            json_response(['error' => 'feuille source introuvable'], 404);
        }

        $sourceParType = [];
        foreach (Chant::forFeuille((int) $source['id']) as $s) {
            if (SectionTypes::estOrdinaire($s['type']) && trim((string) $s['chant']) !== '') {
                $sourceParType[$s['type']] = $s;
            }
        }

        $reprises = [];
        foreach (Chant::forFeuille((int) $section['feuille_id']) as $cible) {
            if ((int) $cible['id'] === (int) $section['id']) {
                continue; // la section en cours d'édition est gérée par le formulaire
            }
            if (!SectionTypes::estOrdinaire($cible['type']) || trim((string) $cible['chant']) !== '') {
                continue;
            }
            $src = $sourceParType[$cible['type']] ?? null;
            if ($src === null) {
                continue;
            }
            Chant::update((int) $cible['id'], [
                'titre'  => $src['titre'],
                'code'   => $src['code'],
                'auteur' => $src['auteur'],
                'chant'  => $src['chant'],
            ]);
            $reprises[] = $cible['nom'];
        }

        json_response(['ok' => true, 'reprises' => $reprises]);
    }

    private function ownFeuille(int $id): array
    {
        $feuille = FeuilleChant::findForParoisse($id, Auth::paroisseId());
        if ($feuille === null) {
            http_response_code(404);
            exit('Feuille introuvable.');
        }

        return $feuille;
    }

    private function ownSection(int $id, ?int $feuilleId = null): array
    {
        $section = Chant::findForParoisse($id, Auth::paroisseId());
        if ($section === null || ($feuilleId !== null && (int) $section['feuille_id'] !== $feuilleId)) {
            http_response_code(404);
            exit('Section introuvable.');
        }

        return $section;
    }
}

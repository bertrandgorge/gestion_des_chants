<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Models\Chant;
use App\Models\Clocher;
use App\Models\FeuilleChant;
use App\Models\Paroisse;
use App\Models\RepertoireChant;
use App\Models\Statistique;
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
            'clochers' => Clocher::forParoisse(Auth::paroisseId()),
            'impression' => SectionTypes::selectionImpression(
                Paroisse::impressionSections(Auth::paroisseId()),
                $sections
            ),
            'titre'    => 'Feuille du ' . format_date_fr($feuille['date_heure']),
        ]);
    }

    /**
     * Feuille de chant prête à imprimer (deux colonnes, format A5 recto/verso).
     * On mémorise la sélection de sections cochées au niveau de la paroisse
     * pour préremplir l'impression des feuilles suivantes.
     */
    public function imprimer(array $params): void
    {
        Auth::requireLogin();
        $feuille = $this->ownFeuille((int) $params['id']);
        $sections = Chant::forFeuille((int) $feuille['id']);

        $coches = array_map('intval', (array) ($_POST['sections'] ?? []));

        // Préférence paroisse : on met à jour les types présents sur cette feuille.
        $prefs = Paroisse::impressionSections(Auth::paroisseId());
        foreach ($sections as $s) {
            $prefs[(string) $s['type']] = in_array((int) $s['id'], $coches, true);
        }
        Paroisse::enregistrerImpressionSections(Auth::paroisseId(), $prefs);

        // Sections cochées et non vides (même filtre que la feuille des paroissiens).
        $aImprimer = array_values(array_filter(
            $sections,
            static fn ($s) => in_array((int) $s['id'], $coches, true)
                && (trim((string) $s['chant']) !== ''
                    || trim((string) $s['contenu']) !== ''
                    || trim((string) $s['titre']) !== '')
        ));

        echo view('layout/impression', [
            'content'  => view('impression/feuille', [
                'feuille'  => $feuille,
                'sections' => $aImprimer,
            ]),
            'pageTitle' => 'Feuille de chant — ' . format_date_fr($feuille['date_heure'], false),
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
        $comportement = SectionTypes::comportement($section['type']);

        $stats = null;
        $urls = [];
        if (in_array($comportement, ['chant', 'ordinaire'], true) && !empty($section['repertoire_id'])) {
            $repertoireId = (int) $section['repertoire_id'];
            $paroisseId = Auth::paroisseId();
            $chantRepertoire = RepertoireChant::find($repertoireId);
            $type = (string) ($chantRepertoire['type'] ?? $section['type']);
            $stats = [
                'dernieresMesses'    => Statistique::dernieresMesses($repertoireId, $paroisseId),
                'utilisations12Mois' => Statistique::nombreUtilisations($repertoireId, $paroisseId),
                'topSection'         => Statistique::topSection($type, $paroisseId),
                'repertoireId'       => $repertoireId,
                'labelSection'       => SectionTypes::libelle($type),
            ];
            $urls = RepertoireChant::urls($repertoireId);
        }

        render('chantre', 'chant/section_form', [
            'section'      => $section,
            'feuille'      => $feuille,
            'comportement' => $comportement,
            'stats'        => $stats,
            'urls'         => $urls,
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
            'chant'     => ['titre', 'auteur', 'code', 'chant', 'url'],
            'ordinaire' => ['titre', 'auteur', 'code', 'chant', 'url'],
            'lecture'   => ['titre', 'reference', 'introduction', 'contenu'],
            'psaume'    => ['titre', 'reference', 'chant'],
            'evangile'  => ['acclamation', 'introduction', 'reference', 'contenu'],
            'priere'    => ['contenu'],
        ];
        foreach ($champsParComportement[$comportement] ?? ['titre', 'chant'] as $champ) {
            $data[$champ] = (string) ($_POST[$champ] ?? '');
        }
        if (array_key_exists('chant', $data)) {
            $data['nb_couplets'] = count_couplets($data['chant'], false);
        }
        if (in_array($comportement, ['chant', 'ordinaire'], true)) {
            $repertoireId = trim((string) ($_POST['repertoire_id'] ?? ''));
            $data['repertoire_id'] = $repertoireId !== '' ? (int) $repertoireId : null;
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
        $feuilleId = isset($_GET['feuille']) ? (int) $_GET['feuille'] : null;
        $texte = !empty($_GET['texte']);
        if (mb_strlen($q) < 2) {
            json_response([]);
        }
        json_response(Chant::historique(Auth::paroisseId(), $q, $type, $feuilleId, $texte));
    }

    /**
     * AJAX JSON : reprise groupée de l'ordinaire.
     *
     * L'utilisateur édite une section d'ordinaire et choisit, dans le répertoire,
     * un chant qui appartient à un ordinaire de messe (App\Models\RepertoireChant::pourOrdinaire)
     * : on recopie les autres chants de ce même ordinaire dans les sections
     * d'ordinaire encore vides de la feuille courante.
     */
    public function reprendreOrdinaire(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);
        if (!SectionTypes::estOrdinaire($section['type'])) {
            json_response(['error' => 'section hors ordinaire'], 422);
        }

        $repertoireId = (int) input('repertoire_id', '0');
        $source = $repertoireId > 0 ? RepertoireChant::find($repertoireId) : null;
        $ordinaire = trim((string) ($source['ordinaire'] ?? ''));
        if ($source === null || $ordinaire === '') {
            json_response(['error' => "ce chant n'appartient pas à un ordinaire du répertoire"], 404);
        }

        $sourceParType = RepertoireChant::pourOrdinaire($ordinaire);

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
                'titre'         => $src['titre'],
                'code'          => $src['code'],
                'auteur'        => $src['auteur'],
                'chant'         => $src['chant'],
                'repertoire_id' => (int) $src['id'],
            ]);
            $reprises[] = $cible['nom'];
        }

        json_response(['ok' => true, 'reprises' => $reprises]);
    }

    /**
     * Ajoute une section (déjà enregistrée) au répertoire partagé : dédoublonne
     * par titre (App\Models\RepertoireChant::trouverDoublon) plutôt que de
     * créer systématiquement une nouvelle fiche.
     */
    public function ajouterAuRepertoire(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);
        $comportement = SectionTypes::comportement($section['type']);

        if (!in_array($comportement, ['chant', 'ordinaire'], true)
            || trim((string) $section['titre']) === ''
            || trim((string) $section['chant']) === ''
        ) {
            flash('error', "Cette section n'a pas de quoi être ajoutée au répertoire.");
            redirect('/app/sections/' . $section['id']);
        }

        // Une section tapée à la main n'appartient à aucune des sources importées :
        // pas d'exclusion « même source » ici.
        $repertoireId = RepertoireChant::trouverDoublon(
            (string) $section['titre'],
            (string) $section['chant'],
            $section['code'] !== '' ? (string) $section['code'] : null,
            null,
            null
        );
        if ($repertoireId === null) {
            $repertoireId = RepertoireChant::create([
                'titre'       => $section['titre'],
                'code'        => $section['code'] !== '' ? $section['code'] : null,
                'auteur'      => $section['auteur'] !== '' ? $section['auteur'] : null,
                'type'        => $section['type'],
                'nom'         => $section['nom'],
                'chant'       => $section['chant'],
                'nb_couplets' => $section['nb_couplets'] ?? count_couplets((string) $section['chant'], false),
            ]);
        }

        Chant::update((int) $section['id'], ['repertoire_id' => $repertoireId]);
        flash('success', 'Chant ajouté au répertoire.');
        redirect('/app/sections/' . $section['id']);
    }

    /** AJAX : aperçu paroissien d'une section à partir des champs en cours d'édition. */
    public function previewSection(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);

        foreach (['titre', 'auteur', 'code', 'chant', 'reference', 'introduction', 'contenu', 'acclamation'] as $champ) {
            if (array_key_exists($champ, $_POST)) {
                $section[$champ] = (string) $_POST[$champ];
            }
        }

        echo view('public/_section', ['s' => $section]);
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

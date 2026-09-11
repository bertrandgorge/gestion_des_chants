<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Import\UrlImporter;
use App\Models\Chant;
use App\Models\Clocher;
use App\Models\FeuilleChant;
use App\Models\Paroisse;
use App\Models\RepertoireChant;
use App\Models\Statistique;
use App\PropositionsChorale;
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

        // Le psaume s'édite comme un chant (issue #3) : mêmes affordances
        // (recherche, lien répertoire, aide au choix), sa référence en plus.
        $editeurChant = in_array($comportement, ['chant', 'ordinaire', 'psaume'], true);

        $stats = null;
        $urls = [];
        $ficheRepertoire = null;
        if ($editeurChant && !empty($section['repertoire_id'])) {
            $repertoireId = (int) $section['repertoire_id'];
            $paroisseId = Auth::paroisseId();
            $ficheRepertoire = RepertoireChant::find($repertoireId);
            $type = (string) ($ficheRepertoire['type'] ?? $section['type']);
            $stats = [
                'dernieresMesses'    => Statistique::dernieresMesses($repertoireId, $paroisseId),
                'utilisations12Mois' => Statistique::nombreUtilisations($repertoireId, $paroisseId),
                'topSection'         => Statistique::topSection($type, $paroisseId),
                'repertoireId'       => $repertoireId,
                'labelSection'       => SectionTypes::libelle($type),
            ];
            $urls = RepertoireChant::urls($repertoireId);
        }

        // Aide au choix : chants déjà pris dans la paroisse pour cette section
        // sur des messes qui partageaient une des lectures du jour.
        $lectures = in_array($comportement, ['chant', 'psaume'], true)
            ? Chant::pourMemesLectures((int) $feuille['id'], (string) $section['type'], Auth::paroisseId())
            : [];

        render('chantre', 'chant/section_form', [
            'section'         => $section,
            'feuille'         => $feuille,
            'comportement'    => $comportement,
            'stats'           => $stats,
            'urls'            => $urls,
            'ficheRepertoire' => $ficheRepertoire,
            'lectures'        => $lectures,
            'titre'           => $section['nom'],
        ]);
    }

    /**
     * AJAX JSON : chants proposés par choralepolefontainebleau.org pour le
     * dimanche de la feuille, dans la rubrique correspondant à la section.
     */
    public function suggestionsExternes(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);
        if (SectionTypes::comportement((string) $section['type']) !== 'chant') {
            json_response(['ok' => true, 'url' => null, 'chants' => []]);
        }
        $feuille = FeuilleChant::find((int) $section['feuille_id']) ?? [];

        json_response(PropositionsChorale::suggestions($feuille, (string) $section['type']));
    }

    /**
     * AJAX JSON : importe (ou retrouve) dans le répertoire partagé un chant
     * proposé par choralepolefontainebleau.org, et renvoie ses champs pour
     * remplir la section.
     */
    public function importerSuggestion(array $params): void
    {
        Auth::requireLogin();
        $this->ownSection((int) $params['id']);

        $url = trim((string) input('url', ''));
        $host = preg_replace('~^www\.~', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        if ($host !== 'choralepolefontainebleau.org') {
            json_response(['ok' => false, 'error' => 'URL non reconnue.'], 422);
        }

        $resultat = UrlImporter::importer($url);
        $fiche = $resultat['ok'] && $resultat['id'] !== null
            ? RepertoireChant::find((int) $resultat['id'])
            : null;
        if ($fiche === null) {
            json_response(['ok' => false, 'error' => $resultat['message']], 502);
        }

        json_response([
            'ok'            => true,
            'titre'         => $fiche['titre'],
            'code'          => $fiche['code'] ?? '',
            'auteur'        => $fiche['auteur'] ?? '',
            'chant'         => $fiche['chant'] ?? '',
            'url'           => $url,
            'repertoire_id' => (int) $fiche['id'],
            'ordinaire'     => $fiche['ordinaire'] ?? null,
        ]);
    }

    public function saveSection(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);

        Chant::update((int) $section['id'], $this->donneesDepuisPost($section));
        flash('success', 'Section enregistrée.');
        redirect('/app/feuilles/' . $section['feuille_id'] . '#section-' . $section['id']);
    }

    /**
     * Champs d'une section extraits du POST du formulaire d'édition, selon son
     * comportement. Le code (cote Secli…), l'auteur et les URL de partition ne
     * sont plus portés par la section (issue #14) : ils viennent de la fiche du
     * répertoire liée (repertoire_id).
     *
     * @return array<string,mixed>
     */
    private function donneesDepuisPost(array $section): array
    {
        $comportement = SectionTypes::comportement($section['type']);

        $champsParComportement = [
            'chant'     => ['titre', 'chant'],
            'ordinaire' => ['titre', 'chant'],
            'lecture'   => ['titre', 'reference', 'introduction', 'contenu'],
            'psaume'    => ['titre', 'chant', 'reference'],
            'evangile'  => ['acclamation', 'introduction', 'reference', 'contenu'],
            'priere'    => ['contenu'],
        ];

        $data = [];
        foreach ($champsParComportement[$comportement] ?? ['titre', 'chant'] as $champ) {
            $data[$champ] = (string) ($_POST[$champ] ?? '');
        }
        if (array_key_exists('chant', $data)) {
            $data['nb_couplets'] = count_couplets($data['chant'], false);
        }
        if (in_array($comportement, ['chant', 'ordinaire', 'psaume'], true)) {
            $repertoireId = trim((string) ($_POST['repertoire_id'] ?? ''));
            $data['repertoire_id'] = $repertoireId !== '' ? (int) $repertoireId : null;
        }

        return $data;
    }

    /** AJAX JSON : fiche du répertoire liée à une section, pour la recharger dans le formulaire. */
    public function ficheRepertoire(array $params): void
    {
        Auth::requireLogin();
        $this->ownSection((int) $params['id']);

        $fiche = RepertoireChant::find((int) $params['repertoire_id']);
        if ($fiche === null) {
            json_response(['ok' => false], 404);
        }

        $urls = array_values(array_filter(array_map(
            static fn ($u) => (string) ($u['url'] ?? ''),
            RepertoireChant::urls((int) $fiche['id'])
        )));

        json_response([
            'ok'            => true,
            'titre'         => $fiche['titre'],
            'code'          => $fiche['code'] ?? '',
            'auteur'        => $fiche['auteur'] ?? '',
            'chant'         => $fiche['chant'] ?? '',
            'repertoire_id' => (int) $fiche['id'],
            'ordinaire'     => $fiche['ordinaire'] ?? null,
            'url'           => $urls[0] ?? '',
            'urls'          => $urls,
        ]);
    }

    /**
     * Pousse les paroles de la section (telles qu'éditées) dans la fiche du
     * répertoire à laquelle elle est liée. La section est enregistrée au
     * passage pour ne pas perdre les modifications. Confirmation côté client.
     */
    public function mettreAJourRepertoire(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);

        $data = $this->donneesDepuisPost($section);
        Chant::update((int) $section['id'], $data);

        $repertoireId = (int) ($data['repertoire_id'] ?? $section['repertoire_id'] ?? 0);
        $fiche = $repertoireId > 0 ? RepertoireChant::find($repertoireId) : null;
        if ($fiche === null) {
            flash('error', "Cette section n'est pas liée à une fiche du répertoire.");
            redirect('/app/sections/' . $section['id']);
        }

        $chant = (string) ($data['chant'] ?? '');
        RepertoireChant::update($repertoireId, [
            'chant'       => $chant,
            'nb_couplets' => count_couplets($chant, false),
        ]);
        flash('success', 'Paroles mises à jour dans le répertoire.');
        redirect('/app/sections/' . $section['id']);
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
                'chant'         => $src['chant'],
                'repertoire_id' => (int) $src['id'],
            ]);
            $reprises[] = $cible['nom'];
        }

        json_response(['ok' => true, 'reprises' => $reprises]);
    }

    /**
     * Ajoute la section au répertoire partagé : enregistre d'abord le
     * formulaire (le chant vient d'être saisi, pas forcément sauvé), dédoublonne
     * par titre (App\Models\RepertoireChant::trouverDoublon) plutôt que de créer
     * systématiquement une nouvelle fiche, puis lie la section. Reste sur la page.
     */
    public function ajouterAuRepertoire(array $params): void
    {
        Auth::requireLogin();
        $section = $this->ownSection((int) $params['id']);
        $comportement = SectionTypes::comportement($section['type']);

        if (!in_array($comportement, ['chant', 'ordinaire', 'psaume'], true)) {
            flash('error', "Cette section n'a pas de quoi être ajoutée au répertoire.");
            redirect('/app/sections/' . $section['id']);
        }

        $data = $this->donneesDepuisPost($section);
        Chant::update((int) $section['id'], $data);
        $section = array_merge($section, $data);

        if (trim((string) $section['titre']) === '' || trim((string) $section['chant']) === '') {
            flash('error', 'Renseignez au moins le titre et les paroles.');
            redirect('/app/sections/' . $section['id']);
        }

        // Une section tapée à la main n'appartient à aucune des sources importées :
        // pas d'exclusion « même source » ici. Code / auteur ne sont plus saisis
        // sur la section — la fiche est créée avec le seul titre + paroles.
        $repertoireId = RepertoireChant::trouverDoublon(
            (string) $section['titre'],
            (string) $section['chant'],
            null,
            null,
            null
        );
        if ($repertoireId === null) {
            $repertoireId = RepertoireChant::create([
                'titre'       => $section['titre'],
                'code'        => null,
                'auteur'      => null,
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

        foreach (['titre', 'chant', 'reference', 'introduction', 'contenu', 'acclamation'] as $champ) {
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

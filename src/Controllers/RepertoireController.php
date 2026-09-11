<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Import\UrlImporter;
use App\Models\RepertoireChant;
use App\Models\Statistique;
use App\SectionTypes;

final class RepertoireController
{
    public function index(): void
    {
        Auth::requireLogin();
        $q = trim((string) ($_GET['q'] ?? ''));
        $texte = !empty($_GET['texte']);
        $type = trim((string) ($_GET['type'] ?? ''));

        render('chantre', 'repertoire/index', [
            'chants'  => RepertoireChant::all($q, $texte, $type !== '' ? $type : null),
            'tags'    => RepertoireChant::typesAvecComptage($q, $texte),
            'q'       => $q,
            'texte'   => $texte,
            'type'    => $type,
            'titre'   => 'Répertoire',
            'section' => 'repertoire',
        ]);
    }

    public function edit(array $params): void
    {
        Auth::requireLogin();
        $chant = $this->ownChant((int) $params['id']);
        $paroisseId = Auth::paroisseId();

        render('chantre', 'repertoire/form', [
            'chant'         => $chant,
            'urls'          => RepertoireChant::urls((int) $chant['id']),
            'memeTitre'     => RepertoireChant::memeTitre((int) $chant['id'], (string) $chant['titre']),
            'memeOrdinaire' => RepertoireChant::memeOrdinaire((int) $chant['id'], $chant['ordinaire']),
            'types'         => $this->typesDisponibles(),
            'retourQ'       => trim((string) ($_GET['q'] ?? '')),
            'retourTexte'   => !empty($_GET['texte']),
            'retourType'    => trim((string) ($_GET['type'] ?? '')),
            'titre'         => $chant['titre'],
            'section'       => 'repertoire',
            'stats'         => [
                'dernieresMesses'    => Statistique::dernieresMesses((int) $chant['id'], $paroisseId),
                'utilisations12Mois' => Statistique::nombreUtilisations((int) $chant['id'], $paroisseId),
                'topSection'         => Statistique::topSection((string) $chant['type'], $paroisseId),
                'repertoireId'       => (int) $chant['id'],
                'labelSection'       => SectionTypes::libelle((string) $chant['type']),
            ],
        ]);
    }

    /** Fiches du répertoire regroupées par titre (hors ponctuation/espaces) pour repérer les doublons à fusionner. */
    public function doublons(): void
    {
        Auth::requireLogin();

        render('chantre', 'repertoire/doublons', [
            'groupes' => RepertoireChant::doublons(),
            'titre'   => 'Doublons du répertoire',
            'section' => 'repertoire',
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireLogin();
        $chant = $this->ownChant((int) $params['id']);
        $qs = $this->qsRetour();

        $titre = trim((string) input('titre', ''));
        if ($titre === '') {
            flash('error', 'Le titre est obligatoire.');
            redirect('/app/repertoire/' . $chant['id'] . $qs);
        }

        $type = (string) input('type', 'entree');
        // « nom » (classement d'origine, badge dans le répertoire) n'est plus
        // éditable ici : alimenté par l'import et par « Ajouter au répertoire »,
        // on ne le touche pas à l'enregistrement d'une fiche.
        $data = [
            'titre'     => $titre,
            'code'      => $this->videEnNull((string) input('code', '')),
            'auteur'    => $this->videEnNull((string) input('auteur', '')),
            'type'      => array_key_exists($type, $this->typesDisponibles()) ? $type : 'entree',
            'ordinaire' => $this->videEnNull((string) input('ordinaire', '')),
            'chant'     => (string) ($_POST['chant'] ?? ''),
            'mots_cles' => $this->videEnNull((string) input('mots_cles', '')),
        ];
        $data['nb_couplets'] = count_couplets($data['chant'], false);

        RepertoireChant::update((int) $chant['id'], $data);
        flash('success', 'Fiche du répertoire enregistrée.');
        redirect('/app/repertoire/' . $chant['id'] . $qs);
    }

    public function importer(): void
    {
        Auth::requireLogin();
        $url = trim((string) input('url', ''));
        if ($url === '') {
            flash('error', 'URL requise.');
            redirect('/app/repertoire');
        }

        $resultat = UrlImporter::importer($url);
        flash($resultat['ok'] ? 'success' : 'error', $resultat['message']);
        redirect($resultat['ok'] && $resultat['id'] !== null
            ? '/app/repertoire/' . $resultat['id']
            : '/app/repertoire');
    }

    public function supprimer(array $params): void
    {
        Auth::requireLogin();
        $chant = $this->ownChant((int) $params['id']);
        RepertoireChant::delete((int) $chant['id']);
        flash('success', 'Fiche supprimée du répertoire.');
        redirect('/app/repertoire' . $this->qsRetour());
    }

    /**
     * Sépare une source liée à cette fiche (paroles identiques mais musique
     * différente, fusionnée à tort) dans une nouvelle fiche indépendante.
     */
    public function dedoublonner(array $params): void
    {
        Auth::requireLogin();
        $chant = $this->ownChant((int) $params['id']);
        $qs = $this->qsRetour();

        $nouveauId = RepertoireChant::separerImport((string) $params['source'], (string) $params['ref']);
        if ($nouveauId === null) {
            flash('error', "Cette source n'est plus liée à une fiche du répertoire.");
            redirect('/app/repertoire/' . $chant['id'] . $qs);
        }

        flash('success', 'Source séparée dans une nouvelle fiche.');
        redirect('/app/repertoire/' . $nouveauId . $qs);
    }

    public function fusionner(): void
    {
        Auth::requireLogin();
        $ids = array_values(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if (count($ids) !== 2) {
            flash('error', 'Sélectionnez exactement deux fiches à fusionner.');
            redirect('/app/repertoire');
        }

        $a = RepertoireChant::find($ids[0]);
        $b = RepertoireChant::find($ids[1]);
        if ($a === null || $b === null) {
            flash('error', 'Fiche introuvable.');
            redirect('/app/repertoire');
        }

        [$survivant, $perdant] = RepertoireChant::meilleur($a, $b);
        RepertoireChant::mergerDans((int) $survivant['id'], (int) $perdant['id']);

        flash('success', sprintf('Fiches fusionnées dans « %s » (#%d).', $survivant['titre'], $survivant['id']));
        redirect('/app/repertoire/' . $survivant['id']);
    }

    /** @return array<string,string> slug => libellé, types utilisables comme chant. */
    private function typesDisponibles(): array
    {
        $types = [];
        foreach (SectionTypes::DEFAUT as $s) {
            if (in_array(SectionTypes::comportement($s['type']), ['chant', 'ordinaire', 'psaume'], true)) {
                $types[$s['type']] = $s['nom'];
            }
        }

        // Type d'ordinaire hors App\SectionTypes::DEFAUT (aucune feuille n'en crée
        // une section par défaut), mais utilisé par les fiches importées depuis
        // emmanuel.info (bin/import_emmanuel_ordinaires.php).
        $types['alleluia'] = 'Alléluia';

        return $types;
    }

    private function ownChant(int $id): array
    {
        $chant = RepertoireChant::find($id);
        if ($chant === null) {
            http_response_code(404);
            exit('Fiche du répertoire introuvable.');
        }

        return $chant;
    }

    /** Critères de recherche (q, texte, type) reçus en champs cachés du formulaire, pour revenir au répertoire filtré. */
    private function qsRetour(): string
    {
        return query_suffix([
            'q'     => trim((string) ($_POST['retour_q'] ?? '')),
            'texte' => !empty($_POST['retour_texte']) ? '1' : '',
            'type'  => trim((string) ($_POST['retour_type'] ?? '')),
        ]);
    }

    private function videEnNull(string $s): ?string
    {
        $s = trim($s);

        return $s === '' ? null : $s;
    }
}

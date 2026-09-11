<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\FeuilleService;
use App\Models\Clocher;
use App\Models\FeuilleChant;
use DateTimeImmutable;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

final class FeuilleController
{
    public function index(): void
    {
        Auth::requireLogin();
        $feuilles = FeuilleChant::upcomingForParoisse(Auth::paroisseId());
        render('chantre', 'feuilles/index', [
            'feuilles' => $feuilles,
            'titre'    => 'Feuilles de messe',
        ]);
    }

    public function past(): void
    {
        Auth::requireLogin();
        $before = $_GET['before'] ?? null;
        $limit = 25;
        $rows = FeuilleChant::pastForParoisse(Auth::paroisseId(), $before, $limit);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $nextBefore = $hasMore && $rows ? end($rows)['date_heure'] : null;

        echo view('feuilles/anciennes_partial', [
            'feuilles'   => $rows,
            'nextBefore' => $nextBefore,
        ]);
    }

    public function createForm(): void
    {
        Auth::requireLogin();
        $clochers = Clocher::forParoisse(Auth::paroisseId());
        $defaults = [];
        foreach ($clochers as $c) {
            $defaults[$c['id']] = Clocher::prochaineDateParDefaut($c)->format('Y-m-d\TH:i');
        }
        render('chantre', 'feuilles/form', [
            'clochers' => $clochers,
            'defaults' => $defaults,
            'titre'    => 'Nouvelle feuille',
        ]);
    }

    public function create(): void
    {
        Auth::requireLogin();
        $clocherId = $this->resoudreClocher(input('clocher_id'), Auth::paroisseId());
        $dateHeure = $this->parseDate(input('date_heure'));

        if ($clocherId === null || $dateHeure === null) {
            flash('error', 'Lieu ou date invalide.');
            redirect('/app/feuilles/nouveau');
        }

        $id = FeuilleService::creer(Auth::id(), $clocherId, $dateHeure);
        flash('success', 'Feuille créée. Complétez les chants.');
        redirect('/app/feuilles/' . $id);
    }

    /**
     * Id du clocher choisi : un clocher enregistré, ou — pour « - Autres - » — un
     * clocher ad hoc créé/retrouvé à partir du lieu saisi en texte libre (issue #8).
     * Renvoie null si le choix est invalide.
     */
    private function resoudreClocher(?string $choix, int $paroisseId): ?int
    {
        if (trim((string) $choix) === 'autre') {
            $lieu = trim((string) input('lieu', ''));

            return $lieu === '' ? null : Clocher::trouverOuCreerAdHoc($paroisseId, $lieu);
        }

        $clocher = Clocher::findForParoisse((int) $choix, $paroisseId);

        return $clocher !== null ? (int) $clocher['id'] : null;
    }

    public function copy(array $params): void
    {
        Auth::requireLogin();
        $source = FeuilleChant::findForParoisse((int) $params['id'], Auth::paroisseId());
        if ($source === null) {
            http_response_code(404);
            exit('Feuille introuvable.');
        }

        $clocherId = $this->resoudreClocher(input('clocher_id'), Auth::paroisseId());
        $dateHeure = $this->parseDate(input('date_heure'));
        if ($clocherId === null || $dateHeure === null) {
            flash('error', 'Lieu ou date invalide.');
            redirect('/app');
        }

        $id = FeuilleService::copier($source, Auth::id(), $clocherId, $dateHeure);
        flash('success', 'Feuille copiée.');
        redirect('/app/feuilles/' . $id);
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        $feuille = FeuilleChant::findForParoisse((int) $params['id'], Auth::paroisseId());
        if ($feuille === null) {
            http_response_code(404);
            exit('Feuille introuvable.');
        }
        if ((int) $feuille['chantre_id'] !== Auth::id()) {
            flash('error', 'Seul l\'auteur peut supprimer cette feuille.');
            redirect('/app');
        }

        FeuilleChant::delete((int) $feuille['id']);
        Clocher::supprimerAdHocSansFeuille((int) $feuille['clocher_id']);
        flash('success', 'Feuille supprimée.');
        redirect('/app');
    }

    /** QR code (SVG) pointant vers la page publique de la feuille — utile pour un lieu ponctuel. */
    public function qrcode(array $params): void
    {
        Auth::requireLogin();
        $feuille = FeuilleChant::findForParoisse((int) $params['id'], Auth::paroisseId());
        if ($feuille === null) {
            http_response_code(404);
            exit('Feuille introuvable.');
        }

        $result = (new SvgWriter())->write(new QrCode(base_url(feuille_public_url($feuille))));

        header('Content-Type: ' . $result->getMimeType());
        header('Cache-Control: no-store');
        echo $result->getString();
    }

    public function resync(array $params): void
    {
        Auth::requireLogin();
        $feuille = FeuilleChant::findForParoisse((int) $params['id'], Auth::paroisseId());
        if ($feuille === null) {
            http_response_code(404);
            exit('Feuille introuvable.');
        }

        $ok = FeuilleService::resync($feuille);
        flash($ok ? 'success' : 'error', $ok
            ? 'Lectures resynchronisées depuis AELF.'
            : 'AELF indisponible pour le moment, réessayez plus tard.');
        redirect('/app/feuilles/' . $feuille['id']);
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

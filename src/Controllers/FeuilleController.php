<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\FeuilleService;
use App\Models\Clocher;
use App\Models\FeuilleChant;
use DateTimeImmutable;

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
        $clocher = Clocher::findForParoisse((int) input('clocher_id', '0'), Auth::paroisseId());
        $dateHeure = $this->parseDate(input('date_heure'));

        if ($clocher === null || $dateHeure === null) {
            flash('error', 'Clocher ou date invalide.');
            redirect('/app/feuilles/nouveau');
        }

        $id = FeuilleService::creer(Auth::id(), (int) $clocher['id'], $dateHeure);
        flash('success', 'Feuille créée. Complétez les chants.');
        redirect('/app/feuilles/' . $id);
    }

    public function copy(array $params): void
    {
        Auth::requireLogin();
        $source = FeuilleChant::findForParoisse((int) $params['id'], Auth::paroisseId());
        if ($source === null) {
            http_response_code(404);
            exit('Feuille introuvable.');
        }

        $clocher = Clocher::findForParoisse((int) input('clocher_id', '0'), Auth::paroisseId());
        $dateHeure = $this->parseDate(input('date_heure'));
        if ($clocher === null || $dateHeure === null) {
            flash('error', 'Clocher ou date invalide.');
            redirect('/app');
        }

        $id = FeuilleService::copier($source, Auth::id(), (int) $clocher['id'], $dateHeure);
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
        flash('success', 'Feuille supprimée.');
        redirect('/app');
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

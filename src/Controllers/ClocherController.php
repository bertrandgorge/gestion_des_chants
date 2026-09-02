<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Models\Clocher;
use App\Models\Paroisse;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

final class ClocherController
{
    public function index(): void
    {
        Auth::requireAdmin();
        $paroisse = Paroisse::find(Auth::paroisseId());
        render('admin', 'paroisse/clochers', [
            'paroisse' => $paroisse,
            'clochers' => Clocher::forParoisse(Auth::paroisseId()),
            'titre'    => 'Clochers',
            'active'   => 'clochers',
        ]);
    }

    public function edit(array $params): void
    {
        Auth::requireAdmin();
        $clocher = $this->own((int) $params['id']);
        $paroisse = Paroisse::find(Auth::paroisseId());
        render('admin', 'paroisse/clocher_form', [
            'clocher'  => $clocher,
            'paroisse' => $paroisse,
            'titre'    => $clocher['nom'],
            'active'   => 'clochers',
        ]);
    }

    public function store(): void
    {
        Auth::requireAdmin();
        $data = $this->validate($errors);
        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('/admin/clochers');
        }
        Clocher::create($data + ['paroisse_id' => Auth::paroisseId()]);
        flash('success', 'Clocher ajouté.');
        redirect('/admin/clochers');
    }

    public function update(array $params): void
    {
        Auth::requireAdmin();
        $clocher = $this->own((int) $params['id']);
        $data = $this->validate($errors, (int) $clocher['id']);
        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('/admin/clochers/' . $clocher['id']);
        }
        Clocher::update((int) $clocher['id'], $data);
        flash('success', 'Clocher mis à jour.');
        redirect('/admin/clochers');
    }

    public function delete(array $params): void
    {
        Auth::requireAdmin();
        $clocher = $this->own((int) $params['id']);
        if (Clocher::hasFeuilles((int) $clocher['id'])) {
            flash('error', 'Ce clocher a des feuilles de messe : suppression impossible.');
            redirect('/admin/clochers');
        }
        Clocher::delete((int) $clocher['id']);
        flash('success', 'Clocher supprimé.');
        redirect('/admin/clochers');
    }

    public function qrcode(array $params): void
    {
        Auth::requireAdmin();
        $clocher = $this->own((int) $params['id']);
        $paroisse = Paroisse::find(Auth::paroisseId());
        $url = base_url('/' . $paroisse['slug'] . '/' . $clocher['slug']);

        $result = (new SvgWriter())->write(new QrCode($url));

        header('Content-Type: ' . $result->getMimeType());
        header('Cache-Control: no-store');
        echo $result->getString();
    }

    /** @return array<string,mixed> */
    private function validate(?array &$errors, ?int $exceptId = null): array
    {
        $errors = [];
        $nom = (string) input('nom', '');
        $slug = slugify((string) input('slug', '') ?: $nom);
        $jour = input('jour_defaut');
        $heure = input('heure_defaut');

        if ($nom === '') {
            $errors[] = 'Le nom du clocher est obligatoire.';
        }
        if ($slug === '') {
            $errors[] = 'Slug invalide.';
        } elseif (Clocher::slugExists(Auth::paroisseId(), $slug, $exceptId)) {
            $errors[] = 'Ce slug est déjà utilisé dans la paroisse.';
        }

        $jourVal = ($jour !== null && $jour !== '' && (int) $jour >= 1 && (int) $jour <= 7) ? (int) $jour : null;
        $heureVal = ($heure !== null && preg_match('/^\d{1,2}:\d{2}$/', $heure)) ? $heure . ':00' : null;

        return [
            'nom'          => $nom,
            'slug'         => $slug,
            'jour_defaut'  => $jourVal,
            'heure_defaut' => $heureVal,
        ];
    }

    private function own(int $id): array
    {
        $clocher = Clocher::findForParoisse($id, Auth::paroisseId());
        if ($clocher === null) {
            http_response_code(404);
            exit('Clocher introuvable.');
        }

        return $clocher;
    }
}

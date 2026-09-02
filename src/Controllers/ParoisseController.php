<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Models\Paroisse;

final class ParoisseController
{
    private const RESERVES = ['app', 'admin', 'login', 'logout', 'register', 'invitation', 'connexion', 'assets'];

    public function edit(): void
    {
        Auth::requireAdmin();
        $paroisse = Paroisse::find(Auth::paroisseId());
        render('admin', 'paroisse/edit', ['paroisse' => $paroisse, 'titre' => 'Paroisse', 'active' => 'paroisse']);
    }

    public function update(): void
    {
        Auth::requireAdmin();
        $paroisseId = Auth::paroisseId();
        $nom = (string) input('nom', '');
        $slug = slugify((string) input('slug', ''));

        $errors = [];
        if ($nom === '') {
            $errors[] = 'Le nom est obligatoire.';
        }
        if ($slug === '' || in_array($slug, self::RESERVES, true)) {
            $errors[] = 'Ce slug n\'est pas autorisé.';
        }
        if (Paroisse::slugExists($slug, $paroisseId)) {
            $errors[] = 'Ce slug est déjà utilisé.';
        }

        $data = ['nom' => $nom, 'slug' => $slug];

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('/admin/paroisse');
        }

        Paroisse::update($paroisseId, $data);
        flash('success', 'Paroisse mise à jour.');
        redirect('/admin/paroisse');
    }
}

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

        $logo = $_FILES['logo'] ?? null;
        if ($logo && $logo['error'] === UPLOAD_ERR_OK) {
            $path = $this->storeLogo($logo, $errors);
            if ($path !== null) {
                $data['logo'] = $path;
            }
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('/admin/paroisse');
        }

        Paroisse::update($paroisseId, $data);
        flash('success', 'Paroisse mise à jour.');
        redirect('/admin/paroisse');
    }

    private function storeLogo(array $file, array &$errors): ?string
    {
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        $mime = mime_content_type($file['tmp_name']) ?: '';
        if (!isset($allowed[$mime])) {
            $errors[] = 'Logo : format non supporté (PNG, JPG, WEBP ou SVG).';

            return null;
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Logo : 2 Mo maximum.';

            return null;
        }

        $dir = APP_ROOT . '/public/assets/uploads/logos';
        @mkdir($dir, 0775, true);
        $name = 'p' . Auth::paroisseId() . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            $errors[] = 'Logo : échec de l\'enregistrement.';

            return null;
        }

        return 'assets/uploads/logos/' . $name;
    }
}

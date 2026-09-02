<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Mailer;
use App\Models\Paroisse;
use App\Models\Token;
use App\Models\Utilisateur;

final class UtilisateurController
{
    public function index(): void
    {
        Auth::requireAdmin();
        $users = Utilisateur::forParoisse(Auth::paroisseId());
        render('admin', 'paroisse/utilisateurs', [
            'users'  => $users,
            'titre'  => 'Utilisateurs',
            'active' => 'utilisateurs',
        ]);
    }

    public function invite(): void
    {
        Auth::requireAdmin();
        $email = mb_strtolower((string) input('email', ''));
        $type = input('type', 'chantre') === 'admin' ? 'admin' : 'chantre';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Adresse email invalide.');
            redirect('/admin/utilisateurs');
        }
        if (Utilisateur::findByEmail($email) !== null) {
            flash('error', 'Cette adresse est déjà rattachée à un compte.');
            redirect('/admin/utilisateurs');
        }

        $userId = Utilisateur::create($email, null, $type, Auth::paroisseId());
        $this->sendInvitation($userId, $email);

        flash('success', "Invitation envoyée à {$email}.");
        redirect('/admin/utilisateurs');
    }

    public function resend(array $params): void
    {
        Auth::requireAdmin();
        $user = $this->ownUser((int) $params['id']);
        if (!empty($user['pass_hash'])) {
            flash('error', 'Ce compte est déjà actif.');
            redirect('/admin/utilisateurs');
        }
        Token::invalidatePending((int) $user['id'], 'invitation');
        $this->sendInvitation((int) $user['id'], $user['email']);
        flash('success', 'Invitation renvoyée.');
        redirect('/admin/utilisateurs');
    }

    public function updateRole(array $params): void
    {
        Auth::requireAdmin();
        $user = $this->ownUser((int) $params['id']);
        $type = input('type', 'chantre') === 'admin' ? 'admin' : 'chantre';

        if ($type === 'chantre' && $user['type'] === 'admin' && Utilisateur::countAdmins(Auth::paroisseId()) <= 1) {
            flash('error', 'Impossible : il doit rester au moins un administrateur.');
            redirect('/admin/utilisateurs');
        }

        Utilisateur::setType((int) $user['id'], $type);
        flash('success', 'Rôle mis à jour.');
        redirect('/admin/utilisateurs');
    }

    public function delete(array $params): void
    {
        Auth::requireAdmin();
        $user = $this->ownUser((int) $params['id']);

        if ((int) $user['id'] === Auth::id()) {
            flash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            redirect('/admin/utilisateurs');
        }
        if ($user['type'] === 'admin' && !empty($user['pass_hash']) && Utilisateur::countAdmins(Auth::paroisseId()) <= 1) {
            flash('error', 'Impossible de supprimer le dernier administrateur.');
            redirect('/admin/utilisateurs');
        }
        if (Utilisateur::authoredFeuilles((int) $user['id'])) {
            flash('error', 'Cet utilisateur est auteur de feuilles de messe : passez-le en « chantre » plutôt que de le supprimer.');
            redirect('/admin/utilisateurs');
        }

        Utilisateur::delete((int) $user['id']);
        flash('success', 'Utilisateur supprimé.');
        redirect('/admin/utilisateurs');
    }

    private function sendInvitation(int $userId, string $email): void
    {
        $raw = Token::issue($userId, 'invitation', date('Y-m-d H:i:s', time() + 7 * 86400));
        $paroisse = Paroisse::find(Auth::paroisseId());
        Mailer::invitation($email, $paroisse['nom'], base_url('/invitation/' . $raw));
    }

    private function ownUser(int $id): array
    {
        $user = Utilisateur::find($id);
        if ($user === null || (int) $user['paroisse_id'] !== Auth::paroisseId()) {
            http_response_code(404);
            exit('Utilisateur introuvable.');
        }

        return $user;
    }
}

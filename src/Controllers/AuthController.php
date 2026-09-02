<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Mailer;
use App\Models\Paroisse;
use App\Models\Token;
use App\Models\Utilisateur;

final class AuthController
{
    private const RESERVES = ['app', 'admin', 'login', 'logout', 'register', 'invitation', 'mot-de-passe', 'assets'];

    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('/app');
        }
        echo view('layout/auth', ['content' => view('auth/login'), 'titre' => 'Connexion']);
    }

    public function login(): void
    {
        $email = (string) input('email', '');
        $password = (string) input('password', '');

        if (Auth::attempt($email, $password)) {
            clear_old();
            redirect('/app');
        }

        remember_old(['email' => $email]);
        flash('error', 'Identifiants incorrects.');
        redirect('/login');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }

    public function showRegister(): void
    {
        if (Auth::check()) {
            redirect('/app');
        }
        echo view('layout/auth', ['content' => view('auth/register'), 'titre' => 'Créer une paroisse']);
    }

    public function register(): void
    {
        $email = mb_strtolower((string) input('email', ''));
        $password = (string) input('password', '');
        $paroisseNom = (string) input('paroisse', '');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse email invalide.';
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'Le mot de passe doit faire au moins 8 caractères.';
        }
        if ($paroisseNom === '') {
            $errors[] = 'Le nom de la paroisse est obligatoire.';
        }
        if (Utilisateur::findByEmail($email) !== null) {
            $errors[] = 'Un compte existe déjà avec cette adresse.';
        }

        if ($errors !== []) {
            remember_old(['email' => $email, 'paroisse' => $paroisseNom]);
            flash('error', implode(' ', $errors));
            redirect('/register');
        }

        $slug = $this->slugUnique(slugify($paroisseNom));
        $paroisseId = Paroisse::create($paroisseNom, $slug);
        $userId = Utilisateur::create($email, password_hash($password, PASSWORD_DEFAULT), 'admin', $paroisseId);

        clear_old();
        Auth::login($userId);
        flash('success', 'Bienvenue ! Votre paroisse « ' . $paroisseNom . ' » est créée.');
        redirect('/app');
    }

    public function showInvitation(array $params): void
    {
        $token = Token::findValid($params['token'], 'invitation');
        if ($token === null) {
            echo view('layout/auth', ['content' => view('auth/token_invalide'), 'titre' => 'Lien expiré']);

            return;
        }
        echo view('layout/auth', [
            'content' => view('auth/invitation', ['email' => $token['user_email'], 'token' => $params['token']]),
            'titre'   => 'Choisir un mot de passe',
        ]);
    }

    public function acceptInvitation(array $params): void
    {
        $token = Token::findValid($params['token'], 'invitation');
        if ($token === null) {
            flash('error', 'Lien invalide ou expiré.');
            redirect('/login');
        }

        $password = (string) input('password', '');
        if (mb_strlen($password) < 8) {
            flash('error', 'Le mot de passe doit faire au moins 8 caractères.');
            redirect('/invitation/' . $params['token']);
        }

        Utilisateur::setPassword((int) $token['utilisateur_id'], password_hash($password, PASSWORD_DEFAULT));
        Token::consume((int) $token['id']);
        Auth::login((int) $token['utilisateur_id']);
        flash('success', 'Votre compte est activé.');
        redirect('/app');
    }

    public function showForgot(): void
    {
        echo view('layout/auth', ['content' => view('auth/forgot'), 'titre' => 'Mot de passe oublié']);
    }

    public function forgot(): void
    {
        $email = mb_strtolower((string) input('email', ''));
        $user = Utilisateur::findByEmail($email);

        if ($user !== null && !empty($user['pass_hash'])) {
            Token::invalidatePending((int) $user['id'], 'reset');
            $raw = Token::issue((int) $user['id'], 'reset', date('Y-m-d H:i:s', time() + 3600));
            Mailer::reset($email, base_url('/mot-de-passe/reset/' . $raw));
        }

        flash('success', 'Si un compte existe pour cette adresse, un email vient d\'être envoyé.');
        redirect('/login');
    }

    public function showReset(array $params): void
    {
        $token = Token::findValid($params['token'], 'reset');
        if ($token === null) {
            echo view('layout/auth', ['content' => view('auth/token_invalide'), 'titre' => 'Lien expiré']);

            return;
        }
        echo view('layout/auth', [
            'content' => view('auth/reset', ['token' => $params['token']]),
            'titre'   => 'Nouveau mot de passe',
        ]);
    }

    public function reset(array $params): void
    {
        $token = Token::findValid($params['token'], 'reset');
        if ($token === null) {
            flash('error', 'Lien invalide ou expiré.');
            redirect('/login');
        }

        $password = (string) input('password', '');
        if (mb_strlen($password) < 8) {
            flash('error', 'Le mot de passe doit faire au moins 8 caractères.');
            redirect('/mot-de-passe/reset/' . $params['token']);
        }

        Utilisateur::setPassword((int) $token['utilisateur_id'], password_hash($password, PASSWORD_DEFAULT));
        Token::consume((int) $token['id']);
        flash('success', 'Mot de passe modifié, vous pouvez vous connecter.');
        redirect('/login');
    }

    private function slugUnique(string $base): string
    {
        $slug = $base;
        $i = 2;
        while (in_array($slug, self::RESERVES, true) || Paroisse::slugExists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}

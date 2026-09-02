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
    private const RESERVES = ['app', 'admin', 'login', 'logout', 'register', 'invitation', 'connexion', 'assets'];

    /** Durée de validité d'un lien de connexion. */
    private const LIEN_TTL = 1800; // 30 minutes

    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('/app');
        }
        echo view('layout/auth', ['content' => view('auth/login'), 'titre' => 'Connexion']);
    }

    /** Envoie un lien de connexion par email (aucun mot de passe). */
    public function login(): void
    {
        $email = mb_strtolower((string) input('email', ''));
        $user = Utilisateur::findByEmail($email);

        if ($user !== null) {
            Token::invalidatePending((int) $user['id'], 'login');
            $raw = Token::issue((int) $user['id'], 'login', date('Y-m-d H:i:s', time() + self::LIEN_TTL));
            Mailer::lienConnexion($email, base_url('/connexion/' . $raw));
        }

        flash('success', 'Si un compte existe pour cette adresse, un email contenant un lien de connexion vient d\'être envoyé.');
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
        $paroisseNom = (string) input('paroisse', '');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse email invalide.';
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
        $userId = Utilisateur::create($email, 'admin', $paroisseId);

        clear_old();
        Auth::login($userId);
        flash('success', 'Bienvenue ! Votre paroisse « ' . $paroisseNom . ' » est créée.');
        redirect('/app');
    }

    public function connexion(array $params): void
    {
        $this->consumeAndLogin($params['token'], 'login');
    }

    public function invitation(array $params): void
    {
        $this->consumeAndLogin($params['token'], 'invitation');
    }

    /**
     * Consomme le lien reçu par email et connecte directement l'utilisateur
     * (pas de page intermédiaire).
     */
    private function consumeAndLogin(string $rawToken, string $type): void
    {
        $token = Token::findValid($rawToken, $type);
        if ($token === null) {
            echo view('layout/auth', ['content' => view('auth/token_invalide'), 'titre' => 'Lien expiré']);

            return;
        }

        Token::consume((int) $token['id']);
        Auth::login((int) $token['utilisateur_id']);
        redirect('/app');
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

<?php

/**
 * Configuration de l'environnement de développement Docker.
 *
 * Ce fichier est versionné (pas de secret réel). L'entrypoint Docker le copie
 * vers config.php si aucun config.php n'existe.
 */

return [
    'db' => [
        'host'    => 'db',
        'name'    => 'gestion_des_chants',
        'user'    => 'app',
        'pass'    => 'app',
        'charset' => 'utf8mb4',
    ],

    'smtp' => [
        'host'      => 'mailpit',
        'port'      => 1025,
        'user'      => '',
        'pass'      => '',
        'secure'    => '',
        'from'      => 'no-reply@paroisse.test',
        'from_name' => 'Feuilles de messe (dev)',
    ],

    'app' => [
        'base_url' => 'http://localhost:8090',
    ],
];

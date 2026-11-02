<?php
// logout.php — Déconnexion sécurisée et nettoyage de la session (Membre 1)
require_once __DIR__ . '/includes/functions.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

session_start();
message_flash('info', 'Vous avez été déconnecté en toute sécurité.');
header('Location: ' . BASE_URL . '/login.php');
exit;

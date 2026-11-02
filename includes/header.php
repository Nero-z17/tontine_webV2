<?php
// includes/header.php — En-tête HTML avec support natif Mode Clair / Mode Sombre
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$titre_complet = isset($titre_page) ? e($titre_page) . ' — Djangui' : 'Djangui — Gestion de Tontines au Cameroun';
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $titre_complet ?></title>

    <!-- Script ultra-léger qui applique le thème choisi (Clair ou Sombre) avant l'affichage -->
    <script>
        (function() {
            const themeSauvegarde = localStorage.getItem('djangui_theme') || 'light';
            document.documentElement.setAttribute('data-theme', themeSauvegarde);
            document.documentElement.setAttribute('data-bs-theme', themeSauvegarde);
        })();
    </script>

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Police Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Feuille de style personnalisée Djangui (Light & Dark) -->
    <link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
</head>
<body>

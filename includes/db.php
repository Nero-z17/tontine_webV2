<?php
// includes/db.php — Connexion MySQL (InfinityFree en ligne ou WAMP en local)

if (($_SERVER['HTTP_HOST'] ?? 'localhost') === 'localhost' || str_starts_with($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1')) {
    // Configuration locale WAMP
    $host        = '127.0.0.1';
    $dbname      = 'djangui_db';
    $username_db = 'root';
    $password_db = '';
} else {
    // Configuration en ligne InfinityFree (Remplacez par vos vraies valeurs)
    $host        = 'sql304.infinityfree.com'; // Votre MySQL Hostname
    $dbname      = 'if0_43361049_djangui_cm_db';    // Votre MySQL Database Name
    $username_db = 'if0_43361049';            // Votre MySQL Username
    $password_db = 'VUHUsm6GJKD';      // Votre MySQL Password
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username_db,
        $password_db,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données.");
}
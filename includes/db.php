<?php
// includes/db.php — Connexion simple et sécurisée à MySQL sous XAMPP

$host = '127.0.0.1';
$dbname = 'djangui_db';
$username_db = 'root';
$password_db = ''; // Vide par défaut sur XAMPP

try {
    $pdo = new PDO("mysql:host=$host;
                    dbname=$dbname;
                    charset=utf8mb4",
                    $username_db,
                    $password_db
                );
    
    // Affiche clairement les erreurs SQL si on se trompe dans une requête
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Récupère les données sous forme de tableau facile à lire : $ligne['nom_colonne']
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion à la db :" . $e->getMessage());
}

<?php

$titre_page = 'Demande en attente';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/navbar.php';
?>

<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card card-djangui text-center p-4 p-md-5">
                <div class="text-primary mb-3">
                    <i class="bi bi-hourglass-split display-3"></i>
                </div>
                <h1 class="h3 fw-bold">Demande en attente d’approbation</h1>
                <p class="text-muted mb-4">
                    Votre demande pour rejoindre la tontine a bien été enregistrée.
                    Veuillez attendre que son administrateur approuve votre adhésion.
                    Vous pourrez ensuite vous connecter pour accéder à votre tableau de bord membre.
                </p>

                <a href="<?= BASE_URL ?>/login.php" class="btn btn-glow-blue">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Aller à la connexion
                </a>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>

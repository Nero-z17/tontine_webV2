<?php
// index.php — Page d'accueil publique tout-en-un (Présentation + Fonctionnement + Tarif 2 000 FCFA)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$titre_page = "Accueil — Tontines digitales au Cameroun";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<!-- HERO BANNER AVEC VIDÉO EN BOUCLE EN ARRIÈRE-PLAN -->
<section class="hero-video-section mb-4">
    <!-- Vidéo muette en lecture automatique et en boucle infinie -->
    <video class="hero-video-bg" autoplay muted loop playsinline>
        <source src="<?= BASE_URL ?>/assets/videos/hero.mp4" type="video/mp4">
    </video>

    <!-- Voile sombre élégant pour faire ressortir le texte et la carte -->
    <div class="hero-video-overlay"></div>

    <!-- Contenu centré au premier plan (sans le cadre d'exemple à droite) -->
    <div class="container hero-video-content py-4">
        <div class="row justify-content-center text-center">
            <div class="col-lg-9">
                <span class="badge bg-success px-3 py-2 rounded-pill fw-semibold mb-3 shadow-sm">
                    <i class="bi bi-shield-check me-1"></i>FINTECH COMMUNAUTAIRE — CAMEROUN (FCFA)
                </span>
                <h1 class="display-5 fw-bold text-white mb-3">
                    Gérez votre tontine en toute transparence, sans cahier perdu ni calcul manuel.
                </h1>
                <p class="lead text-white-50 mx-auto mb-4" style="max-width: 720px;">
                    Tirage au sort aléatoire verrouillé, suivi en direct du pot du bouffeur de la séance, gestion automatique des retards sur pot et caisse de réserve par cycle.
                </p>
                <div class="d-flex flex-wrap justify-content-center gap-3">
                    <a href="<?= BASE_URL ?>/register-tontine.php" class="btn btn-glow-emerald btn-lg px-4">
                        <i class="bi bi-plus-circle-fill me-2"></i>Créer ma tontine (2 mois gratuits)
                    </a>
                    <a href="<?= BASE_URL ?>/join-tontine.php" class="btn btn-outline-light btn-lg px-4 rounded-3">
                        <i class="bi bi-people-fill me-2"></i>Rejoindre avec un code
                    </a>
                    <a href="<?= BASE_URL ?>/login.php" class="btn btn-glow-blue btn-lg px-4">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- COMMENT ÇA MARCHE EN 4 ÉTAPES GUIDÉES -->
<section class="container py-4">
    <div class="text-center mb-4">
        <h2 class="fw-bold h3">Une interface guidée en 4 écrans simples</h2>
        <p class="text-muted small">Tout est conçu pour être pris en main immédiatement par la secrétaire et tous les membres.</p>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-6 col-lg-3">
            <div class="card card-djangui hover-lift p-4 h-100 border-left-navy">
                <div class="fs-2 text-primary mb-2"><i class="bi bi-1-circle-fill"></i></div>
                <h5 class="fw-bold">Accueil & Séance</h5>
                <p class="text-muted small mb-0">Visualisez immédiatement qui est le bouffeur de la séance actuelle, la progression de son pot et vos réunions Zoom.</p>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card card-djangui hover-lift p-4 h-100 border-left-emerald">
                <div class="fs-2 text-success mb-2"><i class="bi bi-2-circle-fill"></i></div>
                <h5 class="fw-bold">Cycles & Tirage</h5>
                <p class="text-muted small mb-0">Tirage au sort aléatoire en 1 clic et verrouillage des séances tant que la séance précédente n'est pas clôturée.</p>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card card-djangui hover-lift p-4 h-100 border-left-warning">
                <div class="fs-2 text-warning mb-2"><i class="bi bi-3-circle-fill"></i></div>
                <h5 class="fw-bold">Cotisations & Caisse</h5>
                <p class="text-muted small mb-0">Choisissez le montant exact à verser, suivez vos reçus ventilés et consultez la caisse de réserve alimentée par les pénalités (%).</p>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card card-djangui hover-lift p-4 h-100 border-left-navy">
                <div class="fs-2 text-info mb-2"><i class="bi bi-4-circle-fill"></i></div>
                <h5 class="fw-bold">Groupe & Profil</h5>
                <p class="text-muted small mb-0">Validation des adhésions avec CNI, suivi des membres actifs/inactifs et passation d'administration en 1 clic.</p>
            </div>
        </div>
    </div>

    <!-- TARIF UNIQUE TRANSPARENT -->
    <div class="card card-accent-djangui p-4 p-md-5 mb-4">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <span class="badge bg-success mb-2">TARIF UNIQUE PAR GROUPE</span>
                <h3 class="fw-bold mb-2">2 mois d'essai gratuit, puis 2 000 FCFA / mois par tontine</h3>
                <p class="text-muted mb-0">
                    L'accès est <strong>100 % gratuit pour tous les membres cotisants</strong>. Seule la tontine règle 2 000 FCFA par mois après les 2 premiers mois d'essai offerts, quel que soit le nombre de participants.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="<?= BASE_URL ?>/register-tontine.php" class="btn btn-glow-emerald btn-lg px-4">
                    Démarrer gratuitement
                </a>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

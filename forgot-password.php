<?php
// forgot-password.php — Récupération sécurisée du mot de passe (Membre 1)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$erreur = '';
$code_simule = '';
$etape = 'demande';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();

    if (isset($_POST['demander_code'])) {
        $identifiant = trim($_POST['identifiant'] ?? '');
        $stmt = $pdo->prepare("SELECT id, nom_complet FROM users WHERE username = ? OR telephone = ? OR email = ? LIMIT 1");
        $stmt->execute([$identifiant, $identifiant, $identifiant]);
        $user = $stmt->fetch();

        if (!$user) {
            $erreur = "Aucun compte actif ne correspond à cet identifiant.";
        } else {
            $token = (string)random_int(100000, 999999);
            $expire = date('Y-m-d H:i:s', temps_actuel() + 900); // Valable 15 minutes

            $upd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expire_at = ? WHERE id = ?");
            $upd->execute([$token, $expire, $user['id']]);

            $code_simule = $token;
            $etape = 'validation';
        }
    }

    if (isset($_POST['valider_mdp'])) {
        $token_saisi = trim($_POST['reset_token'] ?? '');
        $nouveau_mdp = $_POST['nouveau_mdp'] ?? '';

        if (strlen($nouveau_mdp) < 6) {
            $erreur = "Le nouveau mot de passe doit contenir au moins 6 caractères.";
            $etape = 'validation';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expire_at > NOW() LIMIT 1");
            $stmt->execute([$token_saisi]);
            $user = $stmt->fetch();

            if (!$user) {
                $erreur = "Code de vérification invalide ou expiré (validité : 15 min).";
                $etape = 'validation';
            } else {
                $hash = password_hash($nouveau_mdp, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("
                    UPDATE users
                    SET password_hash = ?, reset_token = NULL, reset_expire_at = NULL,
                        tentatives_echouees = 0, blocage_jusqua = NULL
                    WHERE id = ?
                ");
                $upd->execute([$hash, $user['id']]);

                message_flash('success', 'Votre mot de passe a été mis à jour avec succès. Vous pouvez vous connecter.');
                header('Location: ' . BASE_URL . '/login.php');
                exit;
            }
        }
    }
}

$titre_page = "Mot de passe oublié";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container text-center">
        <h1 class="fw-bold h2 mb-1">Récupération du mot de passe</h1>
        <p class="text-muted small mb-0">Réinitialisez votre accès en toute autonomie</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card card-djangui hover-lift p-4 p-md-5 border-left-warning">
                <?php if ($erreur !== ''): ?>
                    <div class="alert alert-danger small"><?= e($erreur) ?></div>
                <?php endif; ?>

                <?php if ($code_simule !== ''): ?>
                    <div class="alert alert-success small">
                        <strong><i class="bi bi-chat-dots-fill me-1"></i>Mode Démo XAMPP (Simulation SMS/Email) :</strong><br>
                        Votre code de sécurité valable 15 minutes est : <code class="fs-5 fw-bold"><?= e($code_simule) ?></code>
                    </div>
                <?php endif; ?>

                <?php if ($etape === 'demande'): ?>
                    <form method="POST" action="forgot-password.php">
                        <?= champ_csrf() ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Nom d'utilisateur, Téléphone ou Email</label>
                            <input type="text" name="identifiant" class="form-control"
                                   placeholder="Ex: ngono_secretaire ou +237699223344" required>
                        </div>
                        <button type="submit" name="demander_code" class="btn btn-glow-blue w-100 py-2">
                            <i class="bi bi-send-fill me-2"></i>Générer un code de récupération
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="forgot-password.php">
                        <?= champ_csrf() ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Code à 6 chiffres reçu</label>
                            <input type="text" name="reset_token" class="form-control fw-bold"
                                   value="<?= e($code_simule) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Nouveau mot de passe (min. 6 caractères)</label>
                            <input type="password" name="nouveau_mdp" class="form-control" required>
                        </div>
                        <button type="submit" name="valider_mdp" class="btn btn-glow-emerald w-100 py-2">
                            <i class="bi bi-check-circle-fill me-2"></i>Enregistrer le nouveau mot de passe
                        </button>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-4 pt-2 border-top small">
                    <a href="<?= BASE_URL ?>/login.php" class="text-decoration-none fw-semibold text-primary">
                        <i class="bi bi-arrow-left me-1"></i>Retour à la page de connexion
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

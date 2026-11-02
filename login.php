<?php
// login.php — Connexion sécurisée par nom d'utilisateur ou téléphone (Membre 1)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Si l'utilisateur est déjà connecté, redirection immédiate selon son rôle
if (!empty($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: ' . BASE_URL . '/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/dashboard.php');
    }
    exit;
}

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();

    $identifiant  = trim($_POST['identifiant'] ?? '');
    $mot_de_passe = $_POST['mot_de_passe'] ?? '';

    if ($identifiant === '' || $mot_de_passe === '') {
        $erreur = "Veuillez renseigner votre identifiant et votre mot de passe.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR telephone = ? LIMIT 1");
        $stmt->execute([$identifiant, $identifiant]);
        $user = $stmt->fetch();

        if (!$user) {
            $erreur = "Identifiants incorrects. Vérifiez votre nom d'utilisateur ou numéro de téléphone.";
        } elseif ($user['statut_membre'] !== 'actif') {
            $erreur = "Ce compte membre est actuellement inactif ou retiré de la tontine. Contactez votre secrétaire.";
        } elseif (!empty($user['blocage_jusqua']) && strtotime($user['blocage_jusqua']) > time()) {
            $minutes = (int)ceil((strtotime($user['blocage_jusqua']) - time()) / 60);
            $erreur = "Compte temporairement bloqué par sécurité après 5 échecs. Réessayez dans {$minutes} minute(s).";
        } elseif (!password_verify($mot_de_passe, $user['password_hash'])) {
            // Incrémentation du compteur d'échecs
            $echecs = (int)$user['tentatives_echouees'] + 1;
            if ($echecs >= 5) {
                $blocage = date('Y-m-d H:i:s', time() + 15 * 60);
                $upd = $pdo->prepare("UPDATE users SET tentatives_echouees = ?, blocage_jusqua = ? WHERE id = ?");
                $upd->execute([$echecs, $blocage, $user['id']]);
                $erreur = "5 tentatives échouées. Par mesure de sécurité, votre compte est bloqué pendant 15 minutes.";
            } else {
                $upd = $pdo->prepare("UPDATE users SET tentatives_echouees = ? WHERE id = ?");
                $upd->execute([$echecs, $user['id']]);
                $restant = 5 - $echecs;
                $erreur = "Mot de passe incorrect. Il vous reste {$restant} tentative(s) avant blocage temporaire.";
            }
        } else {
            // Connexion valide : remise à zéro des tentatives échouées
            $upd = $pdo->prepare("UPDATE users SET tentatives_echouees = 0, blocage_jusqua = NULL WHERE id = ?");
            $upd->execute([$user['id']]);

            if (($user['statut_validation'] ?? 'accepte') === 'refuse') {
                $erreur = "Votre demande d'adhésion à cette tontine a été refusée par l'administrateur.";
            } elseif (($user['statut_validation'] ?? 'accepte') !== 'accepte') {
                message_flash('info', 'Votre demande d’adhésion est toujours en attente de validation par l’administrateur.');
                header('Location: ' . BASE_URL . '/includes/waiting.php');
                exit;
            } else {

            session_regenerate_id(true);

            $_SESSION['user_id']     = (int)$user['id'];
            $_SESSION['code_public'] = $user['code_public'];
            $_SESSION['username']    = $user['username'];
            $_SESSION['nom_complet'] = $user['nom_complet'];
            $_SESSION['role']        = $user['role'];
            $_SESSION['tontine_id']  = (int)$user['tontine_id'];

            message_flash('success', 'Heureux de vous revoir, ' . $user['nom_complet'] . ' !');
            if ($user['role'] === 'admin') {
                header('Location: ' . BASE_URL . '/dashboard.php');
            } else {
                header('Location: ' . BASE_URL . '/dashboard.php');
            }
            exit;}
        }
    }
}

$titre_page = "Connexion";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container text-center">
        <span class="badge bg-primary bg-opacity-25 text-primary px-3 py-2 rounded-pill fw-semibold mb-2">
            ESPACE SÉCURISÉ DJANGUI
        </span>
        <h1 class="fw-bold h2 mb-1">Accéder à ma tontine</h1>
        <p class="text-muted small mb-0">Connectez-vous pour cotiser, suivre votre rang ou administrer votre groupe</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row justify-content-center g-4">
        <div class="col-md-8 col-lg-5">
            <div class="card card-djangui hover-lift p-4 p-md-5 border-left-navy">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="rounded-3 bg-primary bg-opacity-10 p-3 text-primary">
                        <i class="bi bi-shield-lock-fill fs-3"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0">Identification</h4>
                        <span class="text-muted small">Membre cotisant ou Secrétaire</span>
                    </div>
                </div>

                <?php if ($erreur !== ''): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 small" role="alert">
                        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                        <div><?= e($erreur) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" novalidate>
                    <?= champ_csrf() ?>

                    <div class="mb-3">
                        <label for="identifiant" class="form-label fw-semibold small">Nom d'utilisateur ou Téléphone</label>
                        <div class="input-group">
                            <span class="input-group-text box-adaptive"><i class="bi bi-person-badge text-primary"></i></span>
                            <input type="text" id="identifiant" name="identifiant" class="form-control"
                                   placeholder="Ex: ngono_secretaire ou +237699223344"
                                   value="<?= e($_POST['identifiant'] ?? '') ?>" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="mot_de_passe" class="form-label fw-semibold small mb-0">Mot de passe</label>
                            <a href="<?= BASE_URL ?>/forgot-password.php" class="small text-primary text-decoration-none fw-semibold">
                                Mot de passe oublié ?
                            </a>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text box-adaptive"><i class="bi bi-key-fill text-primary"></i></span>
                            <input type="password" id="mot_de_passe" name="mot_de_passe" class="form-control"
                                   placeholder="Votre mot de passe" required>
                            <button class="btn btn-outline-secondary" type="button" id="togglePassword" title="Afficher/Masquer">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-glow-blue w-100 py-2.5 mb-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter à mon espace
                    </button>
                </form>

                <div class="text-center border-top pt-3 mt-2 small">
                    <div class="mb-2">
                        Vous avez un code d'invitation ?
                        <a href="<?= BASE_URL ?>/join-tontine.php" class="fw-bold text-primary text-decoration-none">Rejoindre ma tontine</a>
                    </div>
                    <div>
                        Vous êtes responsable d'un groupe ?
                        <a href="<?= BASE_URL ?>/register-tontine.php" class="fw-bold text-success text-decoration-none">Créer une tontine</a>
                    </div>
                </div>
            </div>

            <!-- Accès rapide de démonstration pour les tests d'équipe et la soutenance -->
            <div class="card card-djangui p-3 mt-3">
                <div class="small fw-bold text-primary mb-2">
                    <i class="bi bi-lightning-charge-fill me-1"></i>Remplissage rapide (Démo Jury — Mot de passe : <code>password</code>)
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary js-demo-login" data-user="ngono_secretaire">
                        Secrétaire / Admin
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success js-demo-login" data-user="fotso_eric">
                        Membre à jour (Éric)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-warning js-demo-login" data-user="mbarga_paul">
                        Membre en retard (Paul)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('togglePassword');
    const pwdInput = document.getElementById('mot_de_passe');
    const idInput = document.getElementById('identifiant');

    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', function () {
            const isPwd = pwdInput.type === 'password';
            pwdInput.type = isPwd ? 'text' : 'password';
            toggleBtn.innerHTML = isPwd ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        });
    }

    document.querySelectorAll('.js-demo-login').forEach(function (btn) {
        btn.addEventListener('click', function () {
            idInput.value = this.getAttribute('data-user');
            pwdInput.value = 'password';
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

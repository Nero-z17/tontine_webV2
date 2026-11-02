<?php
// join-tontine.php — Rattachement d'un nouveau membre via un code d'invitation valide (Membre 1)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$erreur = '';
$code_saisi = trim($_GET['code'] ?? ($_POST['code_invitation'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();

    $code_invitation    = strtoupper(trim($_POST['code_invitation'] ?? ''));
    $nom_complet        = trim($_POST['nom_complet'] ?? '');
    $username           = strtolower(trim($_POST['username'] ?? ''));
    $telephone          = trim($_POST['telephone'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $canal_paiement     = $_POST['canal_paiement'] ?? 'momo';
    $telephone_paiement = trim($_POST['telephone_paiement'] ?? $telephone);
    $mot_de_passe       = $_POST['mot_de_passe'] ?? '';
    $numero_cni = trim($_POST['numero_cni'] ?? '');

    if ($code_invitation === '' || $nom_complet === '' || $username === '' || $telephone === '' || $mot_de_passe === '') {
        $erreur = "Veuillez remplir tous les champs obligatoires (*).";
    } elseif ($numero_cni === '') {
        $erreur = "Veuillez renseigner votre numéro de CNI.";
    } elseif (!preg_match('/^[a-z0-9_]{4,30}$/', $username)) {
        $erreur = "Le nom d'utilisateur doit contenir entre 4 et 30 caractères alphanumériques ou _.";
    } elseif (strlen($mot_de_passe) < 6) {
        $erreur = "Le mot de passe doit contenir au moins 6 caractères.";
    } elseif (!in_array($canal_paiement, ['momo', 'om', 'especes'], true)) {
        $erreur = "Moyen de paiement invalide.";
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = "Veuillez renseigner une adresse email valide.";
    } else {
        // 1. Vérifier que le code d'invitation correspond à une tontine active
        $stmtTontine = $pdo->prepare("SELECT * FROM tontines WHERE code_invitation = ? LIMIT 1");
        $stmtTontine->execute([$code_invitation]);
        $tontine = $stmtTontine->fetch();

        if (!$tontine) {
            $erreur = "Code d'invitation introuvable. Demandez le code exact à votre secrétaire (ex: INV-AKWA-2026).";
        } elseif ($tontine['statut'] !== 'active') {
            $erreur = "Cette tontine n'accepte plus de nouvelles adhésions (statut : " . $tontine['statut'] . ").";
        } else {
            // 2. Vérifier que la personne ne possède pas déjà un compte dans une tontine (1 compte = 1 seule tontine)
            $stmtUser = $pdo->prepare("SELECT id FROM users WHERE username = ? OR telephone = ? OR (email IS NOT NULL AND email != '' AND email = ?) LIMIT 1");
            $stmtUser->execute([$username, $telephone, $email]);

            if ($stmtUser->fetch()) {
                $erreur = "Vous possédez déjà un compte sur Djangui (un membre ne peut appartenir qu'à une seule tontine). Connectez-vous directement.";
            } else {
                try {
                    $code_public   = 'DJG-' . date('Y') . '-M' . strtoupper(bin2hex(random_bytes(2)));
                    $password_hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);

                    $stmtInsert = $pdo->prepare("
                        INSERT INTO users (
                            code_public, username, password_hash, nom_complet, telephone, email,
                            role, statut_membre, statut_validation, canal_paiement,
                            telephone_paiement, tontine_id, numero_cni
                        ) VALUES (?, ?, ?, ?, ?, ?, 'member', 'actif', 'en_attente', ?, ?, ?, ?)
                    ");
                    $stmtInsert->execute([
                        $code_public,
                        $username,
                        $password_hash,
                        $nom_complet,
                        $telephone,
                        $email !== '' ? $email : null,
                        $canal_paiement,
                        $telephone_paiement !== '' ? $telephone_paiement : $telephone,
                        (int)$tontine['id'],
                        $numero_cni
                    ]);

                    message_flash('success', 'Votre demande pour rejoindre la tontine « ' . $tontine['nom'] . ' » a été envoyée. Vous pourrez accéder à votre espace après validation par l’administrateur.');
                    header('Location: ' . BASE_URL . '/includes/waiting.php');
                    exit;
                } catch (PDOException $e) {
                    $erreur = "Impossible d'enregistrer votre demande. Vérifiez que vos coordonnées ne sont pas déjà utilisées.";
                }
            }
        }
    }
}

$titre_page = "Rejoindre une tontine";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container text-center">
        <span class="badge bg-info text-dark px-3 py-2 rounded-pill fw-bold mb-2">
            ADHÉSION PAR INVITATION
        </span>
        <h1 class="fw-bold h2 mb-1">Rejoindre ma tontine</h1>
        <p class="text-muted small mb-0">
            Munissez-vous du code d'invitation remis par votre secrétaire (Code de démo : <code>INV-AKWA-2026</code>)
        </p>
    </div>
</div>

<div class="container pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card card-djangui hover-lift p-4 p-md-5 border-left-navy">
                <?php if ($erreur !== ''): ?>
                    <div class="alert alert-danger small"><?= e($erreur) ?></div>
                <?php endif; ?>

                <form method="POST" action="join-tontine.php">
                    <?= champ_csrf() ?>

                    <div class="p-3 rounded-3 box-adaptive mb-4">
                        <label class="form-label fw-bold text-primary small mb-1">
                            <i class="bi bi-key-fill me-1"></i>Code d'invitation de la tontine *
                        </label>
                        <input type="text" name="code_invitation" class="form-control form-control-lg fw-bold text-uppercase"
                               placeholder="Ex: INV-AKWA-2026"
                               value="<?= e($code_saisi) ?>" required>
                        <div class="form-text small">
                            Règle Djangui : un compte utilisateur ne peut rejoindre qu'une seule tontine.
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Nom complet *</label>
                            <input type="text" name="nom_complet" class="form-control"
                                   placeholder="Ex: Samuel Eto'o"
                                   value="<?= e($_POST['nom_complet'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Nom d'utilisateur souhaité *</label>
                            <input type="text" name="username" class="form-control"
                                   placeholder="Ex: samuel_237"
                                   value="<?= e($_POST['username'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Numéro de téléphone *</label>
                            <input type="text" name="telephone" class="form-control"
                                   placeholder="Ex: +237670001122"
                                   value="<?= e($_POST['telephone'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Email (optionnel)</label>
                            <input type="email" name="email" class="form-control"
                                   placeholder="Optionnel"
                                   value="<?= e($_POST['email'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Moyen de paiement principal *</label>
                            <select name="canal_paiement" class="form-select">
                                <option value="momo">MTN Mobile Money (MoMo)</option>
                                <option value="om">Orange Money (OM)</option>
                                <option value="especes">Espèces</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Numéro MoMo/OM (pour recevoir votre pot)</label>
                            <input type="text" name="telephone_paiement" class="form-control"
                                   placeholder="Identique au téléphone si vide"
                                   value="<?= e($_POST['telephone_paiement'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Numéro de CNI (confidentiel, visible uniquement par l'admin) *</label>
                            <input type="text" name="numero_cni" class="form-control"
                                placeholder="Ex: CNI-LT-102938475"
                                value="<?= e($_POST['numero_cni'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Choisissez votre mot de passe (min. 6 caractères) *</label>
                            <input type="password" name="mot_de_passe" class="form-control" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-glow-blue btn-lg w-100 mt-4">
                        <i class="bi bi-person-plus-fill me-2"></i>Rejoindre la tontine maintenant
                    </button>
                </form>

                <div class="text-center mt-3 small text-muted">
                    Votre adhésion a déjà été validée par la secrétaire ?
                    <a href="<?= BASE_URL ?>/login.php" class="fw-bold text-primary text-decoration-none">Connectez-vous ici</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

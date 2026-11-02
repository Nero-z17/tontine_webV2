<?php
// register-tontine.php — Création atomique d'une tontine et du compte Secrétaire/Admin (Membre 1)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();

    // 1. Infos de la Secrétaire / Administrateur
    $nom_complet        = trim($_POST['nom_complet'] ?? '');
    $username           = strtolower(trim($_POST['username'] ?? ''));
    $telephone          = trim($_POST['telephone'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $numero_cni         = trim($_POST['numero_cni'] ?? '');
    $canal_paiement     = $_POST['canal_paiement'] ?? 'momo';
    $telephone_paiement = trim($_POST['telephone_paiement'] ?? $telephone);
    $mot_de_passe       = $_POST['mot_de_passe'] ?? '';
    $confirmation_mdp   = $_POST['confirmation_mdp'] ?? '';

    // 2. Infos de la Tontine
    $nom_tontine  = trim($_POST['nom_tontine'] ?? '');
    $ville        = trim($_POST['ville'] ?? 'Douala');
    $description  = trim($_POST['description'] ?? '');

    if ($nom_complet === '' || $username === '' || $telephone === '' || $nom_tontine === '' || $mot_de_passe === '') {
        $erreur = "Veuillez remplir tous les champs obligatoires (*).";
    } elseif (!preg_match('/^[a-z0-9_]{4,30}$/', $username)) {
        $erreur = "Le nom d'utilisateur doit contenir entre 4 et 30 caractères (lettres sans espace, chiffres ou _).";
    } elseif (strlen($mot_de_passe) < 6) {
        $erreur = "Le mot de passe doit contenir au moins 6 caractères.";
    } elseif ($mot_de_passe !== $confirmation_mdp) {
        $erreur = "Les deux mots de passe ne correspondent pas.";
    } elseif (!in_array($canal_paiement, ['momo', 'om', 'especes'], true)) {
        $erreur = "Moyen de paiement invalide.";
    } else {
        // Vérification qu'aucun compte n'existe déjà avec ce username, téléphone ou email (Règle : 1 compte unique)
        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ? OR telephone = ? OR (email IS NOT NULL AND email != '' AND email = ?) LIMIT 1");
        $stmtCheck->execute([$username, $telephone, $email]);

        if ($stmtCheck->fetch()) {
            $erreur = "Un compte existe déjà avec ce nom d'utilisateur, ce numéro de téléphone ou cet email.";
        } else {
            try {
                // TRANSACTION ATOMIQUE : Tout réussit ou tout est annulé
                $pdo->beginTransaction();

                $code_public     = 'DJG-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $code_tontine    = 'TON-' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $ville) . 'DLA', 0, 3)) . '-' . random_int(1000, 9999);
                $code_invitation = 'INV-' . strtoupper(bin2hex(random_bytes(3))) . '-' . date('y');
                $password_hash   = password_hash($mot_de_passe, PASSWORD_DEFAULT);

                // Étape A : Créer le compte de la Secrétaire / Administrateur (doit_changer_mdp = 0 car elle l'a choisi elle-même)
                $stmtUser = $pdo->prepare("
                    INSERT INTO users (
                        code_public, username, password_hash, nom_complet, telephone, email,
                        numero_cni, role, statut_membre, canal_paiement, telephone_paiement
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'admin', 'actif', ?, ?)
                ");
                $stmtUser->execute([
                    $code_public,
                    $username,
                    $password_hash,
                    $nom_complet,
                    $telephone,
                    $email !== '' ? $email : null,
                    $numero_cni !== '' ? $numero_cni : null,
                    $canal_paiement,
                    $telephone_paiement !== '' ? $telephone_paiement : $telephone
                ]);
                $admin_id = (int)$pdo->lastInsertId();

                // Étape B : Créer la Tontine avec les 2 mois d'essai gratuit rattachés à la tontine
                $debut_essai = date('Y-m-d H:i:s');
                $fin_essai   = date('Y-m-d H:i:s', strtotime('+2 months'));

                $stmtTontine = $pdo->prepare("
                    INSERT INTO tontines (
                        code_tontine, code_invitation, nom, description, ville, devise,
                        statut, date_debut_essai, date_fin_essai, statut_abonnement, admin_id
                    ) VALUES (?, ?, ?, ?, ?, 'FCFA', 'active', ?, ?, 'essai', ?)
                ");
                $stmtTontine->execute([
                    $code_tontine,
                    $code_invitation,
                    $nom_tontine,
                    $description !== '' ? $description : null,
                    $ville,
                    $debut_essai,
                    $fin_essai,
                    $admin_id
                ]);
                $tontine_id = (int)$pdo->lastInsertId();

                // Étape C : Rattacher la Secrétaire à sa tontine (elle est aussi membre cotisante)
                $stmtLink = $pdo->prepare("UPDATE users SET tontine_id = ? WHERE id = ?");
                $stmtLink->execute([$tontine_id, $admin_id]);

                // Étape D : Enregistrer la période d'essai gratuit de 2 mois dans subscriptions
                $ref_essai = 'TRIAL-' . $code_tontine;
                $stmtSub = $pdo->prepare("
                    INSERT INTO subscriptions (
                        tontine_id, type_periode, montant, date_debut, date_fin,
                        statut_paiement, reference_transaction, paye_par_id
                    ) VALUES (?, 'essai', 0.00, ?, ?, 'essai', ?, ?)
                ");
                $stmtSub->execute([$tontine_id, $debut_essai, $fin_essai, $ref_essai, $admin_id]);

                $pdo->commit();

                // Connexion automatique de la nouvelle Secrétaire
                session_regenerate_id(true);
                $_SESSION['user_id']     = $admin_id;
                $_SESSION['code_public'] = $code_public;
                $_SESSION['username']    = $username;
                $_SESSION['nom_complet'] = $nom_complet;
                $_SESSION['role']        = 'admin';
                $_SESSION['tontine_id']  = $tontine_id;

                message_flash('success', "Félicitations ! La tontine « {$nom_tontine} » est créée avec 2 mois d'essai gratuit. Code d'invitation : {$code_invitation}");
                header('Location: ' . BASE_URL . '/dashboard.php');
                exit;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $erreur = "Impossible de finaliser la création : vérifiez que vos coordonnées ne sont pas déjà utilisées.";
            }
        }
    }
}

$titre_page = "Créer une tontine";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="page-header-dark">
    <div class="container">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge bg-success px-3 py-2 rounded-pill mb-2">
                    <i class="bi bi-gift-fill me-1"></i>2 MOIS D'ESSAI GRATUITS INCLUS
                </span>
                <h1 class="fw-bold h2 mb-1">Créer votre tontine sur Djangui</h1>
                <p class="text-muted small mb-0">
                    Un administrateur crée une seule tontine, gère la caisse de réserve et participe également aux cycles comme membre cotisant.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="<?= BASE_URL ?>/join-tontine.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                    J'ai déjà un code d'invitation <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if ($erreur !== ''): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-exclamation-octagon-fill fs-5"></i>
            <div><?= e($erreur) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="register-tontine.php">
        <?= champ_csrf() ?>

        <div class="row g-4">
            <!-- COLONNE 1 : INFORMATIONS DE LA TONTINE -->
            <div class="col-lg-6">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-emerald">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge bg-success rounded-circle p-2"><i class="bi bi-safe2-fill fs-5"></i></span>
                        <h5 class="fw-bold mb-0">1. Informations de la Tontine</h5>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nom officiel de la tontine *</label>
                        <input type="text" name="nom_tontine" class="form-control"
                               placeholder="Ex: Djangui Solidarité Akwa"
                               value="<?= e($_POST['nom_tontine'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Ville principale *</label>
                        <select name="ville" class="form-select">
                            <?php
                            $villes = ['Douala', 'Yaoundé', 'Bafoussam', 'Garoua', 'Bamenda', 'Maroua', 'Ngaoundéré', 'Bertoua', 'Ebolowa', 'Buea', 'Kribi', 'Dschang'];
                            $ville_choisie = $_POST['ville'] ?? 'Douala';
                            foreach ($villes as $v):
                            ?>
                                <option value="<?= e($v) ?>" <?= $ville_choisie === $v ? 'selected' : '' ?>><?= e($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Règlement intérieur / Description</label>
                        <textarea name="description" class="form-control" rows="4"
                                  placeholder="Précisez l'objectif du groupe, le fonctionnement de la caisse de réserve et les règles de solidarité..."><?= e($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <div class="p-3 rounded-3 box-adaptive mt-auto small">
                        <div class="fw-bold text-success mb-1">
                            <i class="bi bi-check-circle-fill me-1"></i>Abonnement SaaS Communautaire
                        </div>
                        <p class="text-muted mb-0">
                            Votre tontine démarre avec <strong>2 mois 100 % gratuits</strong>. À partir du 3e mois, l'abonnement est de <strong>2 000 FCFA / mois</strong> pour toute la tontine (gratuit pour tous vos membres).
                        </p>
                    </div>
                </div>
            </div>

            <!-- COLONNE 2 : COMPTE DE LA SECRÉTAIRE / ADMINISTRATEUR -->
            <div class="col-lg-6">
                <div class="card card-djangui hover-lift h-100 p-4 border-left-navy">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge bg-primary rounded-circle p-2"><i class="bi bi-person-Vcard-fill fs-5"></i></span>
                        <h5 class="fw-bold mb-0">2. Compte Secrétaire / Administrateur</h5>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Votre nom complet *</label>
                            <input type="text" name="nom_complet" class="form-control"
                                   placeholder="Ex: Marie-Claire Ngono"
                                   value="<?= e($_POST['nom_complet'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Nom d'utilisateur (connexion) *</label>
                            <input type="text" name="username" class="form-control"
                                   placeholder="Ex: ngono_marie"
                                   value="<?= e($_POST['username'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Téléphone principal *</label>
                            <input type="text" name="telephone" class="form-control"
                                   placeholder="Ex: +237699223344"
                                   value="<?= e($_POST['telephone'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Adresse Email (optionnel)</label>
                            <input type="email" name="email" class="form-control"
                                   placeholder="exemple@domaine.cm"
                                   value="<?= e($_POST['email'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Canal Caisse & Pot *</label>
                            <select name="canal_paiement" class="form-select">
                                <option value="momo" <?= ($_POST['canal_paiement'] ?? '') === 'momo' ? 'selected' : '' ?>>MTN Mobile Money (MoMo)</option>
                                <option value="om" <?= ($_POST['canal_paiement'] ?? '') === 'om' ? 'selected' : '' ?>>Orange Money (OM)</option>
                                <option value="especes" <?= ($_POST['canal_paiement'] ?? '') === 'especes' ? 'selected' : '' ?>>Espèces</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">N° MoMo/OM (Caisse & Réception Pot)</label>
                            <input type="text" name="telephone_paiement" class="form-control"
                                   placeholder="Identique au téléphone si vide"
                                   value="<?= e($_POST['telephone_paiement'] ?? '') ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold small">
                                Numéro de CNI <span class="text-muted fw-normal">(Confidentiel — jamais affiché aux membres)</span>
                            </label>
                            <input type="text" name="numero_cni" class="form-control"
                                   placeholder="Optionnel"
                                   value="<?= e($_POST['numero_cni'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Mot de passe (min. 6 car.) *</label>
                            <input type="password" name="mot_de_passe" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Confirmer le mot de passe *</label>
                            <input type="password" name="confirmation_mdp" class="form-control" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-glow-emerald btn-lg w-100 mt-4">
                        <i class="bi bi-rocket-takeoff-fill me-2"></i>Créer ma tontine et activer l'essai gratuit
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
// group.php — ÉCRAN 4 UNIFIÉ : Membres (Validation CNI, Actifs/Inactifs/Sortis), Mon Profil, Infos Tontine, Abonnement 2 000 FCFA & Passation Admin
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

verifier_connexion();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id    = (int)$_SESSION['user_id'];
$est_admin  = (($_SESSION['role'] ?? '') === 'admin');

$onglet_actif = $_GET['tab'] ?? 'membres';

// 1. TRAITEMENT DES ACTIONS POST (MEMBRE & ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    // --- ACTIONS ADMIN SUR LES MEMBRES ---
    if ($est_admin && $action === 'accepter_membre') {
        $membre_id = (int)($_POST['membre_id'] ?? 0);
        $pdo->prepare("UPDATE users SET statut_validation = 'accepte', statut_membre = 'actif' WHERE id = ? AND tontine_id = ? AND role = 'member'")
            ->execute([$membre_id, $tontine_id]);
        message_flash('success', 'Le membre a été accepté et activé dans la tontine.');
        header('Location: ' . BASE_URL . '/group.php?tab=membres');
        exit;
    }

    if ($est_admin && $action === 'refuser_membre') {
        $membre_id = (int)($_POST['membre_id'] ?? 0);
        $pdo->prepare("UPDATE users SET statut_validation = 'refuse', statut_membre = 'inactif' WHERE id = ? AND tontine_id = ? AND role = 'member'")
            ->execute([$membre_id, $tontine_id]);
        message_flash('warning', 'La demande d\'adhésion a été refusée.');
        header('Location: ' . BASE_URL . '/group.php?tab=membres');
        exit;
    }

    if ($est_admin && $action === 'changer_statut_membre') {
        $membre_id      = (int)($_POST['membre_id'] ?? 0);
        $nouveau_statut = $_POST['statut_membre'] ?? '';
        if (in_array($nouveau_statut, ['actif', 'inactif', 'sorti'], true)) {
            $pdo->prepare("UPDATE users SET statut_membre = ? WHERE id = ? AND tontine_id = ? AND role = 'member'")
                ->execute([$nouveau_statut, $membre_id, $tontine_id]);
            message_flash('info', 'Le statut du membre a été mis à jour (' . $nouveau_statut . ').');
        }
        header('Location: ' . BASE_URL . '/group.php?tab=membres');
        exit;
    }

    if ($est_admin && $action === 'modifier_membre_admin') {
        $membre_id          = (int)($_POST['membre_id'] ?? 0);
        $nom_complet        = trim($_POST['nom_complet'] ?? '');
        $telephone          = trim($_POST['telephone'] ?? '');
        $email              = trim($_POST['email'] ?? '');
        $canal_paiement     = $_POST['canal_paiement'] ?? 'momo';
        $telephone_paiement = trim($_POST['telephone_paiement'] ?? $telephone);
        $numero_cni         = trim($_POST['numero_cni'] ?? '');

        if ($nom_complet !== '' && $telephone !== '' && $numero_cni !== '' && in_array($canal_paiement, ['momo', 'om', 'especes'], true)) {
            try {
                $pdo->prepare("
                    UPDATE users
                    SET nom_complet = ?, telephone = ?, email = ?, canal_paiement = ?, telephone_paiement = ?, numero_cni = ?
                    WHERE id = ? AND tontine_id = ? AND role = 'member'
                ")->execute([
                    $nom_complet,
                    $telephone,
                    $email !== '' ? $email : null,
                    $canal_paiement,
                    $telephone_paiement !== '' ? $telephone_paiement : $telephone,
                    $numero_cni,
                    $membre_id,
                    $tontine_id
                ]);
                message_flash('success', 'La fiche du membre a été mise à jour.');
            } catch (PDOException $e) {
                message_flash('danger', 'Impossible de modifier ce membre : numéro ou email déjà utilisé.');
            }
        }
        header('Location: ' . BASE_URL . '/group.php?tab=membres');
        exit;
    }

    // --- ACTIONS SUR MON PROFIL PERSONNEL (MEMBRE OU ADMIN) ---
    if ($action === 'modifier_mon_profil') {
        $nom_complet        = trim($_POST['nom_complet'] ?? '');
        $telephone          = trim($_POST['telephone'] ?? '');
        $email              = trim($_POST['email'] ?? '');
        $canal_paiement     = $_POST['canal_paiement'] ?? 'momo';
        $telephone_paiement = trim($_POST['telephone_paiement'] ?? $telephone);

        if ($nom_complet === '' || $telephone === '' || !in_array($canal_paiement, ['momo', 'om', 'especes'], true)) {
            message_flash('danger', 'Veuillez remplir correctement vos coordonnées obligatoires.');
        } else {
            try {
                $pdo->prepare("
                    UPDATE users
                    SET nom_complet = ?, telephone = ?, email = ?, canal_paiement = ?, telephone_paiement = ?
                    WHERE id = ? AND tontine_id = ?
                ")->execute([
                    $nom_complet,
                    $telephone,
                    $email !== '' ? $email : null,
                    $canal_paiement,
                    $telephone_paiement !== '' ? $telephone_paiement : $telephone,
                    $user_id,
                    $tontine_id
                ]);
                $_SESSION['nom_complet'] = $nom_complet;
                message_flash('success', 'Votre profil personnel et votre numéro MoMo/OM ont été mis à jour.');
            } catch (PDOException $e) {
                message_flash('danger', 'Ce numéro de téléphone ou cet email est déjà utilisé par un autre compte.');
            }
        }
        header('Location: ' . BASE_URL . '/group.php?tab=profil');
        exit;
    }

    if ($action === 'modifier_mon_mdp') {
        $ancien_mdp       = $_POST['ancien_mdp'] ?? '';
        $nouveau_mdp      = $_POST['nouveau_mdp'] ?? '';
        $confirmation_mdp = $_POST['confirmation_mdp'] ?? '';

        $stmtPwd = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmtPwd->execute([$user_id]);
        $hash_actuel = $stmtPwd->fetchColumn();

        if (!$hash_actuel || !password_verify($ancien_mdp, $hash_actuel)) {
            message_flash('danger', 'Votre mot de passe actuel est incorrect.');
        } elseif (strlen($nouveau_mdp) < 6) {
            message_flash('danger', 'Le nouveau mot de passe doit comporter au moins 6 caractères.');
        } elseif ($nouveau_mdp !== $confirmation_mdp) {
            message_flash('danger', 'La confirmation du nouveau mot de passe ne correspond pas.');
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($nouveau_mdp, PASSWORD_DEFAULT), $user_id]);
            message_flash('success', 'Votre mot de passe a été modifié avec succès.');
        }
        header('Location: ' . BASE_URL . '/group.php?tab=profil');
        exit;
    }

    // --- ACTIONS ADMIN : PARAMÈTRES TONTINE, ABONNEMENT 2 000 FCFA & PASSATION ---
    if ($est_admin && $action === 'modifier_tontine') {
        $nom         = trim($_POST['nom'] ?? '');
        $ville       = trim($_POST['ville'] ?? 'Douala');
        $description = trim($_POST['description'] ?? '');
        $statut_ton  = $_POST['statut'] ?? 'active';

        if ($nom !== '' && in_array($statut_ton, ['active', 'suspendue', 'fermee'], true)) {
            $pdo->prepare("UPDATE tontines SET nom = ?, ville = ?, description = ?, statut = ? WHERE id = ?")
                ->execute([$nom, $ville, $description !== '' ? $description : null, $statut_ton, $tontine_id]);
            message_flash('success', 'Les paramètres de la tontine ont été enregistrés.');
        }
        header('Location: ' . BASE_URL . '/group.php?tab=reglages');
        exit;
    }

    if ($est_admin && $action === 'regenerer_code_invitation') {
        $nouveau_code = 'INV-' . strtoupper(bin2hex(random_bytes(3))) . '-' . date('y');
        $pdo->prepare("UPDATE tontines SET code_invitation = ? WHERE id = ?")->execute([$nouveau_code, $tontine_id]);
        message_flash('info', 'Nouveau code d\'invitation généré : ' . $nouveau_code);
        header('Location: ' . BASE_URL . '/group.php?tab=membres');
        exit;
    }

    if ($est_admin && $action === 'payer_abonnement_saas') {
        $stmtT = $pdo->prepare("SELECT date_fin_essai, abonnement_valide_jusqua FROM tontines WHERE id = ? LIMIT 1");
        $stmtT->execute([$tontine_id]);
        $tRow = $stmtT->fetch();

        $maintenant = temps_actuel();
        $base_ts    = $maintenant;
        if (!empty($tRow['abonnement_valide_jusqua']) && strtotime($tRow['abonnement_valide_jusqua']) > $base_ts) {
            $base_ts = strtotime($tRow['abonnement_valide_jusqua']);
        } elseif (!empty($tRow['date_fin_essai']) && strtotime($tRow['date_fin_essai']) > $base_ts) {
            $base_ts = strtotime($tRow['date_fin_essai']);
        }

        $date_debut = date('Y-m-d H:i:s', $base_ts);
        $date_fin   = date('Y-m-d H:i:s', strtotime('+1 month', $base_ts));
        $ref_tx     = 'SAAS-2000-' . date_actuelle('Ym') . '-' . random_int(100, 999);

        $pdo->beginTransaction();
        $pdo->prepare("
            INSERT INTO subscriptions (tontine_id, type_periode, montant, date_debut, date_fin, statut_paiement, reference_transaction, paye_par_id)
            VALUES (?, 'mensuel', 2000.00, ?, ?, 'simule', ?, ?)
        ")->execute([$tontine_id, $date_debut, $date_fin, $ref_tx, $user_id]);

        $pdo->prepare("UPDATE tontines SET statut_abonnement = 'actif', abonnement_valide_jusqua = ? WHERE id = ?")
            ->execute([$date_fin, $tontine_id]);
        $pdo->commit();

        message_flash('success', 'Abonnement mensuel de 2 000 FCFA renouvelé jusqu\'au ' . date('d/m/Y', strtotime($date_fin)) . '.');
        header('Location: ' . BASE_URL . '/group.php?tab=reglages');
        exit;
    }

    // PASSATION D'ADMINISTRATION (Sans option "Cycle en cours" — Point 3 de la passe 2)
    if ($est_admin && $action === 'transferer_admin') {
        $nouvel_admin_id     = (int)($_POST['nouvel_admin_id'] ?? 0);
        $ancien_reste_membre = isset($_POST['ancien_reste_membre']) && $_POST['ancien_reste_membre'] === '0' ? 0 : 1;
        $motif               = trim($_POST['motif'] ?? '');

        $stmtCand = $pdo->prepare("SELECT id, nom_complet FROM users WHERE id = ? AND tontine_id = ? AND role = 'member' AND statut_membre = 'actif' AND statut_validation = 'accepte' LIMIT 1");
        $stmtCand->execute([$nouvel_admin_id, $tontine_id]);
        $candidat = $stmtCand->fetch();

        if (!$candidat) {
            message_flash('danger', 'Veuillez choisir un membre actif et validé de la tontine.');
        } else {
            $pdo->beginTransaction();

            $nouveau_statut_ancien = $ancien_reste_membre ? 'actif' : 'sorti';
            $pdo->prepare("UPDATE users SET role = 'member', statut_membre = ? WHERE id = ?")
                ->execute([$nouveau_statut_ancien, $user_id]);

            $pdo->prepare("UPDATE users SET role = 'admin', statut_membre = 'actif' WHERE id = ?")
                ->execute([$nouvel_admin_id]);

            $pdo->prepare("UPDATE tontines SET admin_id = ? WHERE id = ?")
                ->execute([$nouvel_admin_id, $tontine_id]);

            $pdo->prepare("
                INSERT INTO admin_transfers (tontine_id, ancien_admin_id, nouvel_admin_id, ancien_reste_membre, motif, date_transfert)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([
                $tontine_id,
                $user_id,
                $nouvel_admin_id,
                $ancien_reste_membre,
                $motif !== '' ? $motif : 'Passation de la gestion de la tontine'
            ]);

            $pdo->commit();

            if ($ancien_reste_membre === 1) {
                $_SESSION['role'] = 'member';
                message_flash('success', 'Passation effectuée : ' . $candidat['nom_complet'] . ' est désormais administrateur. Vous êtes maintenant membre cotisant.');
                header('Location: ' . BASE_URL . '/dashboard.php');
                exit;
            } else {
                $_SESSION = [];
                session_destroy();
                session_start();
                message_flash('info', 'Passation effectuée et retrait enregistré.');
                header('Location: ' . BASE_URL . '/login.php');
                exit;
            }
        }
        header('Location: ' . BASE_URL . '/group.php?tab=reglages');
        exit;
    }
}

// 2. CHARGEMENT DES DONNÉES
$stmtTontine = $pdo->prepare("
    SELECT t.*, u.nom_complet AS admin_nom, u.telephone_paiement AS admin_tel, u.canal_paiement AS admin_canal
    FROM tontines t
    JOIN users u ON u.id = t.admin_id
    WHERE t.id = ?
    LIMIT 1
");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

$stmtMonProfil = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmtMonProfil->execute([$user_id]);
$mon_profil = $stmtMonProfil->fetch();

$stmtMembres = $pdo->prepare("
    SELECT * FROM users
    WHERE tontine_id = ? AND statut_validation != 'refuse'
    ORDER BY (statut_validation = 'en_attente') DESC, (role = 'admin') DESC, (statut_membre = 'actif') DESC, nom_complet ASC
");
$stmtMembres->execute([$tontine_id]);
$membres = $stmtMembres->fetchAll();

// Compteurs exacts (Point 9 : afficher En attente, Actifs, Inactifs et Sortis)
$nb_attente  = 0;
$nb_actifs   = 0;
$nb_inactifs = 0;
$nb_sortis   = 0;
$candidats_passation = [];

foreach ($membres as $m) {
    if ($m['statut_validation'] === 'en_attente') {
        $nb_attente++;
    } elseif ($m['statut_membre'] === 'actif') {
        $nb_actifs++;
        if ($m['role'] === 'member') {
            $candidats_passation[] = $m;
        }
    } elseif ($m['statut_membre'] === 'inactif') {
        $nb_inactifs++;
    } elseif ($m['statut_membre'] === 'sorti') {
        $nb_sortis++;
    }
}

$abonnements = [];
$transferts  = [];
if ($est_admin) {
    $stmtSub = $pdo->prepare("
        SELECT s.*, u.nom_complet AS payeur_nom
        FROM subscriptions s
        LEFT JOIN users u ON u.id = s.paye_par_id
        WHERE s.tontine_id = ?
        ORDER BY s.date_fin DESC
    ");
    $stmtSub->execute([$tontine_id]);
    $abonnements = $stmtSub->fetchAll();

    $stmtTr = $pdo->prepare("
        SELECT tr.*, u_old.nom_complet AS ancien_nom, u_new.nom_complet AS nouveau_nom
        FROM admin_transfers tr
        JOIN users u_old ON u_old.id = tr.ancien_admin_id
        JOIN users u_new ON u_new.id = tr.nouvel_admin_id
        WHERE tr.tontine_id = ?
        ORDER BY tr.date_transfert DESC
    ");
    $stmtTr->execute([$tontine_id]);
    $transferts = $stmtTr->fetchAll();
}

$titre_page = "Groupe, Membres & Profil";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="container-fluid px-3 px-lg-4 py-4">

    <!-- EN-TÊTE + CODE D'INVITATION COPIABLE EN 1 CLIC -->
    <div class="card card-accent-djangui p-4 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-primary rounded-pill"><?= e($tontine['code_tontine']) ?></span>
                    <span class="badge bg-success rounded-pill"><i class="bi bi-geo-alt me-1"></i><?= e($tontine['ville']) ?></span>
                </div>
                <h2 class="fw-bold h4 mb-1"><?= e($tontine['nom']) ?></h2>
                <p class="text-muted small mb-0">
                    Secrétaire / Admin : <strong><?= e($tontine['admin_nom']) ?></strong>
                    (<?= strtoupper(e($tontine['admin_canal'])) ?> : <?= e($tontine['admin_tel']) ?>)
                </p>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="p-2 px-3 rounded-3 box-adaptive d-flex align-items-center gap-2">
                    <span class="small text-muted">Code d'invitation :</span>
                    <code class="fw-bold fs-6 text-primary"><?= e($tontine['code_invitation']) ?></code>
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" onclick="copierCodeInvitation('<?= e($tontine['code_invitation']) ?>', this)">
                        <i class="bi bi-clipboard me-1"></i>Copier
                    </button>
                </div>
                <?php if ($est_admin): ?>
                    <form method="POST" action="group.php?tab=membres" onsubmit="return confirm('Générer un nouveau code d\'invitation ?');">
                        <?= champ_csrf() ?>
                        <input type="hidden" name="action" value="regenerer_code_invitation">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Régénérer le code">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- NAVIGATION PAR ONGLETS PILLS -->
    <ul class="nav nav-pills nav-pills-djangui mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $onglet_actif === 'membres' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tabMembres" type="button">
                <i class="bi bi-people-fill me-1"></i>1. Membres du groupe (<?= count($membres) ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $onglet_actif === 'profil' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tabProfil" type="button">
                <i class="bi bi-person-circle me-1"></i>2. Mon Profil & Sécurité
            </button>
        </li>
        <?php if ($est_admin): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $onglet_actif === 'reglages' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tabReglages" type="button">
                <i class="bi bi-gear-fill me-1"></i>3. Tontine, Abonnement & Passation
            </button>
        </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content">

        <!-- =============================================================== -->
        <!-- ONGLET 1 : MEMBRES DU GROUPE & VALIDATION CNI (POINT 9)         -->
        <!-- =============================================================== -->
        <div class="tab-pane fade <?= $onglet_actif === 'membres' ? 'show active' : '' ?>" id="tabMembres">
            <div class="card card-djangui p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-people text-primary me-2"></i>Annuaire de la tontine</h5>

                    <!-- Badges de comptage complets (Point 9 : En attente, Actifs, Inactifs, Sortis) -->
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill">
                            <i class="bi bi-hourglass-split me-1"></i><?= $nb_attente ?> en attente
                        </span>
                        <span class="badge bg-success px-3 py-2 rounded-pill">
                            <i class="bi bi-check-circle me-1"></i><?= $nb_actifs ?> actif(s)
                        </span>
                        <?php if ($nb_inactifs > 0): ?>
                            <span class="badge bg-secondary px-3 py-2 rounded-pill">
                                <i class="bi bi-pause-circle me-1"></i><?= $nb_inactifs ?> inactif(s)
                            </span>
                        <?php endif; ?>
                        <?php if ($nb_sortis > 0): ?>
                            <span class="badge bg-danger px-3 py-2 rounded-pill">
                                <i class="bi bi-box-arrow-right me-1"></i><?= $nb_sortis ?> sorti(s)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-djangui align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Membre</th>
                                <th>Téléphone</th>
                                <th>Canal MoMo / OM</th>
                                <?php if ($est_admin): ?>
                                    <th>N° CNI (Admin)</th>
                                <?php endif; ?>
                                <th>Statut</th>
                                <?php if ($est_admin): ?>
                                    <th class="text-end">Actions Admin</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($membres as $m): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold">
                                            <?= e($m['nom_complet']) ?>
                                            <?php if ((int)$m['id'] === $user_id): ?>
                                                <span class="badge bg-primary-subtle text-primary ms-1">Vous</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= $m['role'] === 'admin' ? 'Secrétaire / Admin' : 'Membre' ?> · <code><?= e($m['code_public']) ?></code>
                                        </small>
                                    </td>
                                    <td><?= e($m['telephone']) ?></td>
                                    <td>
                                        <span class="badge bg-info-subtle text-info border me-1"><?= strtoupper(e($m['canal_paiement'])) ?></span>
                                        <span class="small fw-semibold"><?= e($m['telephone_paiement']) ?></span>
                                    </td>
                                    <?php if ($est_admin): ?>
                                        <td><code class="small"><?= e($m['numero_cni'] ?: 'Non renseigné') ?></code></td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ($m['statut_validation'] === 'en_attente'): ?>
                                            <span class="badge bg-warning text-dark">En attente d'approbation</span>
                                        <?php elseif ($m['statut_membre'] === 'actif'): ?>
                                            <span class="badge bg-success">Actif</span>
                                        <?php elseif ($m['statut_membre'] === 'inactif'): ?>
                                            <span class="badge bg-secondary">Inactif</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Sorti</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($est_admin): ?>
                                        <td class="text-end">
                                            <?php if ($m['role'] === 'admin'): ?>
                                                <span class="text-muted small">Compte Admin</span>
                                            <?php elseif ($m['statut_validation'] === 'en_attente'): ?>
                                                <div class="d-inline-flex gap-1">
                                                    <form method="POST" action="group.php?tab=membres">
                                                        <?= champ_csrf() ?>
                                                        <input type="hidden" name="action" value="accepter_membre">
                                                        <input type="hidden" name="membre_id" value="<?= (int)$m['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-glow-emerald">
                                                            <i class="bi bi-check-lg me-1"></i>Accepter
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="group.php?tab=membres" onsubmit="return confirm('Refuser cette demande ?');">
                                                        <?= champ_csrf() ?>
                                                        <input type="hidden" name="action" value="refuser_membre">
                                                        <input type="hidden" name="membre_id" value="<?= (int)$m['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Refuser</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <div class="d-inline-flex align-items-center gap-1">
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalEditMembre"
                                                            data-id="<?= (int)$m['id'] ?>"
                                                            data-nom="<?= e($m['nom_complet']) ?>"
                                                            data-tel="<?= e($m['telephone']) ?>"
                                                            data-email="<?= e($m['email'] ?? '') ?>"
                                                            data-canal="<?= e($m['canal_paiement']) ?>"
                                                            data-tel-paie="<?= e($m['telephone_paiement']) ?>"
                                                            data-cni="<?= e($m['numero_cni'] ?? '') ?>">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <form method="POST" action="group.php?tab=membres" class="d-inline">
                                                        <?= champ_csrf() ?>
                                                        <input type="hidden" name="action" value="changer_statut_membre">
                                                        <input type="hidden" name="membre_id" value="<?= (int)$m['id'] ?>">
                                                        <select name="statut_membre" class="form-select form-select-sm d-inline-block" style="width: 110px;" onchange="this.form.submit()">
                                                            <option value="actif" <?= $m['statut_membre'] === 'actif' ? 'selected' : '' ?>>Actif</option>
                                                            <option value="inactif" <?= $m['statut_membre'] === 'inactif' ? 'selected' : '' ?>>Inactif</option>
                                                            <option value="sorti" <?= $m['statut_membre'] === 'sorti' ? 'selected' : '' ?>>Sorti</option>
                                                        </select>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =============================================================== -->
        <!-- ONGLET 2 : MON PROFIL PERSONNEL & MOT DE PASSE                  -->
        <!-- =============================================================== -->
        <div class="tab-pane fade <?= $onglet_actif === 'profil' ? 'show active' : '' ?>" id="tabProfil">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card card-djangui p-4 h-100 border-left-emerald">
                        <h5 class="fw-bold mb-3"><i class="bi bi-person-vcard text-success me-2"></i>Mes coordonnées & Numéro de réception du pot</h5>
                        <form method="POST" action="group.php?tab=profil">
                            <?= champ_csrf() ?>
                            <input type="hidden" name="action" value="modifier_mon_profil">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Nom complet *</label>
                                    <input type="text" name="nom_complet" class="form-control" value="<?= e($mon_profil['nom_complet']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Nom d'utilisateur (fixe)</label>
                                    <input type="text" class="form-control" value="<?= e($mon_profil['username']) ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Téléphone principal *</label>
                                    <input type="text" name="telephone" class="form-control" value="<?= e($mon_profil['telephone']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?= e($mon_profil['email'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Canal de paiement principal *</label>
                                    <select name="canal_paiement" class="form-select">
                                        <option value="momo" <?= $mon_profil['canal_paiement'] === 'momo' ? 'selected' : '' ?>>MTN Mobile Money (MoMo)</option>
                                        <option value="om" <?= $mon_profil['canal_paiement'] === 'om' ? 'selected' : '' ?>>Orange Money (OM)</option>
                                        <option value="especes" <?= $mon_profil['canal_paiement'] === 'especes' ? 'selected' : '' ?>>Espèces</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Numéro MoMo/OM (réception de votre pot) *</label>
                                    <input type="text" name="telephone_paiement" class="form-control fw-bold" value="<?= e($mon_profil['telephone_paiement']) ?>" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-glow-emerald mt-4 px-4">
                                <i class="bi bi-check2-circle me-1"></i>Enregistrer mes coordonnées
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card card-djangui p-4 h-100 border-left-navy">
                        <h5 class="fw-bold mb-3"><i class="bi bi-shield-lock text-primary me-2"></i>Changer mon mot de passe</h5>
                        <form method="POST" action="group.php?tab=profil">
                            <?= champ_csrf() ?>
                            <input type="hidden" name="action" value="modifier_mon_mdp">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Mot de passe actuel *</label>
                                <input type="password" name="ancien_mdp" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Nouveau mot de passe (min. 6 caractères) *</label>
                                <input type="password" name="nouveau_mdp" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Confirmer le nouveau mot de passe *</label>
                                <input type="password" name="confirmation_mdp" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-glow-blue w-100">
                                <i class="bi bi-key me-1"></i>Mettre à jour mon mot de passe
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- =============================================================== -->
        <!-- ONGLET 3 (ADMIN) : RÉGLAGES TONTINE, ABONNEMENT & PASSATION     -->
        <!-- =============================================================== -->
        <?php if ($est_admin): ?>
        <div class="tab-pane fade <?= $onglet_actif === 'reglages' ? 'show active' : '' ?>" id="tabReglages">
            <div class="row g-4">
                <!-- Colonne 1 : Informations de la Tontine -->
                <div class="col-lg-6">
                    <div class="card card-djangui p-4 h-100">
                        <h5 class="fw-bold mb-3"><i class="bi bi-sliders text-primary me-2"></i>Informations & Règlement de la Tontine</h5>
                        <form method="POST" action="group.php?tab=reglages">
                            <?= champ_csrf() ?>
                            <input type="hidden" name="action" value="modifier_tontine">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Nom de la tontine *</label>
                                <input type="text" name="nom" class="form-control" value="<?= e($tontine['nom']) ?>" required>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Ville *</label>
                                    <input type="text" name="ville" class="form-control" value="<?= e($tontine['ville']) ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Statut</label>
                                    <select name="statut" class="form-select">
                                        <option value="active" <?= $tontine['statut'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="suspendue" <?= $tontine['statut'] === 'suspendue' ? 'selected' : '' ?>>Suspendue</option>
                                        <option value="fermee" <?= $tontine['statut'] === 'fermee' ? 'selected' : '' ?>>Fermée</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Description / Règlement intérieur</label>
                                <textarea name="description" class="form-control" rows="4"><?= e($tontine['description'] ?? '') ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-glow-blue">Enregistrer les modifications</button>
                        </form>
                    </div>
                </div>

                <!-- Colonne 2 : Abonnement SaaS 2 000 FCFA/mois + Passation d'Admin -->
                <div class="col-lg-6 d-flex flex-column gap-4">
                    <!-- Carte Abonnement SaaS -->
                    <div class="card card-djangui p-4 border-left-emerald">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-patch-check-fill text-success me-2"></i>Abonnement Djangui SaaS</h5>
                                <p class="text-muted small mb-0">2 mois d'essai gratuit puis <strong>2 000 FCFA / mois</strong> pour toute la tontine.</p>
                            </div>
                            <span class="badge bg-success"><?= strtoupper(e($tontine['statut_abonnement'])) ?></span>
                        </div>
                        <div class="p-3 rounded-3 box-adaptive my-2 small d-flex justify-content-between align-items-center">
                            <span>Valide jusqu'au : <strong><?= date('d/m/Y', strtotime($tontine['abonnement_valide_jusqua'] ?: $tontine['date_fin_essai'])) ?></strong></span>
                            <form method="POST" action="group.php?tab=reglages">
                                <?= champ_csrf() ?>
                                <input type="hidden" name="action" value="payer_abonnement_saas">
                                <button type="submit" class="btn btn-sm btn-glow-emerald">
                                    <i class="bi bi-credit-card me-1"></i>Prolonger +1 mois (2 000 FCFA)
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Carte Passation d'Administrateur (Point 3 de la passe 2 : sans "Cycle en cours") -->
                    <div class="card card-djangui p-4 border-left-warning">
                        <h5 class="fw-bold mb-2"><i class="bi bi-arrow-left-right text-warning me-2"></i>Passation d'administration</h5>
                        <p class="text-muted small mb-3">
                            Transférez votre rôle de Secrétaire / Administrateur à un autre membre actif de la tontine.
                        </p>

                        <?php if (empty($candidats_passation)): ?>
                            <div class="alert alert-light border small mb-0">
                                Aucun autre membre actif n'est disponible pour recevoir l'administration.
                            </div>
                        <?php else: ?>
                            <form method="POST" action="group.php?tab=reglages" onsubmit="return confirm('Confirmer le transfert immédiat de votre rôle d\'administrateur ?');">
                                <?= champ_csrf() ?>
                                <input type="hidden" name="action" value="transferer_admin">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Nouveau Secrétaire / Administrateur *</label>
                                    <select name="nouvel_admin_id" class="form-select form-select-sm" required>
                                        <option value="">-- Sélectionner un membre actif --</option>
                                        <?php foreach ($candidats_passation as $cand): ?>
                                            <option value="<?= (int)$cand['id'] ?>"><?= e($cand['nom_complet']) ?> (<?= e($cand['telephone']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Votre statut après la passation</label>
                                    <select name="ancien_reste_membre" class="form-select form-select-sm">
                                        <option value="1">Je reste membre cotisant de la tontine</option>
                                        <option value="0">Je quitte la tontine après la passation</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Motif de la passation</label>
                                    <input type="text" name="motif" class="form-control form-control-sm" placeholder="Ex: Rotation du bureau en fin de cycle">
                                </div>
                                <button type="submit" class="btn btn-warning btn-sm fw-bold text-dark w-100">
                                    <i class="bi bi-person-check-fill me-1"></i>Transférer l'administration
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if (!empty($transferts)): ?>
                            <hr class="my-3">
                            <div class="small fw-bold mb-1">Historique des passations :</div>
                            <?php foreach ($transferts as $tr): ?>
                                <div class="small text-muted">
                                    • Le <?= date('d/m/Y', strtotime($tr['date_transfert'])) ?> : de <strong><?= e($tr['ancien_nom']) ?></strong> vers <strong><?= e($tr['nouveau_nom']) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>

<!-- MODALE ADMIN : MODIFIER LA FICHE D'UN MEMBRE -->
<?php if ($est_admin): ?>
<div class="modal fade" id="modalEditMembre" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content card-djangui">
            <form method="POST" action="group.php?tab=membres">
                <?= champ_csrf() ?>
                <input type="hidden" name="action" value="modifier_membre_admin">
                <input type="hidden" name="membre_id" id="editMembreId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Modifier la fiche membre</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Nom complet *</label>
                        <input type="text" name="nom_complet" id="editNom" class="form-control form-control-sm" required>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Téléphone *</label>
                            <input type="text" name="telephone" id="editTel" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Email</label>
                            <input type="email" name="email" id="editEmail" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Canal *</label>
                            <select name="canal_paiement" id="editCanal" class="form-select form-select-sm">
                                <option value="momo">MTN MoMo</option>
                                <option value="om">Orange Money</option>
                                <option value="especes">Espèces</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">N° MoMo/OM *</label>
                            <input type="text" name="telephone_paiement" id="editTelPaie" class="form-control form-control-sm" required>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-semibold">Numéro de CNI *</label>
                        <input type="text" name="numero_cni" id="editCni" class="form-control form-control-sm" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-glow-blue btn-sm">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('modalEditMembre');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        const b = event.relatedTarget;
        document.getElementById('editMembreId').value = b.getAttribute('data-id');
        document.getElementById('editNom').value      = b.getAttribute('data-nom');
        document.getElementById('editTel').value      = b.getAttribute('data-tel');
        document.getElementById('editEmail').value    = b.getAttribute('data-email');
        document.getElementById('editCanal').value    = b.getAttribute('data-canal');
        document.getElementById('editTelPaie').value  = b.getAttribute('data-tel-paie');
        document.getElementById('editCni').value      = b.getAttribute('data-cni');
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

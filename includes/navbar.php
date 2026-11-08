<?php
// includes/navbar.php — Navigation App Moderne (Sidebar fixe PC + Topbar + Bottom Nav Mobile)
$page_courante = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$est_connecte  = !empty($_SESSION['user_id']);
$est_admin     = (($_SESSION['role'] ?? '') === 'admin');
$tontine_id_nav = (int)($_SESSION['tontine_id'] ?? 0);
$user_id_nav    = (int)($_SESSION['user_id'] ?? 0);

$nb_demandes_attente = 0;
$nb_alertes_cloche   = 0;
$liste_alertes_top   = [];
$nom_tontine_top     = 'Ma Tontine';
$solde_caisse_top    = 0.0;
$titre_cycle_top     = 'Aucun cycle';

if ($est_connecte && isset($pdo) && $pdo instanceof PDO && $tontine_id_nav > 0) {
    $stmtTopTontine = $pdo->prepare("SELECT nom FROM tontines WHERE id = ? LIMIT 1");
    $stmtTopTontine->execute([$tontine_id_nav]);
    if ($rowT = $stmtTopTontine->fetch()) {
        $nom_tontine_top = $rowT['nom'];
    }

    // Point 6 : La caisse est reliée à un seul cycle (le cycle sélectionné ou le cycle actif/dernier cycle)
    $cycle_id_req = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
    if ($cycle_id_req > 0) {
        $stmtCycNav = $pdo->prepare("SELECT titre, solde_caisse_reserve FROM cycles WHERE id = ? AND tontine_id = ? LIMIT 1");
        $stmtCycNav->execute([$cycle_id_req, $tontine_id_nav]);
        $rowCycNav = $stmtCycNav->fetch();
    } else {
        $rowCycNav = false;
    }
    if (!$rowCycNav) {
        $stmtCycNav = $pdo->prepare("
            SELECT titre, solde_caisse_reserve
            FROM cycles
            WHERE tontine_id = ? AND statut != 'brouillon'
            ORDER BY (statut = 'actif') DESC, numero_cycle DESC
            LIMIT 1
        ");
        $stmtCycNav->execute([$tontine_id_nav]);
        $rowCycNav = $stmtCycNav->fetch();
    }
    if ($rowCycNav) {
        $solde_caisse_top = (float)$rowCycNav['solde_caisse_reserve'];
        $titre_cycle_top  = $rowCycNav['titre'];
    }

    if ($est_admin) {
        $stmtAtt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tontine_id = ? AND statut_validation = 'en_attente'");
        $stmtAtt->execute([$tontine_id_nav]);
        $nb_demandes_attente = (int)$stmtAtt->fetchColumn();
        if ($nb_demandes_attente > 0) {
            $liste_alertes_top[] = [
                'icone' => 'bi-person-plus-fill text-warning',
                'texte' => $nb_demandes_attente . ' demande(s) d\'adhésion en attente',
                'lien'  => BASE_URL . '/group.php?tab=membres'
            ];
        }
    }

    $stmtRetNav = $pdo->prepare("
        SELECT COUNT(*)
        FROM contributions co
        INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
        INNER JOIN cycles c ON c.id = cm.cycle_id
        WHERE cm.user_id = ? AND c.tontine_id = ? AND c.statut = 'actif'
          AND co.cycle_member_id != co.beneficiaire_cycle_member_id
          AND (co.montant_attendu - co.montant_paye) > 0.01
          AND co.date_echeance < ?
    ");
    $stmtRetNav->execute([$user_id_nav, $tontine_id_nav, date_actuelle('Y-m-d')]);
    $mes_retards_nav = (int)$stmtRetNav->fetchColumn();
    if ($mes_retards_nav > 0) {
        $liste_alertes_top[] = [
            'icone' => 'bi-exclamation-triangle-fill text-danger',
            'texte' => $mes_retards_nav . ' cotisation(s) en retard à régulariser',
            'lien'  => BASE_URL . '/finances.php'
        ];
    }

    $stmtPenNav = $pdo->prepare("
        SELECT COUNT(*)
        FROM penalties p
        INNER JOIN cycle_members cm ON cm.id = p.cycle_member_id
        INNER JOIN cycles c ON c.id = cm.cycle_id
        WHERE cm.user_id = ? AND c.tontine_id = ? AND p.type_operation = 'penalite' AND p.statut = 'en_attente'
    ");
    $stmtPenNav->execute([$user_id_nav, $tontine_id_nav]);
    $mes_pen_nav = (int)$stmtPenNav->fetchColumn();
    if ($mes_pen_nav > 0) {
        $liste_alertes_top[] = [
            'icone' => 'bi-receipt-cutoff text-danger',
            'texte' => $mes_pen_nav . ' pénalité(s) en attente de règlement',
            'lien'  => BASE_URL . '/finances.php?tab=caisse'
        ];
    }

    $stmtZoomNav = $pdo->prepare("
        SELECT titre, date_heure FROM meetings
        WHERE tontine_id = ? AND statut = 'publiee' AND date_heure >= NOW()
        ORDER BY date_heure ASC LIMIT 1
    ");
    $stmtZoomNav->execute([$tontine_id_nav]);
    if ($rowZ = $stmtZoomNav->fetch()) {
        $liste_alertes_top[] = [
            'icone' => 'bi-camera-video-fill text-info',
            'texte' => 'Réunion : ' . $rowZ['titre'] . ' (' . date('d/m H:i', strtotime($rowZ['date_heure'])) . ')',
            'lien'  => BASE_URL . '/dashboard.php'
        ];
    }

    $nb_alertes_cloche = count($liste_alertes_top);
}
?>

<?php if (!$est_connecte): ?>
    <!-- BARRE DE NAVIGATION PUBLIQUE -->
    <nav class="navbar navbar-expand-lg navbar-public sticky-top py-3">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center gap-2 text-decoration-none" href="<?= BASE_URL ?>/index.php">
                <span class="sidebar-brand-icon"><i class="bi bi-safe2-fill"></i></span>
                <span class="fs-4">DJANGUI</span>
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <button type="button" class="btn-theme-toggle js-theme-toggle">
                    <i class="bi bi-moon-stars-fill js-theme-icon"></i>
                    <span class="js-theme-label d-none d-sm-inline">Mode Sombre</span>
                </button>
                <a href="<?= BASE_URL ?>/join-tontine.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 d-none d-md-inline-block">
                    Rejoindre avec un code
                </a>
                <a href="<?= BASE_URL ?>/login.php" class="btn btn-glow-blue btn-sm px-3 py-2">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Connexion
                </a>
            </div>
        </div>
    </nav>

<?php else: ?>
    <!-- LAYOUT APPLICATION CONNECTÉE : SIDEBAR FIXE SUR PC + TOPBAR -->
    <div class="app-shell">
        <aside class="app-sidebar">
            <a href="<?= BASE_URL ?>/dashboard.php" class="sidebar-brand">
                <span class="sidebar-brand-icon"><i class="bi bi-safe2-fill"></i></span>
                <div>
                    <div>DJANGUI</div>
                    <small class="sidebar-brand-sub">Gestion de Tontine</small>
                </div>
            </a>

            <div class="sidebar-profile-card">
                <div class="sidebar-profile-label">Tontine active</div>
                <div class="sidebar-profile-title text-truncate mb-1"><?= e($nom_tontine_top) ?></div>
                <span class="badge <?= $est_admin ? 'bg-warning text-dark' : 'bg-success' ?> rounded-pill">
                    <i class="bi <?= $est_admin ? 'bi-shield-check' : 'bi-person-check' ?> me-1"></i>
                    <?= $est_admin ? 'Secrétaire & Cotisant' : 'Membre Cotisant' ?>
                </span>
            </div>

            <!-- LES 4 PÔLES UNIFIÉS DE L'APPLICATION -->
            <nav class="sidebar-nav">
                <a href="<?= BASE_URL ?>/dashboard.php" class="sidebar-link <?= $page_courante === 'dashboard.php' ? 'active' : '' ?>">
                    <i class="bi bi-grid-1x2-fill"></i>
                    <span>1. Accueil & Séance</span>
                </a>

                <a href="<?= BASE_URL ?>/cycles.php" class="sidebar-link <?= $page_courante === 'cycles.php' ? 'active' : '' ?>">
                    <i class="bi bi-arrow-repeat"></i>
                    <span>2. Cycles & Tours</span>
                </a>

                <a href="<?= BASE_URL ?>/finances.php" class="sidebar-link <?= $page_courante === 'finances.php' ? 'active' : '' ?>">
                    <i class="bi bi-wallet2"></i>
                    <span><?= $est_admin ? '3. Cotisations & Caisse' : '3. Mes Cotisations' ?></span>
                </a>

                <a href="<?= BASE_URL ?>/group.php" class="sidebar-link <?= $page_courante === 'group.php' ? 'active' : '' ?>">
                    <i class="bi bi-people-fill"></i>
                    <span class="flex-grow-1">4. Groupe & Profil</span>
                    <?php if ($nb_demandes_attente > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill"><?= $nb_demandes_attente ?></span>
                    <?php endif; ?>
                </a>
            </nav>

            <div class="sidebar-footer d-flex flex-column gap-2">
                <button type="button" class="btn-theme-toggle js-theme-toggle w-100 justify-content-center">
                    <i class="bi bi-moon-stars-fill js-theme-icon"></i>
                    <span class="js-theme-label">Mode Sombre</span>
                </button>
                <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-danger btn-sm w-100 py-2">
                    <i class="bi bi-power me-1"></i>Déconnexion
                </a>
            </div>
        </aside>

        <div class="app-main app-main--with-sidebar">
            <header class="app-topbar d-flex justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-2">
                    <a href="<?= BASE_URL ?>/dashboard.php" class="d-lg-none text-decoration-none fw-bold d-flex align-items-center gap-2 text-primary">
                        <span class="sidebar-brand-icon" style="width:32px;height:32px;font-size:1rem;"><i class="bi bi-safe2-fill"></i></span>
                        <span>DJANGUI</span>
                    </a>
                    <div class="d-none d-lg-block">
                        <span class="text-muted small">Bonjour,</span>
                        <strong class="ms-1"><?= e($_SESSION['nom_complet'] ?? '') ?></strong>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <!-- Point 3 & Point 6 : Tout le monde voit le solde Caisse de réserve du cycle, mais seul l'admin a accès à la page Caisse -->
                    <?php if ($est_admin): ?>
                        <a href="<?= BASE_URL ?>/finances.php?tab=caisse" class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill text-decoration-none d-none d-sm-inline-flex align-items-center gap-1" title="<?= e($titre_cycle_top) ?>">
                            <i class="bi bi-piggy-bank-fill"></i>
                            <span>Caisse du cycle : <?= format_fcfa($solde_caisse_top) ?></span>
                        </a>
                    <?php else: ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill d-none d-sm-inline-flex align-items-center gap-1" title="<?= e($titre_cycle_top) ?>">
                            <i class="bi bi-piggy-bank-fill"></i>
                            <span>Caisse du cycle : <?= format_fcfa($solde_caisse_top) ?></span>
                        </span>
                    <?php endif; ?>

                    <div class="dropdown">
                        <button class="btn btn-sm box-adaptive rounded-pill px-3 py-1 position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Mes alertes">
                            <i class="bi bi-bell-fill <?= $nb_alertes_cloche > 0 ? 'text-warning' : 'text-muted' ?>"></i>
                            <?php if ($nb_alertes_cloche > 0): ?>
                                <span class="badge bg-danger rounded-pill ms-1"><?= $nb_alertes_cloche ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="min-width: 290px;">
                            <li class="px-2 py-1 fw-bold small border-bottom mb-1">Alertes & Rappels (<?= $nb_alertes_cloche ?>)</li>
                            <?php if (empty($liste_alertes_top)): ?>
                                <li class="px-2 py-3 text-center text-muted small">
                                    <i class="bi bi-check-circle text-success d-block fs-4 mb-1"></i>
                                    Aucune alerte en attente. Tout est à jour !
                                </li>
                            <?php else: ?>
                                <?php foreach ($liste_alertes_top as $al): ?>
                                    <li>
                                        <a class="dropdown-item rounded-2 small py-2 d-flex align-items-center gap-2 text-wrap" href="<?= e($al['lien']) ?>">
                                            <i class="bi <?= e($al['icone']) ?> fs-5"></i>
                                            <span><?= e($al['texte']) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <button type="button" class="btn-theme-toggle js-theme-toggle d-lg-none py-1 px-2">
                        <i class="bi bi-moon-stars-fill js-theme-icon"></i>
                    </button>
                    <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-danger btn-sm py-1 px-2 d-lg-none" title="Déconnexion">
                        <i class="bi bi-power"></i>
                    </a>
                </div>
            </header>

            <div class="mobile-bottom-nav d-lg-none">
                <a href="<?= BASE_URL ?>/dashboard.php" class="<?= $page_courante === 'dashboard.php' ? 'active' : '' ?>">
                    <i class="bi bi-grid-1x2-fill"></i><span>Accueil</span>
                </a>
                <a href="<?= BASE_URL ?>/cycles.php" class="<?= $page_courante === 'cycles.php' ? 'active' : '' ?>">
                    <i class="bi bi-arrow-repeat"></i><span>Cycles</span>
                </a>
                <a href="<?= BASE_URL ?>/finances.php" class="<?= $page_courante === 'finances.php' ? 'active' : '' ?>">
                    <i class="bi bi-wallet2"></i><span>Cotiser</span>
                </a>
                <a href="<?= BASE_URL ?>/group.php" class="<?= $page_courante === 'group.php' ? 'active' : '' ?>">
                    <i class="bi bi-people-fill"></i>
                    <span>Groupe<?= $nb_demandes_attente > 0 ? ' (' . $nb_demandes_attente . ')' : '' ?></span>
                </a>
            </div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_texte'])): ?>
    <div class="container-fluid px-3 px-lg-4 mt-3">
        <div class="alert alert-<?= e($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show shadow-sm mb-0" role="alert">
            <?= e($_SESSION['flash_texte']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    </div>
    <?php unset($_SESSION['flash_type'], $_SESSION['flash_texte']); ?>
<?php endif; ?>
<?php
// dashboard.php — ÉCRAN 1 UNIFIÉ : Accueil Guidé, Pot de la Séance Actuelle, Paiement Rapide & Réunions Zoom
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

verifier_connexion();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id    = (int)$_SESSION['user_id'];
$est_admin  = (($_SESSION['role'] ?? '') === 'admin');

// 1. TRAITEMENT POST : GESTION RAPIDE DES RÉUNIONS ZOOM (POUR LA SECRÉTAIRE / ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $est_admin) {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'creer_reunion') {
        $titre       = trim($_POST['titre'] ?? '');
        $date_saisie = trim($_POST['date_heure'] ?? '');
        $lien_zoom   = trim($_POST['lien_zoom'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $dt = DateTime::createFromFormat('!Y-m-d\TH:i', $date_saisie);
        $lien_ok = ($lien_zoom === '') || filter_var($lien_zoom, FILTER_VALIDATE_URL);

        if ($titre === '' || !$dt) {
            message_flash('danger', 'Veuillez renseigner un titre et une date/heure valides pour la réunion.');
        } elseif (!$lien_ok) {
            message_flash('danger', 'Le lien Zoom saisi n\'est pas une URL valide.');
        } else {
            $stmtIns = $pdo->prepare("
                INSERT INTO meetings (tontine_id, titre, date_heure, lien_zoom, description, statut, cree_par_id)
                VALUES (?, ?, ?, ?, ?, 'publiee', ?)
            ");
            $stmtIns->execute([
                $tontine_id,
                $titre,
                $dt->format('Y-m-d H:i:s'),
                $lien_zoom !== '' ? $lien_zoom : null,
                $description !== '' ? $description : null,
                $user_id
            ]);
            message_flash('success', 'La réunion Zoom a été programmée et publiée pour tous les membres.');
        }
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }

    if ($action === 'supprimer_reunion') {
        $meeting_id = (int)($_POST['meeting_id'] ?? 0);
        $pdo->prepare("DELETE FROM meetings WHERE id = ? AND tontine_id = ?")->execute([$meeting_id, $tontine_id]);
        message_flash('info', 'La réunion a été retirée de l\'agenda.');
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
}

// 2. RÉCUPÉRATION DES DONNÉES DE LA TONTINE & DES MEMBRES
$stmtTontine = $pdo->prepare("SELECT * FROM tontines WHERE id = ? LIMIT 1");
$stmtTontine->execute([$tontine_id]);
$tontine = $stmtTontine->fetch();

$stmtNbMembres = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN statut_validation = 'accepte' AND statut_membre = 'actif' THEN 1 ELSE 0 END) AS nb_actifs,
        SUM(CASE WHEN statut_validation = 'en_attente' THEN 1 ELSE 0 END) AS nb_attente
    FROM users
    WHERE tontine_id = ?
");
$stmtNbMembres->execute([$tontine_id]);
$rowMembres = $stmtNbMembres->fetch();
$nb_membres_actifs   = (int)($rowMembres['nb_actifs'] ?? 0);
$nb_demandes_attente = (int)($rowMembres['nb_attente'] ?? 0);

// 3. CYCLE ACTIF & SÉANCE OUVERTE
$stmtCycle = $pdo->prepare("SELECT * FROM cycles WHERE tontine_id = ? AND statut = 'actif' ORDER BY numero_cycle DESC LIMIT 1");
$stmtCycle->execute([$tontine_id]);
$cycle = $stmtCycle->fetch();

$seance_max_ouverte   = 1;
$mon_cycle_member     = null;
$prochaine_cotisation = null;
$pot_seance_actuelle  = null;
$cotisants_seance_act = [];
$nb_retards_cycle     = 0;
$mes_seances_payees   = 0;
$mes_seances_total    = 0;

if ($cycle) {
    $cid = (int)$cycle['id'];
    $seance_max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);

    $stmtCM = $pdo->prepare("SELECT * FROM cycle_members WHERE cycle_id = ? AND user_id = ? LIMIT 1");
    $stmtCM->execute([$cid, $user_id]);
    $mon_cycle_member = $stmtCM->fetch();

    if ($mon_cycle_member) {
        $stmtNext = $pdo->prepare("
            SELECT c.*, u_ben.nom_complet AS ben_nom
            FROM contributions c
            JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
            JOIN users u_ben ON u_ben.id = cm_ben.user_id
            WHERE c.cycle_member_id = ?
              AND c.numero_seance <= ?
              AND c.statut != 'paye'
              AND c.montant_paye < c.montant_attendu
            ORDER BY c.numero_seance ASC
            LIMIT 1
        ");
        $stmtNext->execute([(int)$mon_cycle_member['id'], $seance_max_ouverte]);
        $prochaine_cotisation = $stmtNext->fetch();

        // Statistiques personnelles du membre sur le cycle (pour la carte personnelle du membre)
        $stmtMesStats = $pdo->prepare("
            SELECT COUNT(*) AS nb_tot,
                   SUM(CASE WHEN statut = 'paye' OR montant_paye >= montant_attendu THEN 1 ELSE 0 END) AS nb_ok
            FROM contributions
            WHERE cycle_member_id = ?
        ");
        $stmtMesStats->execute([(int)$mon_cycle_member['id']]);
        $rowMesStats = $stmtMesStats->fetch();
        $mes_seances_total  = (int)($rowMesStats['nb_tot'] ?? 0);
        $mes_seances_payees = (int)($rowMesStats['nb_ok'] ?? 0);
    }

    // Pot de la Tontine Actuelle (Bouffeur de la séance ouverte #N)
    $stmtPotActuel = $pdo->prepare("
        SELECT 
            c.numero_seance,
            MIN(c.date_echeance) AS date_echeance,
            u_ben.id AS bouffeur_user_id,
            u_ben.nom_complet AS bouffeur_nom,
            u_ben.canal_paiement AS bouffeur_canal,
            u_ben.telephone_paiement AS bouffeur_tel,
            cm_ben.rang_beneficiaire,
            cm_ben.montant_pot_attendu,
            cm_ben.montant_pot_recu,
            COUNT(c.id) AS nb_cotisants,
            SUM(CASE WHEN c.statut = 'paye' OR c.montant_paye >= c.montant_attendu THEN 1 ELSE 0 END) AS nb_a_jour
        FROM contributions c
        JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
        JOIN users u_ben ON u_ben.id = cm_ben.user_id
        WHERE cm_ben.cycle_id = ? AND c.numero_seance = ?
        GROUP BY c.numero_seance, u_ben.id, u_ben.nom_complet, u_ben.canal_paiement, u_ben.telephone_paiement, cm_ben.rang_beneficiaire, cm_ben.montant_pot_attendu, cm_ben.montant_pot_recu
        LIMIT 1
    ");
    $stmtPotActuel->execute([$cid, $seance_max_ouverte]);
    $pot_seance_actuelle = $stmtPotActuel->fetch();

    // Point 1 : Seul l'admin charge et voit ce que les autres membres ont versé à une séance
    if ($est_admin) {
        $stmtListeAct = $pdo->prepare("
            SELECT c.montant_attendu, c.montant_paye, c.statut,
                   (c.cycle_member_id = c.beneficiaire_cycle_member_id) AS est_bouffeur,
                   u.nom_complet
            FROM contributions c
            JOIN cycle_members cm ON cm.id = c.cycle_member_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ? AND c.numero_seance = ?
            ORDER BY (c.statut = 'paye') DESC, u.nom_complet ASC
        ");
        $stmtListeAct->execute([$cid, $seance_max_ouverte]);
        $cotisants_seance_act = $stmtListeAct->fetchAll();

        $stmtRet = $pdo->prepare("
            SELECT COUNT(*)
            FROM contributions c
            JOIN cycle_members cm ON cm.id = c.cycle_member_id
            WHERE cm.cycle_id = ?
              AND c.cycle_member_id != c.beneficiaire_cycle_member_id
              AND c.montant_paye < c.montant_attendu
              AND c.date_echeance < CURDATE()
        ");
        $stmtRet->execute([$cid]);
        $nb_retards_cycle = (int)$stmtRet->fetchColumn();
    }
}

// 4. PROCHAINES RÉUNIONS ZOOM
$stmtReunions = $pdo->prepare("
    SELECT * FROM meetings
    WHERE tontine_id = ? AND statut = 'publiee'
    ORDER BY (date_heure >= NOW()) DESC, date_heure ASC
    LIMIT 3
");
$stmtReunions->execute([$tontine_id]);
$reunions = $stmtReunions->fetchAll();

// 5. DÉTERMINATION DE L'ÉTAPE ACTIVE DU PARCOURS GUIDÉ (STEPPER 1 à 4)
$etape_courante = 1;
if ($nb_membres_actifs >= 2) {
    $etape_courante = 2;
}
if ($cycle) {
    $etape_courante = ((int)$cycle['tirage_verrouille'] === 1) ? 4 : 3;
}

$titre_page = "Accueil & Séance Actuelle";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="container-fluid px-3 px-lg-4 py-4">

    <?php if ($est_admin && $nb_demandes_attente > 0): ?>
        <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 shadow-sm rounded-4 mb-4">
            <div>
                <i class="bi bi-person-exclamation-fill fs-5 me-2"></i>
                <strong><?= $nb_demandes_attente ?> demande(s) d'adhésion en attente :</strong> vérifiez le numéro de CNI des candidats pour leur ouvrir l'accès.
            </div>
            <a href="<?= BASE_URL ?>/group.php" class="btn btn-sm btn-warning fw-bold text-dark">
                Valider les membres <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    <?php endif; ?>

    <!-- 1. PARCOURS GUIDÉ EN 4 ÉTAPES (STEPPER VISUEL) -->
    <div class="stepper-djangui mb-4">
        <a href="<?= BASE_URL ?>/group.php" class="step-item text-decoration-none <?= $etape_courante > 1 ? 'step-done' : 'step-active' ?>">
            <span class="step-circle"><?= $etape_courante > 1 ? '<i class="bi bi-check-lg"></i>' : '1' ?></span>
            <div>
                <div class="step-title">1. Membres du groupe</div>
                <div class="text-muted" style="font-size: 0.76rem;"><?= $nb_membres_actifs ?> membre(s) actif(s)</div>
            </div>
        </a>

        <a href="<?= BASE_URL ?>/cycles.php" class="step-item text-decoration-none <?= $etape_courante > 2 ? 'step-done' : ($etape_courante === 2 ? 'step-active' : '') ?>">
            <span class="step-circle"><?= $etape_courante > 2 ? '<i class="bi bi-check-lg"></i>' : '2' ?></span>
            <div>
                <div class="step-title">2. Cycle de cotisation</div>
                <div class="text-muted" style="font-size: 0.76rem;"><?= $cycle ? e($cycle['titre']) : 'À créer / activer' ?></div>
            </div>
        </a>

        <a href="<?= BASE_URL ?>/cycles.php" class="step-item text-decoration-none <?= $etape_courante > 3 ? 'step-done' : ($etape_courante === 3 ? 'step-active' : '') ?>">
            <span class="step-circle"><?= $etape_courante > 3 ? '<i class="bi bi-check-lg"></i>' : '3' ?></span>
            <div>
                <div class="step-title">3. Tirage au sort</div>
                <div class="text-muted" style="font-size: 0.76rem;"><?= ($cycle && (int)$cycle['tirage_verrouille'] === 1) ? 'Ordre verrouillé' : 'En attente du tirage' ?></div>
            </div>
        </a>

        <a href="<?= BASE_URL ?>/finances.php" class="step-item text-decoration-none <?= $etape_courante === 4 ? 'step-active' : '' ?>">
            <span class="step-circle">4</span>
            <div>
                <div class="step-title">4. Séance en cours</div>
                <div class="text-muted" style="font-size: 0.76rem;"><?= $pot_seance_actuelle ? 'Séance #' . $seance_max_ouverte . ' ouverte' : 'En attente' ?></div>
            </div>
        </a>
    </div>

    <!-- 2. CŒUR DE L'ÉCRAN : POT DE LA TONTINE ACTUELLE + MA COTISATION -->
    <?php if (!$cycle): ?>
        <div class="card card-djangui p-5 text-center mb-4">
            <i class="bi bi-arrow-repeat display-4 text-primary mb-2"></i>
            <h4 class="fw-bold">Aucun cycle de cotisation n'est actif</h4>
            <p class="text-muted small mx-auto mb-3" style="max-width: 520px;">
                Code d'invitation de votre tontine : <code class="fw-bold fs-6"><?= e($tontine['code_invitation'] ?? '') ?></code>.
                <?= $est_admin ? 'Créez et activez un nouveau cycle pour lancer les séances.' : 'Les cycles précédents sont clôturés ou le prochain cycle est en cours de préparation.' ?>
            </p>
            <div>
                <a href="<?= BASE_URL ?>/cycles.php" class="btn btn-glow-emerald px-4 py-2">
                    <i class="bi bi-arrow-repeat me-1"></i><?= $est_admin ? 'Configurer un cycle ou voir les cycles terminés' : 'Consulter l\'historique des cycles' ?>
                </a>
            </div>
        </div>

    <?php elseif ((int)$cycle['tirage_verrouille'] === 0): ?>
        <div class="card card-accent-djangui p-5 text-center mb-4">
            <i class="bi bi-shuffle display-4 text-primary mb-2"></i>
            <h4 class="fw-bold"><?= e($cycle['titre']) ?> est activé !</h4>
            <p class="text-muted small mx-auto mb-3" style="max-width: 520px;">
                Il ne reste plus qu'à lancer le tirage au sort aléatoire pour attribuer le rang de chaque bouffeur et générer automatiquement le calendrier des séances.
            </p>
            <?php if ($est_admin): ?>
                <div>
                    <a href="<?= BASE_URL ?>/cycles.php" class="btn btn-glow-blue px-4 py-2">
                        <i class="bi bi-shuffle me-1"></i>Aller lancer le tirage au sort
                    </a>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <?php
            $pot_recu    = $pot_seance_actuelle ? (float)$pot_seance_actuelle['montant_pot_recu'] : 0.0;
            $pot_attendu = $pot_seance_actuelle ? (float)$pot_seance_actuelle['montant_pot_attendu'] : 0.0;
            $pct_pot     = ($pot_attendu > 0) ? min(100, round(($pot_recu / $pot_attendu) * 100)) : 0;
            $periode_act = $pot_seance_actuelle
                ? formater_periode_seance($cycle['date_debut'], (int)$pot_seance_actuelle['numero_seance'], $cycle['frequence'], $pot_seance_actuelle['date_echeance'])
                : '—';
            $c_est_mon_tour = ($pot_seance_actuelle && (int)$pot_seance_actuelle['bouffeur_user_id'] === $user_id);
        ?>
        <div class="row g-4 mb-4">
            <!-- COLONNE GAUCHE : POT DE LA TONTINE ACTUELLE -->
            <div class="col-lg-7">
                <div class="card-accent-djangui hover-lift h-100 p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <span class="badge bg-primary px-3 py-2 rounded-pill">
                            <i class="bi bi-trophy-fill text-warning me-1"></i>POT DE LA TONTINE ACTUELLE — SÉANCE #<?= $seance_max_ouverte ?>
                        </span>
                        <span class="small fw-semibold text-primary">
                            <i class="bi bi-calendar3 me-1"></i><?= e($periode_act) ?>
                        </span>
                    </div>

                    <?php if ($pot_seance_actuelle): ?>
                        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 my-3">
                            <div>
                                <div class="text-muted small text-uppercase fw-bold">Bénéficiaire du tour (« Bouffeur »)</div>
                                <h2 class="fw-bold mb-1 d-flex align-items-center gap-2">
                                    <span><?= e($pot_seance_actuelle['bouffeur_nom']) ?></span>
                                    <?php if ($c_est_mon_tour): ?>
                                        <span class="badge bg-success fs-6">C'est votre tour !</span>
                                    <?php endif; ?>
                                </h2>
                                <div class="small text-muted">
                                    Tour n°<?= (int)$pot_seance_actuelle['rang_beneficiaire'] ?> · Réception :
                                    <strong><?= strtoupper(e($pot_seance_actuelle['bouffeur_canal'])) ?> (<?= e($pot_seance_actuelle['bouffeur_tel']) ?>)</strong>
                                </div>
                            </div>

                            <div class="text-sm-end">
                                <div class="text-muted small">Pot collecté</div>
                                <div class="fs-3 fw-bold text-success"><?= format_fcfa($pot_recu) ?></div>
                                <div class="small text-muted">sur <?= format_fcfa($pot_attendu) ?></div>
                            </div>
                        </div>

                        <div class="progress mb-2" style="height: 14px;">
                            <div class="progress-bar bg-success progress-bar-striped" style="width: <?= $pct_pot ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between small text-muted">
                            <span>Progression du pot de la séance #<?= $seance_max_ouverte ?></span>
                            <span class="fw-bold text-success"><?= $pct_pot ?> %</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- COLONNE DROITE : MA COTISATION DIRECTE -->
            <div class="col-lg-5">
                <?php
                    $est_en_retard = ($prochaine_cotisation && strtotime($prochaine_cotisation['date_echeance']) < strtotime(date_actuelle('Y-m-d')));
                ?>
                <div class="card card-djangui hover-lift h-100 p-4 border-left-<?= $est_en_retard ? 'danger' : 'emerald' ?> d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-uppercase text-muted fw-bold small mb-0">
                            <i class="bi bi-wallet2 me-1"></i>Ma Situation sur la Séance
                        </h6>
                        <?php if ($nb_retards_cycle > 0 && $est_admin): ?>
                            <span class="badge bg-danger rounded-pill"><?= $nb_retards_cycle ?> retard(s) groupe</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($prochaine_cotisation): ?>
                        <?php
                            $reste_du = max(0.0, (float)$prochaine_cotisation['montant_attendu'] - (float)$prochaine_cotisation['montant_paye']);
                            $per_proch = formater_periode_seance($cycle['date_debut'], (int)$prochaine_cotisation['numero_seance'], $cycle['frequence'], $prochaine_cotisation['date_echeance']);
                        ?>
                        <div class="p-3 rounded-3 box-adaptive mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold">Séance #<?= (int)$prochaine_cotisation['numero_seance'] ?> (pour <?= e($prochaine_cotisation['ben_nom']) ?>)</span>
                                <span class="badge bg-<?= $est_en_retard ? 'danger' : 'primary' ?>">
                                    <?= $est_en_retard ? 'En retard' : 'À régler' ?>
                                </span>
                            </div>
                            <div class="fs-4 fw-bold text-primary my-1"><?= format_fcfa($reste_du) ?></div>
                            <div class="small text-muted"><?= e($per_proch) ?></div>
                        </div>

                        <a href="<?= BASE_URL ?>/finances.php" class="btn btn-glow-emerald w-100 py-2 mt-auto">
                            <i class="bi bi-credit-card-2-front me-1"></i>Régler ma cotisation maintenant
                        </a>
                    <?php elseif ($c_est_mon_tour): ?>
                        <div class="p-3 rounded-3 box-adaptive my-auto text-center">
                            <i class="bi bi-award-fill fs-1 text-success mb-1 d-block"></i>
                            <h6 class="fw-bold mb-1">Vous êtes le Bouffeur de cette séance !</h6>
                            <p class="small text-muted mb-0">
                                Votre propre part est automatiquement compensée sur votre pot (aucun versement à faire).
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="p-3 rounded-3 box-adaptive my-auto text-center">
                            <i class="bi bi-check-circle-fill fs-1 text-success mb-1 d-block"></i>
                            <h6 class="fw-bold mb-1">Vous êtes 100 % à jour !</h6>
                            <p class="small text-muted mb-0">
                                Votre cotisation de la Séance #<?= $seance_max_ouverte ?> est réglée. La séance suivante s'ouvrira dès la clôture de celle-ci.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 3. BAS DE PAGE : SUIVI DE SÉANCE (ADMIN) OU MON TOUR PERSONNEL (MEMBRE) + RÉUNIONS ZOOM -->
    <div class="row g-4">
        <div class="col-lg-7">
            <?php if ($est_admin): ?>
                <!-- VUE ADMIN : État des cotisations de chaque membre sur la séance actuelle -->
                <div class="card card-djangui p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0">
                            <i class="bi bi-people text-primary me-2"></i>Participants — Séance #<?= $seance_max_ouverte ?> (Vue Admin)
                        </h5>
                        <a href="<?= BASE_URL ?>/cycles.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                            Voir toutes les séances
                        </a>
                    </div>

                    <?php if (empty($cotisants_seance_act)): ?>
                        <p class="text-muted small mb-0">Les cotisations apparaîtront ici dès que le tirage au sort sera effectué.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-djangui align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Membre</th>
                                        <th>Versé / Attendu</th>
                                        <th class="text-end">Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cotisants_seance_act as $csa): ?>
                                        <tr>
                                            <td class="fw-semibold">
                                                <?= e($csa['nom_complet']) ?>
                                                <?php if ((int)$csa['est_bouffeur'] === 1): ?>
                                                    <span class="badge bg-info-subtle text-info border ms-1">Bouffeur du tour</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?= format_fcfa($csa['montant_paye']) ?></strong>
                                                <span class="text-muted small">/ <?= format_fcfa($csa['montant_attendu']) ?></span>
                                            </td>
                                            <td class="text-end">
                                                <?php if ((int)$csa['est_bouffeur'] === 1 && $csa['statut'] === 'paye'): ?>
                                                    <span class="badge bg-info text-dark">Compensé sur pot</span>
                                                <?php elseif ($csa['statut'] === 'paye' || (float)$csa['montant_paye'] >= (float)$csa['montant_attendu']): ?>
                                                    <span class="badge bg-success">Payé</span>
                                                <?php elseif ($csa['statut'] === 'en_retard'): ?>
                                                    <span class="badge bg-danger">En retard</span>
                                                <?php elseif ((float)$csa['montant_paye'] > 0): ?>
                                                    <span class="badge bg-warning text-dark">Partiel</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">En attente</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- VUE MEMBRE (POINT 1 & POINT 3) : Le membre ne voit PAS ce que les autres ont versé. Il voit son propre récapitulatif et le solde Caisse du cycle -->
                <div class="card card-djangui p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0">
                            <i class="bi bi-person-badge text-primary me-2"></i>Mon Récapitulatif sur le Cycle
                        </h5>
                        <a href="<?= BASE_URL ?>/cycles.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                            Ordre des bénéficiaires
                        </a>
                    </div>

                    <?php if ($cycle && $mon_cycle_member): ?>
                        <?php
                            $mon_rang = (int)($mon_cycle_member['rang_beneficiaire'] ?? 0);
                            $ma_periode_pot = ($mon_rang > 0)
                                ? formater_periode_seance($cycle['date_debut'], $mon_rang, $cycle['frequence'], $mon_cycle_member['date_prevue_pot'])
                                : 'En attente du tirage';
                        ?>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="p-3 rounded-3 box-adaptive h-100">
                                    <div class="text-muted small">Mon Rang de Bénéficiaire</div>
                                    <div class="fs-4 fw-bold text-primary mt-1">
                                        <?= $mon_rang > 0 ? 'Tour n°' . $mon_rang : 'Non tiré' ?>
                                    </div>
                                    <div class="small text-muted"><?= e($ma_periode_pot) ?></div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="p-3 rounded-3 box-adaptive h-100">
                                    <div class="text-muted small">Mes Séances Réglées</div>
                                    <div class="fs-4 fw-bold text-success mt-1">
                                        <?= $mes_seances_payees ?> / <?= $mes_seances_total ?>
                                    </div>
                                    <div class="small text-muted">Cotisation : <?= format_fcfa($cycle['montant_cotisation']) ?> / séance</div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="p-3 rounded-3 box-adaptive d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div>
                                        <div class="small text-muted">Solde Caisse de Réserve (<?= e($cycle['titre']) ?>)</div>
                                        <div class="fs-5 fw-bold text-success"><?= format_fcfa($cycle['solde_caisse_reserve'] ?? 0) ?></div>
                                    </div>
                                    <a href="<?= BASE_URL ?>/finances.php" class="btn btn-sm btn-glow-blue">
                                        Voir mes cotisations & reçus
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Aucune donnée de cycle actif disponible pour le moment.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Colonne Droite : Agenda Zoom intégré -->
        <div class="col-lg-5">
            <div class="card card-djangui p-4 h-100 border-left-navy">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-camera-video-fill text-info me-2"></i>Réunions Zoom
                    </h5>
                    <?php if ($est_admin): ?>
                        <button type="button" class="btn btn-sm btn-glow-blue" data-bs-toggle="modal" data-bs-target="#modalZoom">
                            <i class="bi bi-plus-lg me-1"></i>Programmer
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($reunions)): ?>
                    <div class="p-4 rounded-3 box-adaptive text-center text-muted small my-auto">
                        <i class="bi bi-calendar2-x fs-2 d-block mb-1"></i>
                        Aucune réunion en ligne programmée pour le moment.
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($reunions as $r): ?>
                            <div class="p-3 rounded-3 box-adaptive">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <div class="fw-bold"><?= e($r['titre']) ?></div>
                                        <div class="small text-primary fw-semibold">
                                            <i class="bi bi-clock me-1"></i><?= date('d/m/Y à H:i', strtotime($r['date_heure'])) ?>
                                        </div>
                                    </div>
                                    <?php if ($est_admin): ?>
                                        <form method="POST" action="dashboard.php" onsubmit="return confirm('Retirer cette réunion ?');">
                                            <?= champ_csrf() ?>
                                            <input type="hidden" name="action" value="supprimer_reunion">
                                            <input type="hidden" name="meeting_id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Supprimer">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($r['description'])): ?>
                                    <p class="text-muted small mt-1 mb-2"><?= e($r['description']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($r['lien_zoom'])): ?>
                                    <a href="<?= e($r['lien_zoom']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-glow-blue w-100 mt-2">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Rejoindre la réunion Zoom
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php if ($est_admin): ?>
<div class="modal fade" id="modalZoom" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content card-djangui">
            <form method="POST" action="dashboard.php">
                <?= champ_csrf() ?>
                <input type="hidden" name="action" value="creer_reunion">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-camera-video me-2 text-primary"></i>Programmer une réunion Zoom</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Titre de la réunion *</label>
                        <input type="text" name="titre" class="form-control" placeholder="Ex: Bilan de la Séance #2" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Date et heure *</label>
                        <input type="datetime-local" name="date_heure" class="form-control" value="<?= date_actuelle('Y-m-d') ?>T18:30" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Lien de visioconférence (Zoom / Meet)</label>
                        <input type="url" name="lien_zoom" class="form-control" placeholder="https://zoom.us/j/...">
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-semibold">Ordre du jour (optionnel)</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-glow-blue">Publier la réunion</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
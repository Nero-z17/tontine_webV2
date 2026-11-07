<?php
// cycles.php — ÉCRAN 2 UNIFIÉ : Cycles, Tirage au Sort Aléatoire, Calendrier des Séances & Bilan
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

verifier_connexion();

$tontine_id = (int)$_SESSION['tontine_id'];
$user_id    = (int)$_SESSION['user_id'];
$est_admin  = (($_SESSION['role'] ?? '') === 'admin');

// Liste des membres actifs et validés de la tontine (pour la sélection lors de la création d'un cycle)
$stmtMembresDispo = $pdo->prepare("
    SELECT id, nom_complet, telephone, role
    FROM users
    WHERE tontine_id = ? AND statut_membre = 'actif' AND statut_validation = 'accepte'
    ORDER BY (role = 'admin') DESC, nom_complet ASC
");
$stmtMembresDispo->execute([$tontine_id]);
$membres_actifs_tontine = $stmtMembresDispo->fetchAll();

// 1. TRAITEMENT DES ACTIONS POST (ADMIN UNIQUEMENT : CRÉER CYCLE, ACTIVER, SUPPRIMER BROUILLON, LANCER TIRAGE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $est_admin) {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    // ACTION A : Créer un nouveau cycle en brouillon avec choix des membres participants
    if ($action === 'creer_cycle') {
        $titre                = trim($_POST['titre'] ?? '');
        $montant_cotisation   = (float)($_POST['montant_cotisation'] ?? 0);
        $frequence            = $_POST['frequence'] ?? 'mensuel';
        $pourcentage_penalite = (float)($_POST['pourcentage_penalite'] ?? 0);
        $date_debut           = trim($_POST['date_debut'] ?? '');
        $date_fin             = trim($_POST['date_fin'] ?? '');
        $membres_saisis       = isset($_POST['membres_choisis']) && is_array($_POST['membres_choisis'])
            ? array_map('intval', $_POST['membres_choisis'])
            : [];

        // Filtrer uniquement les IDs appartenant réellement aux membres actifs de cette tontine
        $ids_autorises   = array_column($membres_actifs_tontine, 'id');
        $membres_retenus = array_values(array_intersect($ids_autorises, $membres_saisis));

        $dt_debut = DateTime::createFromFormat('!Y-m-d', $date_debut);
        $dt_fin   = ($date_fin !== '') ? DateTime::createFromFormat('!Y-m-d', $date_fin) : null;

        if ($titre === '' || $montant_cotisation <= 0 || !$dt_debut) {
            message_flash('danger', 'Veuillez renseigner un titre, un montant de cotisation valide et une date de début.');
        } elseif (count($membres_retenus) < 2) {
            message_flash('danger', 'Veuillez sélectionner au moins 2 membres participants pour créer un cycle.');
        } elseif ($pourcentage_penalite < 0 || $pourcentage_penalite > 50) {
            message_flash('danger', 'Le pourcentage de pénalité pour cotisation partielle doit être compris entre 0 % et 50 %.');
        } elseif (!in_array($frequence, ['hebdo', 'bimensuel', 'mensuel'], true)) {
            message_flash('danger', 'Fréquence invalide.');
        } else {
            $montant_penalite_retard = round(($montant_cotisation * $pourcentage_penalite) / 100, 2);
            $pot_attendu             = round($montant_cotisation * count($membres_retenus), 2);

            $stmtMax = $pdo->prepare("SELECT COALESCE(MAX(numero_cycle), 0) + 1 FROM cycles WHERE tontine_id = ?");
            $stmtMax->execute([$tontine_id]);
            $prochain_num = (int)$stmtMax->fetchColumn();

            $pdo->beginTransaction();

            $stmtIns = $pdo->prepare("
                INSERT INTO cycles (
                    tontine_id, numero_cycle, titre, montant_cotisation, frequence,
                    montant_penalite_retard, solde_caisse_reserve, date_debut, date_fin, statut
                ) VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?, 'brouillon')
            ");
            $stmtIns->execute([
                $tontine_id,
                $prochain_num,
                $titre,
                $montant_cotisation,
                $frequence,
                $montant_penalite_retard,
                $dt_debut->format('Y-m-d'),
                $dt_fin ? $dt_fin->format('Y-m-d') : null
            ]);
            $nouveau_cycle_id = (int)$pdo->lastInsertId();

            // Enregistrer immédiatement les membres choisis dans cycle_members
            $stmtInsCM = $pdo->prepare("INSERT INTO cycle_members (cycle_id, user_id, montant_pot_attendu) VALUES (?, ?, ?)");
            foreach ($membres_retenus as $uid) {
                $stmtInsCM->execute([$nouveau_cycle_id, (int)$uid, $pot_attendu]);
            }

            $pdo->commit();

            message_flash('success', "Le cycle « {$titre} » a été créé avec " . count($membres_retenus) . " participant(s) sélectionné(s) (Pot par tour : " . format_fcfa($pot_attendu) . ").");
        }
        header('Location: ' . BASE_URL . '/cycles.php');
        exit;
    }

    // ACTION B : Activer un cycle brouillon
    // Règle métier : Si les séances du cycle actif précédent sont toutes terminées (date de la dernière séance passée),
    // on peut activer un nouveau cycle même s'il reste des dettes ou des pénalités à régler sur le cycle précédent !
    if ($action === 'activer_cycle') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);

        $stmtActifs = $pdo->prepare("SELECT id, titre, tirage_verrouille FROM cycles WHERE tontine_id = ? AND statut = 'actif'");
        $stmtActifs->execute([$tontine_id]);
        $cycles_actifs_existants = $stmtActifs->fetchAll();

        $cycle_bloquant = null;
        $cycles_echus_a_basculer = [];

        foreach ($cycles_actifs_existants as $cae) {
            if ((int)$cae['tirage_verrouille'] === 0) {
                $cycle_bloquant = $cae['titre'];
                break;
            }
            $stmtMaxEch = $pdo->prepare("
                SELECT MAX(co.date_echeance)
                FROM contributions co
                INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
                WHERE cm.cycle_id = ?
            ");
            $stmtMaxEch->execute([(int)$cae['id']]);
            $derniere_ech_cae = $stmtMaxEch->fetchColumn();

            // Si la dernière séance du cycle actif n'est pas encore passée, il est toujours en cours de séances
            if (!$derniere_ech_cae || strtotime($derniere_ech_cae) >= strtotime(date_actuelle('Y-m-d'))) {
                $cycle_bloquant = $cae['titre'];
                break;
            } else {
                // Les séances de cet ancien cycle sont terminées : il ne bloque pas le nouveau cycle !
                $cycles_echus_a_basculer[] = (int)$cae['id'];
            }
        }

        if ($cycle_bloquant !== null) {
            message_flash('warning', "Le cycle « {$cycle_bloquant} » a encore des séances en cours. Attendez la fin de sa dernière séance ou utilisez le bouton « Arrêter le cycle » avant d'en activer un nouveau.");
            header('Location: ' . BASE_URL . '/cycles.php');
            exit;
        }

        // Un ancien cycle dont toutes les séances sont passées ne passe à 'termine' que s'il n'a plus d'impayés ni de pénalités en attente.
        // S'il lui reste des dettes ou pénalités, il reste ouvert au recouvrement jusqu'à règlement complet, sans bloquer ce nouveau cycle !
        synchroniser_horloge_et_retards($pdo);

        $stmtC = $pdo->prepare("SELECT * FROM cycles WHERE id = ? AND tontine_id = ? AND statut = 'brouillon' LIMIT 1");
        $stmtC->execute([$cycle_id, $tontine_id]);
        $cBrouillon = $stmtC->fetch();

        if ($cBrouillon) {
            $stmtNbInscrits = $pdo->prepare("SELECT COUNT(*) FROM cycle_members WHERE cycle_id = ?");
            $stmtNbInscrits->execute([$cycle_id]);
            $nb_inscrits = (int)$stmtNbInscrits->fetchColumn();

            // Si c'est un ancien brouillon sans membres pré-enregistrés, on inscrit les membres actifs
            if ($nb_inscrits === 0) {
                $ids_membres = array_column($membres_actifs_tontine, 'id');
                $nb_inscrits = count($ids_membres);
                if ($nb_inscrits >= 2) {
                    $pot_attendu = (float)$cBrouillon['montant_cotisation'] * $nb_inscrits;
                    $stmtInsCM = $pdo->prepare("INSERT IGNORE INTO cycle_members (cycle_id, user_id, montant_pot_attendu) VALUES (?, ?, ?)");
                    foreach ($ids_membres as $uid) {
                        $stmtInsCM->execute([$cycle_id, (int)$uid, $pot_attendu]);
                    }
                }
            }

            if ($nb_inscrits < 2) {
                message_flash('danger', 'Il faut au moins 2 membres inscrits dans ce cycle pour l\'activer.');
            } else {
                $pdo->prepare("UPDATE cycles SET statut = 'actif' WHERE id = ?")->execute([$cycle_id]);
                message_flash('success', 'Cycle activé avec ' . $nb_inscrits . ' participants ! Vous pouvez maintenant lancer le tirage au sort.');
            }
        }
        header('Location: ' . BASE_URL . '/cycles.php?cycle_id=' . $cycle_id);
        exit;
    }

    // ACTION C : Supprimer un cycle brouillon
    if ($action === 'supprimer_brouillon') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);
        $stmtDel = $pdo->prepare("DELETE FROM cycles WHERE id = ? AND tontine_id = ? AND statut = 'brouillon'");
        $stmtDel->execute([$cycle_id, $tontine_id]);
        if ($stmtDel->rowCount() > 0) {
            message_flash('info', 'Le cycle brouillon a été supprimé.');
        }
        header('Location: ' . BASE_URL . '/cycles.php');
        exit;
    }

    // ACTION C-BIS : Arrêter / Clôturer manuellement un cycle actif
    if ($action === 'arreter_cycle') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);
        $stmtStop = $pdo->prepare("UPDATE cycles SET statut = 'termine', date_fin = CURDATE() WHERE id = ? AND tontine_id = ? AND statut = 'actif'");
        $stmtStop->execute([$cycle_id, $tontine_id]);
        if ($stmtStop->rowCount() > 0) {
            message_flash('warning', 'Le cycle a été arrêté et clôturé. Vous pouvez désormais activer un nouveau cycle (les éventuels impayés ou pénalités restent réglables dans l\'onglet Cotisations & Caisse).');
        }
        header('Location: ' . BASE_URL . '/cycles.php?cycle_id=' . $cycle_id);
        exit;
    }

    // ACTION D : Lancer le tirage au sort aléatoire et générer toutes les séances
    if ($action === 'lancer_tirage') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);
        $stmtC = $pdo->prepare("SELECT * FROM cycles WHERE id = ? AND tontine_id = ? AND statut = 'actif' LIMIT 1");
        $stmtC->execute([$cycle_id, $tontine_id]);
        $cActif = $stmtC->fetch();

        if ($cActif && (int)$cActif['tirage_verrouille'] === 0) {
            $stmtParts = $pdo->prepare("SELECT id, user_id FROM cycle_members WHERE cycle_id = ? ORDER BY id ASC");
            $stmtParts->execute([$cycle_id]);
            $participants = $stmtParts->fetchAll();
            $nb_participants = count($participants);

            if ($nb_participants >= 2) {
                $indices = range(0, $nb_participants - 1);
                for ($i = $nb_participants - 1; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    $tmp = $indices[$i];
                    $indices[$i] = $indices[$j];
                    $indices[$j] = $tmp;
                }

                $pdo->beginTransaction();
                $montant_cotis = (float)$cActif['montant_cotisation'];
                $pot_total     = $montant_cotis * $nb_participants;

                $stmtUpdCM = $pdo->prepare("UPDATE cycle_members SET rang_beneficiaire = ?, date_prevue_pot = ?, montant_pot_attendu = ? WHERE id = ?");
                $stmtInsCo = $pdo->prepare("
                    INSERT INTO contributions (cycle_member_id, beneficiaire_cycle_member_id, numero_seance, date_echeance, montant_attendu, montant_paye, statut)
                    VALUES (?, ?, ?, ?, ?, 0.00, 'en_attente')
                ");

                $ordre_bouffeurs = [];
                $rang = 1;
                foreach ($indices as $idx) {
                    $cm_id      = (int)$participants[$idx]['id'];
                    $ech_seance = calculer_echeance_seance($cActif['date_debut'], $rang, $cActif['frequence']);
                    $stmtUpdCM->execute([$rang, $ech_seance, $pot_total, $cm_id]);
                    $ordre_bouffeurs[$rang] = ['cm_id' => $cm_id, 'echeance' => $ech_seance];
                    $rang++;
                }

                foreach ($ordre_bouffeurs as $num_seance => $info_b) {
                    foreach ($participants as $payeur) {
                        $stmtInsCo->execute([
                            (int)$payeur['id'],
                            (int)$info_b['cm_id'],
                            (int)$num_seance,
                            $info_b['echeance'],
                            $montant_cotis
                        ]);
                    }
                }

                $derniere_ech = $ordre_bouffeurs[$nb_participants]['echeance'] ?? $cActif['date_debut'];
                $pdo->prepare("UPDATE cycles SET date_tirage = NOW(), tirage_verrouille = 1, auteur_tirage_id = ?, date_fin = ? WHERE id = ?")
                    ->execute([$user_id, $derniere_ech, $cycle_id]);

                $pdo->commit();
                synchroniser_horloge_et_retards($pdo);
                message_flash('success', 'Tirage au sort effectué et verrouillé ! Le calendrier des ' . $nb_participants . ' séances a été généré.');
            }
        }
        header('Location: ' . BASE_URL . '/cycles.php?cycle_id=' . $cycle_id);
        exit;
    }
}

// 2. RÉCUPÉRATION DE TOUS LES CYCLES DE LA TONTINE
$stmtCycles = $pdo->prepare("SELECT * FROM cycles WHERE tontine_id = ? ORDER BY (statut = 'actif') DESC, numero_cycle DESC");
$stmtCycles->execute([$tontine_id]);
$tous_cycles = $stmtCycles->fetchAll();

$cycle_id_choisi = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
$cycle_choisi    = null;

foreach ($tous_cycles as $c) {
    if ($cycle_id_choisi > 0 && (int)$c['id'] === $cycle_id_choisi) {
        $cycle_choisi = $c;
        break;
    }
}
if (!$cycle_choisi && !empty($tous_cycles)) {
    $cycle_choisi = $tous_cycles[0];
}

// 3. DONNÉES DU CYCLE SÉLECTIONNÉ
$seances_bilan        = [];
$cotisants_par_seance = [];
$seance_max_ouverte   = 1;
$total_attendu_cycle  = 0.0;
$total_collecte_cycle = 0.0;
$total_reliquat_cycle = 0.0;

if ($cycle_choisi) {
    $cid = (int)$cycle_choisi['id'];
    $seance_max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);

    $stmtSeances = $pdo->prepare("
        SELECT 
            c.numero_seance,
            MIN(c.date_echeance) AS date_echeance,
            u.id AS bouffeur_user_id,
            u.nom_complet AS nom_bouffeur,
            u.canal_paiement,
            u.telephone_paiement,
            cm_ben.rang_beneficiaire,
            cm_ben.montant_pot_attendu,
            cm_ben.montant_pot_recu,
            cm_ben.pot_totalement_recu,
            COUNT(c.id) AS nb_cotisations,
            SUM(c.montant_attendu) AS total_seance_attendu,
            SUM(c.montant_paye) AS total_seance_paye,
            SUM(CASE WHEN c.statut = 'paye' OR c.montant_paye >= c.montant_attendu THEN 1 ELSE 0 END) AS nb_payes
        FROM contributions c
        JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
        JOIN users u ON u.id = cm_ben.user_id
        WHERE cm_ben.cycle_id = ?
        GROUP BY c.numero_seance, u.id, u.nom_complet, u.canal_paiement, u.telephone_paiement,
                 cm_ben.rang_beneficiaire, cm_ben.montant_pot_attendu, cm_ben.montant_pot_recu, cm_ben.pot_totalement_recu
        ORDER BY c.numero_seance ASC
    ");
    $stmtSeances->execute([$cid]);
    $seances_bilan = $stmtSeances->fetchAll();

    foreach ($seances_bilan as $sb) {
        $total_attendu_cycle  += (float)$sb['total_seance_attendu'];
        $total_collecte_cycle += (float)$sb['total_seance_paye'];
    }
    $total_reliquat_cycle = max(0.0, $total_attendu_cycle - $total_collecte_cycle);

    // Point 1 : Seul l'admin charge le détail de ce que chaque membre a versé par séance
    if ($est_admin) {
        $stmtDet = $pdo->prepare("
            SELECT c.numero_seance, c.montant_attendu, c.montant_paye, c.statut,
                   (c.cycle_member_id = c.beneficiaire_cycle_member_id) AS est_bouffeur,
                   u.nom_complet
            FROM contributions c
            JOIN cycle_members cm ON cm.id = c.cycle_member_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ?
            ORDER BY c.numero_seance ASC, (c.statut = 'paye') DESC, u.nom_complet ASC
        ");
        $stmtDet->execute([$cid]);
        foreach ($stmtDet->fetchAll() as $d) {
            $cotisants_par_seance[(int)$d['numero_seance']][] = $d;
        }
    }
}

$titre_page = "Cycles, Tirage & Calendrier des Tours";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="container-fluid px-3 px-lg-4 py-4">

    <!-- EN-TÊTE + SÉLECTEUR DE CYCLE + BOUTONS D'ACTION -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">
                <i class="bi bi-arrow-repeat text-primary me-2"></i>Cycles, Tirage au Sort & Calendrier des Tours
            </h1>
            <p class="text-muted small mb-0">
                Un cycle se clôture automatiquement dès que la date de la dernière séance est passée et que toutes les pénalités sont réglées.
            </p>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 no-print">
            <?php if (!empty($tous_cycles)): ?>
                <form method="GET" action="cycles.php" class="d-flex align-items-center gap-2 m-0">
                    <select name="cycle_id" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()" style="min-width: 230px;">
                        <?php foreach ($tous_cycles as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ($cycle_choisi && (int)$c['id'] === (int)$cycle_choisi['id']) ? 'selected' : '' ?>>
                                <?= e($c['titre']) ?> (<?= strtoupper($c['statut']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            
            <?php if ($est_admin) : ?>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer me-1"></i>Imprimer
            </button>
            <?php endif; ?>


            <?php if ($est_admin && $cycle_choisi && $cycle_choisi['statut'] === 'actif'): ?>
                <form method="POST" action="cycles.php" class="d-inline m-0" onsubmit="return confirm('Voulez-vous vraiment arrêter et clôturer ce cycle maintenant ?');">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="action" value="arreter_cycle">
                    <input type="hidden" name="cycle_id" value="<?= (int)$cycle_choisi['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-stop-circle me-1"></i>Arrêter ce cycle
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($est_admin): ?>
                <button type="button" class="btn btn-sm btn-glow-emerald" data-bs-toggle="modal" data-bs-target="#modalNouveauCycle">
                    <i class="bi bi-plus-circle me-1"></i>Nouveau cycle
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$cycle_choisi): ?>
        <div class="card card-djangui p-5 text-center">
            <i class="bi bi-calendar-plus display-4 text-muted mb-2"></i>
            <h5 class="fw-bold">Aucun cycle enregistré pour cette tontine</h5>
            <?php if ($est_admin): ?>
                <div class="mt-2">
                    <button type="button" class="btn btn-glow-emerald" data-bs-toggle="modal" data-bs-target="#modalNouveauCycle">
                        Créer le premier cycle
                    </button>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php
            $montant_cotis    = (float)$cycle_choisi['montant_cotisation'];
            $pen_partielle    = (float)$cycle_choisi['montant_penalite_retard'];
            $pen_non_cotis    = round($pen_partielle * 2, 2);
            $pct_partielle    = $montant_cotis > 0 ? round(($pen_partielle / $montant_cotis) * 100, 1) : 0;
            $pct_non_cotis    = round($pct_partielle * 2, 1);
            $solde_caisse_cyc = (float)($cycle_choisi['solde_caisse_reserve'] ?? 0);
        ?>

        <!-- CARTES KPI DU CYCLE SÉLECTIONNÉ (Point 3 : le membre ne voit ni Total Cotisé ni Reliquat, mais voit le Solde Caisse du cycle) -->
        <div class="row g-3 mb-4">
            <?php if ($est_admin): ?>
                <div class="col-sm-6 col-xl-3">
                    <div class="card card-djangui p-3 border-left-navy h-100">
                        <div class="text-muted small">Cotisation / Séance</div>
                        <div class="fs-4 fw-bold text-primary"><?= format_fcfa($montant_cotis) ?></div>
                        <small class="text-muted">Fréquence : <?= ucfirst(e($cycle_choisi['frequence'])) ?></small>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card card-djangui p-3 border-left-emerald h-100">
                        <div class="text-muted small">Total Cotisé (Cycle)</div>
                        <div class="fs-4 fw-bold text-success"><?= format_fcfa($total_collecte_cycle) ?></div>
                        <small class="text-muted">sur <?= format_fcfa($total_attendu_cycle) ?> attendus</small>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card card-djangui p-3 border-left-<?= $total_reliquat_cycle > 0 ? 'warning' : 'emerald' ?> h-100">
                        <div class="text-muted small">Reliquats à recouvrer</div>
                        <div class="fs-4 fw-bold <?= $total_reliquat_cycle > 0 ? 'text-warning' : 'text-success' ?>"><?= format_fcfa($total_reliquat_cycle) ?></div>
                        <small class="text-muted">Pénalité : <?= $pct_partielle ?>% (partiel) / <?= $pct_non_cotis ?>% (0 FCFA)</small>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card card-djangui p-3 border-left-emerald h-100">
                        <div class="text-muted small">Solde Caisse de Réserve (Cycle)</div>
                        <div class="fs-4 fw-bold text-success"><?= format_fcfa($solde_caisse_cyc) ?></div>
                        <small class="text-muted">Propre à <?= e($cycle_choisi['titre']) ?></small>
                    </div>
                </div>
            <?php else: ?>
                <!-- VUE MEMBRE (POINT 3) : Pas de Total Cotisé ni de Reliquat à recouvrer, mais Solde Caisse de Réserve visible -->
                <div class="col-md-4">
                    <div class="card card-djangui p-3 border-left-navy h-100">
                        <div class="text-muted small">Cotisation / Séance</div>
                        <div class="fs-4 fw-bold text-primary"><?= format_fcfa($montant_cotis) ?></div>
                        <small class="text-muted">Fréquence : <?= ucfirst(e($cycle_choisi['frequence'])) ?></small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-djangui p-3 border-left-warning h-100">
                        <div class="text-muted small">Barème des Pénalités du Cycle</div>
                        <div class="fw-bold text-warning mt-1">
                            Partielle : <?= $pct_partielle ?> % (<?= format_fcfa($pen_partielle) ?>)
                        </div>
                        <small class="text-danger fw-semibold">
                            Non-cotisation (2x) : <?= $pct_non_cotis ?> % (<?= format_fcfa($pen_non_cotis) ?>)
                        </small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-djangui p-3 border-left-emerald h-100">
                        <div class="text-muted small">Solde Caisse de Réserve (Cycle)</div>
                        <div class="fs-4 fw-bold text-success"><?= format_fcfa($solde_caisse_cyc) ?></div>
                        <small class="text-muted">Propre à <?= e($cycle_choisi['titre']) ?></small>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- BANNIÈRE D'ACTION SI LE CYCLE EST EN BROUILLON OU SI LE TIRAGE N'EST PAS ENCORE FAIT -->
        <?php if ($cycle_choisi['statut'] === 'brouillon'): ?>
            <div class="card card-accent-djangui p-4 mb-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <span class="badge bg-secondary mb-2">CYCLE EN BROUILLON</span>
                        <h4 class="fw-bold mb-1"><?= e($cycle_choisi['titre']) ?></h4>
                        <p class="text-muted small mb-0">
                            Début prévu le <?= date('d/m/Y', strtotime($cycle_choisi['date_debut'])) ?>.
                            Activez ce cycle pour inscrire les membres actifs, ou supprimez ce brouillon.
                        </p>
                    </div>
                    <?php if ($est_admin): ?>
                        <div class="d-flex gap-2">
                            <form method="POST" action="cycles.php">
                                <?= champ_csrf() ?>
                                <input type="hidden" name="action" value="activer_cycle">
                                <input type="hidden" name="cycle_id" value="<?= (int)$cycle_choisi['id'] ?>">
                                <button type="submit" class="btn btn-glow-emerald">
                                    <i class="bi bi-play-circle-fill me-1"></i>Activer ce cycle
                                </button>
                            </form>
                            <form method="POST" action="cycles.php" onsubmit="return confirm('Supprimer définitivement ce cycle brouillon ?');">
                                <?= champ_csrf() ?>
                                <input type="hidden" name="action" value="supprimer_brouillon">
                                <input type="hidden" name="cycle_id" value="<?= (int)$cycle_choisi['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="bi bi-trash3 me-1"></i>Supprimer le brouillon
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ((int)$cycle_choisi['tirage_verrouille'] === 0): ?>
            <div class="card card-accent-djangui p-4 mb-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <span class="badge bg-primary mb-2">ÉTAPE SUIVANTE : TIRAGE AU SORT</span>
                        <h4 class="fw-bold mb-1">Attribuer l'ordre de passage des bénéficiaires</h4>
                        <p class="text-muted small mb-0">
                            Le tirage au sort aléatoire verrouillera définitivement le rang de chaque membre (1, 2, 3...) et générera toutes les séances du cycle.
                        </p>
                    </div>
                    <?php if ($est_admin): ?>
                        <form method="POST" action="cycles.php" onsubmit="return confirm('Lancer et verrouiller définitivement le tirage au sort de ce cycle ?');">
                            <?= champ_csrf() ?>
                            <input type="hidden" name="action" value="lancer_tirage">
                            <input type="hidden" name="cycle_id" value="<?= (int)$cycle_choisi['id'] ?>">
                            <button type="submit" class="btn btn-glow-blue btn-lg">
                                <i class="bi bi-shuffle me-2"></i>Lancer le tirage au sort aléatoire
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- TABLEAU CHRONOLOGIQUE DES SÉANCES -->
        <?php if (!empty($seances_bilan)): ?>
            <div class="card card-djangui p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-0">
                            <i class="bi bi-calendar3-range text-primary me-2"></i>Calendrier des Séances & Ordre des Bénéficiaires — <?= e($cycle_choisi['titre']) ?>
                        </h5>
                        <p class="text-muted small mb-0">
                            Séance actuellement ouverte aux cotisations : <strong>Séance #<?= $seance_max_ouverte ?></strong>
                            <?php if ($cycle_choisi['statut'] === 'termine'): ?>
                                · <span class="badge bg-success">Cycle clôturé automatiquement</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-djangui align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Séance</th>
                                <th>Période de la séance</th>
                                <th>Bénéficiaire (Bouffeur)</th>
                                <?php if ($est_admin): ?>
                                    <th class="text-end">Pot collecté / Attendu</th>
                                <?php else: ?>
                                    <th class="text-end">Montant du Pot</th>
                                <?php endif; ?>
                                <th>Statut</th>
                                <?php if ($est_admin): ?>
                                    <th class="text-end no-print">Détail cotisants</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($seances_bilan as $sb): ?>
                                <?php
                                    $num_s      = (int)$sb['numero_seance'];
                                    $per_s      = formater_periode_seance($cycle_choisi['date_debut'], $num_s, $cycle_choisi['frequence'], $sb['date_echeance']);
                                    $est_act    = ($cycle_choisi['statut'] === 'actif' && $num_s === $seance_max_ouverte);
                                    $est_fermee = ($cycle_choisi['statut'] === 'actif' && $num_s > $seance_max_ouverte);
                                    $pot_ok     = ((int)$sb['nb_payes'] >= (int)$sb['nb_cotisations'] || (float)$sb['total_seance_paye'] + 0.01 >= (float)$sb['montant_pot_attendu'] || (int)$sb['pot_totalement_recu'] === 1);
                                ?>
                                <tr class="<?= $est_act ? 'row-highlight-djangui' : '' ?>">
                                    <td class="fw-bold">
                                        #<?= $num_s ?>
                                        <?php if ($est_act): ?>
                                            <span class="badge bg-primary ms-1">Actuelle</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small fw-semibold"><?= e($per_s) ?></td>
                                    <td>
                                        <div class="fw-bold">
                                            <?= e($sb['nom_bouffeur']) ?>
                                            <?php if ((int)$sb['bouffeur_user_id'] === $user_id): ?>
                                                <span class="badge bg-success-subtle text-success ms-1">Mon tour</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?= strtoupper(e($sb['canal_paiement'])) ?> : <?= e($sb['telephone_paiement']) ?></small>
                                    </td>

                                    <?php if ($est_admin): ?>
                                        <?php
                                            $ret_s = obtenir_retenue_sur_pot($pdo, (int)$cycle_choisi['id'], (int)$sb['bouffeur_user_id'], $num_s);
                                            $net_s = max(0.0, (float)$sb['total_seance_paye'] - $ret_s);
                                        ?>
                                        <td class="text-end">
                                            <strong class="text-success"><?= format_fcfa($sb['total_seance_paye']) ?></strong>
                                            <div class="small text-muted">sur <?= format_fcfa($sb['montant_pot_attendu']) ?> (<?= (int)$sb['nb_payes'] ?>/<?= (int)$sb['nb_cotisations'] ?>)</div>
                                            <?php if ($ret_s > 0): ?>
                                                <div class="small text-warning-emphasis fw-semibold">
                                                    Retenue : -<?= format_fcfa($ret_s) ?> (Net : <?= format_fcfa($net_s) ?>)
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <!-- Point 1 & Point 3 : Le membre voit le montant du pot du tour mais pas le détail des versements des autres -->
                                        <td class="text-end fw-bold text-primary">
                                            <?= format_fcfa($sb['montant_pot_attendu']) ?>
                                        </td>
                                    <?php endif; ?>

                                    <td>
                                        <?php if ($pot_ok): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Pot complet</span>
                                        <?php elseif ($est_fermee): ?>
                                            <span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>Verrouillée</span>
                                        <?php elseif ($est_act): ?>
                                            <span class="badge bg-primary">Séance en cours</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">En régularisation</span>
                                        <?php endif; ?>
                                    </td>

                                    <?php if ($est_admin): ?>
                                        <td class="text-end no-print">
                                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" type="button" data-bs-toggle="collapse" data-bs-target="#detailSeance<?= $num_s ?>">
                                                <i class="bi bi-eye me-1"></i>Voir les cotisants
                                            </button>
                                        </td>
                                    <?php endif; ?>
                                </tr>

                                <!-- Sous-tableau dépliable (STRICTEMENT RÉSERVÉ À L'ADMIN — Point 1) -->
                                <?php if ($est_admin): ?>
                                <tr class="collapse no-print" id="detailSeance<?= $num_s ?>">
                                    <td colspan="6" class="p-3 box-adaptive">
                                        <div class="small fw-bold text-primary mb-2">
                                            Détail des cotisations — Séance #<?= $num_s ?> (Bouffeur : <?= e($sb['nom_bouffeur']) ?>)
                                        </div>
                                        <div class="row g-2">
                                            <?php foreach ($cotisants_par_seance[$num_s] ?? [] as $dc): ?>
                                                <div class="col-md-4 col-lg-3">
                                                    <div class="p-2 rounded-3 surface-chip-djangui d-flex justify-content-between align-items-center small">
                                                        <div class="text-truncate me-2">
                                                            <div class="fw-semibold text-truncate"><?= e($dc['nom_complet']) ?></div>
                                                            <div class="text-muted" style="font-size:0.72rem;"><?= format_fcfa($dc['montant_paye']) ?> / <?= format_fcfa($dc['montant_attendu']) ?></div>
                                                        </div>
                                                        <?php if ((int)$dc['est_bouffeur'] === 1 && $dc['statut'] === 'paye'): ?>
                                                            <span class="badge bg-info text-dark">Sur pot</span>
                                                        <?php elseif ($dc['statut'] === 'paye'): ?>
                                                            <span class="badge bg-success">Payé</span>
                                                        <?php elseif ($dc['statut'] === 'en_retard'): ?>
                                                            <span class="badge bg-danger">Retard</span>
                                                        <?php elseif ($dc['statut'] === 'partiel'): ?>
                                                            <span class="badge bg-warning text-dark">Partiel</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Attente</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($est_admin): ?>
                        <tfoot class="tfoot-djangui fw-bold">
                            <tr>
                                <td colspan="3">TOTAL DU CYCLE</td>
                                <td class="text-end text-success"><?= format_fcfa($total_collecte_cycle) ?> / <?= format_fcfa($total_attendu_cycle) ?></td>
                                <td colspan="2">Reliquat : <?= format_fcfa($total_reliquat_cycle) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<!-- MODALE ADMIN : CRÉER UN NOUVEAU CYCLE (AVEC CHOIX DES MEMBRES PARTICIPANTS) -->
<?php if ($est_admin): ?>
<div class="modal fade" id="modalNouveauCycle" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content card-djangui">
            <form method="POST" action="cycles.php">
                <?= champ_csrf() ?>
                <input type="hidden" name="action" value="creer_cycle">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2 text-success"></i>Nouveau cycle de cotisation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Titre du cycle *</label>
                        <input type="text" name="titre" class="form-control" placeholder="Ex: Cycle 3 - Hiver 2027" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">Cotisation par séance (FCFA) *</label>
                            <input type="number" name="montant_cotisation" id="inputMontantCotisCycle" class="form-control" min="100" step="any" value="50000" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">Fréquence des séances *</label>
                            <select name="frequence" class="form-select">
                                <option value="mensuel">Mensuelle (1 mois)</option>
                                <option value="bimensuel">Bimensuelle (15 jours)</option>
                                <option value="hebdo">Hebdomadaire (7 jours)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">Pénalité cotisation partielle (%) *</label>
                            <div class="input-group">
                                <input type="number" name="pourcentage_penalite" id="inputPctPenCycle" class="form-control fw-bold" min="0" max="50" step="0.5" value="5" required>
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">Date de la 1re séance *</label>
                            <input type="date" name="date_debut" class="form-control" value="<?= date_actuelle('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- SÉLECTION DES MEMBRES QUI PARTICIPERONT À CE CYCLE -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label small fw-bold mb-0">
                                <i class="bi bi-people-fill text-primary me-1"></i>Membres participants à ce cycle (<span id="nbMembresCoches"><?= count($membres_actifs_tontine) ?></span> sélectionné(s) — Pot par tour : <span id="apercuPotCycle" class="text-success">0 FCFA</span>) *
                            </label>
                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnToggleTousMembres">
                                Tout cocher / décocher
                            </button>
                        </div>

                        <div class="p-3 rounded-3 box-adaptive" style="max-height: 190px; overflow-y: auto;">
                            <?php if (empty($membres_actifs_tontine)): ?>
                                <p class="text-danger small mb-0">Aucun membre actif trouvé dans la tontine.</p>
                            <?php else: ?>
                                <div class="row g-2">
                                    <?php foreach ($membres_actifs_tontine as $mat): ?>
                                        <div class="col-md-6">
                                            <label class="form-check p-2 ps-4 rounded-2 surface-chip-djangui d-flex align-items-center gap-2 small w-100 m-0" style="cursor: pointer;">
                                                <input class="form-check-input js-check-membre-cycle m-0" type="checkbox" name="membres_choisis[]" value="<?= (int)$mat['id'] ?>" checked>
                                                <span class="text-truncate">
                                                    <strong><?= e($mat['nom_complet']) ?></strong>
                                                    <span class="text-muted">(<?= $mat['role'] === 'admin' ? 'Admin' : e($mat['telephone']) ?>)</span>
                                                </span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- MENTION EXPLICATIVE GUIDÉE -->
                    <div class="p-3 rounded-3 box-adaptive small border-start border-4 border-warning">
                        <div class="fw-bold text-primary mb-1">
                            <i class="bi bi-info-circle-fill me-1"></i>Règle automatique des 2 types de pénalités :
                        </div>
                        <ul class="mb-0 ps-3">
                            <li>
                                <strong>Retard avec cotisation partielle :</strong> applique le taux saisi (<span id="apercuPctPartiel" class="fw-bold">5 %</span> = <span id="apercuMontantPartiel" class="fw-bold text-warning">2 500 FCFA</span>).
                            </li>
                            <li>
                                <strong>Retard pour non-cotisation (0 FCFA versé) :</strong> applique automatiquement <strong>le double</strong> (<span id="apercuPctDouble" class="fw-bold">10 %</span> = <span id="apercuMontantDouble" class="fw-bold text-danger">5 000 FCFA</span>).
                            </li>
                            <li class="mt-1 text-muted">
                                <strong>Cumul par séance :</strong> si un retard ou une pénalité n'est toujours pas réglé à la fin de la séance suivante, une majoration égale à la pénalité partielle (+<span id="apercuMajoration">2 500 FCFA</span>) s'ajoute à chaque nouvelle séance écoulée.
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-glow-emerald">Créer le cycle</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const inpMontant = document.getElementById('inputMontantCotisCycle');
    const inpPct     = document.getElementById('inputPctPenCycle');
    const checks     = document.querySelectorAll('.js-check-membre-cycle');
    const btnToggle  = document.getElementById('btnToggleTousMembres');
    if (!inpMontant || !inpPct) return;

    function majApercuCycle() {
        const m   = parseFloat(inpMontant.value) || 0;
        const pct = parseFloat(inpPct.value) || 0;
        const p1  = Math.round((m * pct) / 100);
        const p2  = p1 * 2;

        let nbCoches = 0;
        checks.forEach(function (c) { if (c.checked) nbCoches++; });
        const potTotal = Math.round(m * nbCoches);

        document.getElementById('nbMembresCoches').textContent      = nbCoches;
        document.getElementById('apercuPotCycle').textContent       = potTotal.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('apercuPctPartiel').textContent     = pct + ' %';
        document.getElementById('apercuMontantPartiel').textContent = p1.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('apercuPctDouble').textContent      = (pct * 2) + ' %';
        document.getElementById('apercuMontantDouble').textContent  = p2.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('apercuMajoration').textContent     = p1.toLocaleString('fr-FR') + ' FCFA';
    }

    inpMontant.addEventListener('input', majApercuCycle);
    inpPct.addEventListener('input', majApercuCycle);
    checks.forEach(function (c) { c.addEventListener('change', majApercuCycle); });

    if (btnToggle) {
        btnToggle.addEventListener('click', function () {
            const tousCoches = Array.from(checks).every(function (c) { return c.checked; });
            checks.forEach(function (c) { c.checked = !tousCoches; });
            majApercuCycle();
        });
    }

    majApercuCycle();
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
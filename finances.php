<?php
// finances.php — ÉCRAN 3 UNIFIÉ :
// - Point 6 : Sélecteur de cycle -> Caisse et Pénalités propres au cycle sélectionné uniquement
// - Point 2 : Un simple membre n'a pas accès à la gestion de la caisse ni aux pénalités des autres (il voit uniquement "Mes Pénalités")
// - Point 3 : Le membre voit quand même le Solde Caisse de réserve du cycle
// - Point 7 : Mention claire Pénalité cotisation partielle (%) vs Pénalité non-cotisation (2x %)
// - Point 9 : Traits et bandeaux de couleur séparant visuellement chaque séance dans le tableau Caisse & Pénalités
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

verifier_connexion();

$tontine_id   = (int)$_SESSION['tontine_id'];
$user_id      = (int)$_SESSION['user_id'];
$est_admin    = (($_SESSION['role'] ?? '') === 'admin');
$onglet_actif = $_GET['tab'] ?? 'cotiser';

// 1. LISTE DES CYCLES (HORS BROUILLON) & CYCLE SÉLECTIONNÉ (POINT 6)
$stmtCycles = $pdo->prepare("
    SELECT * FROM cycles
    WHERE tontine_id = ? AND statut != 'brouillon'
    ORDER BY (statut = 'actif') DESC, numero_cycle DESC
");
$stmtCycles->execute([$tontine_id]);
$cycles_dispo = $stmtCycles->fetchAll();

$cycle_id_req = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : (isset($_POST['cycle_id']) ? (int)$_POST['cycle_id'] : 0);
$cycle        = null;

foreach ($cycles_dispo as $cd) {
    if ($cycle_id_req > 0 && (int)$cd['id'] === $cycle_id_req) {
        $cycle = $cd;
        break;
    }
}
if (!$cycle && !empty($cycles_dispo)) {
    $cycle = $cycles_dispo[0];
}

$cid                  = $cycle ? (int)$cycle['id'] : 0;
$cycle_est_actif      = ($cycle && $cycle['statut'] === 'actif');
$mon_cycle_member     = null;
$seance_max_ouverte   = 1;

if ($cycle) {
    $seance_max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);
    $stmtCM = $pdo->prepare("SELECT * FROM cycle_members WHERE cycle_id = ? AND user_id = ? LIMIT 1");
    $stmtCM->execute([$cid, $user_id]);
    $mon_cycle_member = $stmtCM->fetch();
}

$montant_cotis_cycle  = $cycle ? (float)$cycle['montant_cotisation'] : 0.0;
$penalite_partielle   = $cycle ? (float)($cycle['montant_penalite_retard'] ?? 0) : 0.0;
$penalite_non_cotis   = round($penalite_partielle * 2, 2);
$pourcentage_partiel  = ($cycle && $montant_cotis_cycle > 0) ? round(($penalite_partielle / $montant_cotis_cycle) * 100, 1) : 0.0;
$pourcentage_double   = round($pourcentage_partiel * 2, 1);

// 2. TRAITEMENT DES ACTIONS POST (PAIEMENT COTISATION, RÈGLEMENT PÉNALITÉ MEMBRE, CAISSE ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cycle) {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    // ACTION A : Payer une cotisation avec choix du montant exact
    if ($action === 'payer_cotisation' && $cycle && $mon_cycle_member) {
        $contribution_id = (int)($_POST['contribution_id'] ?? 0);
        $mode_paiement   = trim($_POST['mode_paiement'] ?? '');
        $montant_saisi   = filter_var($_POST['montant_saisi'] ?? null, FILTER_VALIDATE_FLOAT);

        if (!in_array($mode_paiement, ['momo', 'om', 'especes'], true)) {
            message_flash('danger', 'Mode de paiement invalide.');
            header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid);
            exit;
        }

        $stmtVerif = $pdo->prepare("
            SELECT c.*, cm_bouffeur.user_id AS bouffeur_user_id
            FROM contributions c
            JOIN cycle_members cm_payeur ON cm_payeur.id = c.cycle_member_id
            JOIN cycle_members cm_bouffeur ON cm_bouffeur.id = c.beneficiaire_cycle_member_id
            WHERE c.id = ? AND c.cycle_member_id = ? AND cm_payeur.cycle_id = ?
            LIMIT 1
        ");
        $stmtVerif->execute([$contribution_id, (int)$mon_cycle_member['id'], $cid]);
        $cotisation = $stmtVerif->fetch();

        if (!$cotisation) {
            message_flash('danger', 'Séance introuvable.');
            header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid);
            exit;
        }

        $num_seance = (int)$cotisation['numero_seance'];

        if ($num_seance > $seance_max_ouverte) {
            message_flash('danger', "Action refusée : la Séance #{$num_seance} n'est pas encore ouverte (la Séance #" . ($num_seance - 1) . " n'est pas clôturée).");
            header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid);
            exit;
        }

        if ((int)$cotisation['cycle_member_id'] === (int)$cotisation['beneficiaire_cycle_member_id']) {
            message_flash('info', "Vous êtes le bouffeur de la Séance #{$num_seance} : votre propre part est déjà compensée sur votre pot.");
            header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid);
            exit;
        }

        $reste_seance = max(0.0, (float)$cotisation['montant_attendu'] - (float)$cotisation['montant_paye']);

        $stmtDettesAnt = $pdo->prepare("
            SELECT id, beneficiaire_cycle_member_id, numero_seance, montant_attendu, montant_paye
            FROM contributions
            WHERE cycle_member_id = ?
              AND beneficiaire_cycle_member_id != ?
              AND numero_seance < ?
              AND montant_paye < montant_attendu
            ORDER BY numero_seance ASC
        ");
        $stmtDettesAnt->execute([(int)$mon_cycle_member['id'], (int)$mon_cycle_member['id'], $num_seance]);
        $dettes_ant_rows  = $stmtDettesAnt->fetchAll();
        $total_dettes_ant = 0.0;
        foreach ($dettes_ant_rows as $da) {
            $total_dettes_ant += max(0.0, (float)$da['montant_attendu'] - (float)$da['montant_paye']);
        }

        $stmtPenDues = $pdo->prepare("
            SELECT id, contribution_id, montant, motif
            FROM penalties
            WHERE cycle_member_id = ? AND type_operation = 'penalite' AND statut = 'en_attente'
            ORDER BY numero_seance ASC, id ASC
        ");
        $stmtPenDues->execute([(int)$mon_cycle_member['id']]);
        $penalites_rows = $stmtPenDues->fetchAll();
        $total_pen_dues = 0.0;
        foreach ($penalites_rows as $pr) {
            $total_pen_dues += (float)$pr['montant'];
        }

        $plafond_autorise = round($reste_seance + $total_dettes_ant + $total_pen_dues, 2);

        if ($montant_saisi === false || $montant_saisi <= 0) {
            message_flash('danger', 'Veuillez saisir un montant valide supérieur à 0 FCFA.');
            header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid);
            exit;
        }

        $montant_saisi = round((float)$montant_saisi, 2);
        if ($montant_saisi > $plafond_autorise + 0.01) {
            message_flash('danger', 'Le montant saisi (' . format_fcfa($montant_saisi) . ') dépasse le maximum autorisé (' . format_fcfa($plafond_autorise) . ').');
            header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $budget_restant = $montant_saisi;
            $aujourdhui     = date_actuelle('Y-m-d');

            // 1. Affecter sur la séance cliquée
            $affecte_seance = min($budget_restant, $reste_seance);
            $budget_restant = round($budget_restant - $affecte_seance, 2);

            $nouveau_paye_s   = round((float)$cotisation['montant_paye'] + $affecte_seance, 2);
            $est_en_retard_s  = (strtotime($cotisation['date_echeance']) < strtotime($aujourdhui)) ? 1 : 0;
            if ($nouveau_paye_s >= (float)$cotisation['montant_attendu']) {
                $nouveau_statut_s = 'paye';
            } elseif ($est_en_retard_s) {
                $nouveau_statut_s = 'en_retard';
            } else {
                $nouveau_statut_s = 'partiel';
            }

            if ($affecte_seance > 0) {
                $pdo->prepare("UPDATE contributions SET montant_paye = ?, statut = ? WHERE id = ?")
                    ->execute([$nouveau_paye_s, $nouveau_statut_s, $contribution_id]);

                $pdo->prepare("
                    UPDATE cycle_members
                    SET montant_pot_recu = montant_pot_recu + ?,
                        pot_totalement_recu = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu THEN 1 ELSE 0 END,
                        date_reception_pot = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu AND date_reception_pot IS NULL THEN NOW() ELSE date_reception_pot END
                    WHERE id = ?
                ")->execute([$affecte_seance, $affecte_seance, $affecte_seance, (int)$cotisation['beneficiaire_cycle_member_id']]);
            }

            // 2. Si le membre a saisi plus, éponger ses dettes des séances précédentes
            $total_dettes_eponges = 0.0;
            if ($budget_restant > 0 && !empty($dettes_ant_rows)) {
                foreach ($dettes_ant_rows as $da) {
                    if ($budget_restant <= 0) break;
                    $manque_da = max(0.0, (float)$da['montant_attendu'] - (float)$da['montant_paye']);
                    $vers_da   = min($budget_restant, $manque_da);
                    if ($vers_da > 0) {
                        $nv_paye_da   = round((float)$da['montant_paye'] + $vers_da, 2);
                        $nv_statut_da = ($nv_paye_da >= (float)$da['montant_attendu']) ? 'paye' : 'en_retard';
                        $pdo->prepare("UPDATE contributions SET montant_paye = ?, statut = ? WHERE id = ?")
                            ->execute([$nv_paye_da, $nv_statut_da, (int)$da['id']]);
                        $pdo->prepare("
                            UPDATE cycle_members
                            SET montant_pot_recu = montant_pot_recu + ?,
                                pot_totalement_recu = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu THEN 1 ELSE 0 END,
                                date_reception_pot = CASE WHEN (montant_pot_recu + ?) >= montant_pot_attendu AND date_reception_pot IS NULL THEN NOW() ELSE date_reception_pot END
                            WHERE id = ?
                        ")->execute([$vers_da, $vers_da, $vers_da, (int)$da['beneficiaire_cycle_member_id']]);

                        $ref_da = strtoupper($mode_paiement) . '-' . date_actuelle('ymd') . '-D' . random_int(100, 999);
                        $pdo->prepare("
                            INSERT INTO payments (
                                contribution_id, paye_par_user_id, montant_total,
                                part_beneficiaire, part_caisse_reserve, mode_paiement,
                                reference_paiement, date_paiement, est_en_retard,
                                statut_paiement, commentaire
                            ) VALUES (?, ?, ?, ?, 0.00, ?, ?, NOW(), 1, 'valide', ?)
                        ")->execute([
                            (int)$da['id'],
                            $user_id,
                            $vers_da,
                            $vers_da,
                            $mode_paiement,
                            $ref_da,
                            "Règlement dette antérieure Séance #{$da['numero_seance']}"
                        ]);
                        $budget_restant       = round($budget_restant - $vers_da, 2);
                        $total_dettes_eponges = round($total_dettes_eponges + $vers_da, 2);
                    }
                }
            }

            // 3. Si le membre a encore du surplus, régler ses pénalités en attente vers la Caisse DU CYCLE (Point 6)
            $part_caisse_reserve = 0.0;
            if ($budget_restant > 0 && !empty($penalites_rows)) {
                foreach ($penalites_rows as $pr) {
                    if ($budget_restant <= 0) break;
                    $m_pen    = (float)$pr['montant'];
                    $vers_pen = min($budget_restant, $m_pen);
                    if ($vers_pen > 0) {
                        if ($vers_pen >= $m_pen) {
                            $pdo->prepare("UPDATE penalties SET statut = 'regle', date_reglement = NOW() WHERE id = ?")
                                ->execute([(int)$pr['id']]);
                        } else {
                            $pdo->prepare("UPDATE penalties SET montant = ? WHERE id = ?")
                                ->execute([round($m_pen - $vers_pen, 2), (int)$pr['id']]);
                        }
                        $part_caisse_reserve = round($part_caisse_reserve + $vers_pen, 2);
                        $budget_restant      = round($budget_restant - $vers_pen, 2);
                    }
                }
                if ($part_caisse_reserve > 0) {
                    $pdo->prepare("UPDATE cycles SET solde_caisse_reserve = solde_caisse_reserve + ? WHERE id = ?")
                        ->execute([$part_caisse_reserve, $cid]);
                }
            }

            // 4. Reçu principal
            $montant_recu_principal = round($affecte_seance + $part_caisse_reserve, 2);
            if ($montant_recu_principal > 0) {
                $ref_p = strtoupper($mode_paiement) . '-' . date_actuelle('ymd') . '-' . random_int(1000, 9999);
                $comm  = ($nouveau_statut_s === 'paye')
                    ? "Règlement complet Séance #{$num_seance}"
                    : "Versement partiel Séance #{$num_seance} (" . format_fcfa($affecte_seance) . ")";
                if ($part_caisse_reserve > 0) {
                    $comm .= " + Pénalité (" . format_fcfa($part_caisse_reserve) . " -> Caisse du cycle)";
                }
                $pdo->prepare("
                    INSERT INTO payments (
                        contribution_id, paye_par_user_id, montant_total,
                        part_beneficiaire, part_caisse_reserve, mode_paiement,
                        reference_paiement, date_paiement, est_en_retard,
                        statut_paiement, commentaire
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'valide', ?)
                ")->execute([
                    $contribution_id,
                    $user_id,
                    $montant_recu_principal,
                    $affecte_seance,
                    $part_caisse_reserve,
                    $mode_paiement,
                    $ref_p,
                    $est_en_retard_s,
                    $comm
                ]);
            }

            $pdo->commit();
            // Point 4 : Vérifier immédiatement si le cycle doit se clôturer automatiquement
            synchroniser_horloge_et_retards($pdo);
            message_flash('success', 'Paiement de ' . format_fcfa($montant_saisi) . ' validé avec succès !');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            message_flash('danger', 'Erreur lors de l\'enregistrement du paiement.');
        }

        header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid . '&tab=cotiser');
        exit;
    }

    // ACTION B : Un membre règle sa propre pénalité en attente -> Crédite la caisse du cycle + Génère un reçu officiel dans payments
    if ($action === 'regler_ma_penalite' && $mon_cycle_member) {
        $penalty_id = (int)($_POST['penalty_id'] ?? 0);
        $stmtP = $pdo->prepare("
            SELECT * FROM penalties
            WHERE id = ? AND cycle_member_id = ? AND type_operation = 'penalite' AND statut = 'en_attente'
            LIMIT 1
        ");
        $stmtP->execute([$penalty_id, (int)$mon_cycle_member['id']]);
        $pen = $stmtP->fetch();

        if ($pen) {
            $pdo->beginTransaction();

            // 1. Marquer la pénalité comme réglée et créditer la caisse du cycle
            $pdo->prepare("UPDATE penalties SET statut = 'regle', date_reglement = NOW() WHERE id = ?")
                ->execute([$penalty_id]);
            $pdo->prepare("UPDATE cycles SET solde_caisse_reserve = solde_caisse_reserve + ? WHERE id = ?")
                ->execute([(float)$pen['montant'], $cid]);

            // 2. Identifier la cotisation de rattachement pour générer le reçu dans payments
            $contrib_id_pen = !empty($pen['contribution_id']) ? (int)$pen['contribution_id'] : 0;
            if ($contrib_id_pen <= 0) {
                $stmtFindCo = $pdo->prepare("
                    SELECT id FROM contributions
                    WHERE cycle_member_id = ?
                    ORDER BY (numero_seance = ?) DESC, numero_seance ASC
                    LIMIT 1
                ");
                $stmtFindCo->execute([(int)$mon_cycle_member['id'], (int)$pen['numero_seance']]);
                $contrib_id_pen = (int)$stmtFindCo->fetchColumn();
            }

            if ($contrib_id_pen > 0) {
                $stmtCanal = $pdo->prepare("SELECT canal_paiement FROM users WHERE id = ? LIMIT 1");
                $stmtCanal->execute([$user_id]);
                $canal_usr = $stmtCanal->fetchColumn() ?: 'momo';

                $ref_pen = 'PEN-S' . (int)$pen['numero_seance'] . '-' . date_actuelle('ymd') . '-' . random_int(100, 999);
                $pdo->prepare("
                    INSERT INTO payments (
                        contribution_id, paye_par_user_id, montant_total,
                        part_beneficiaire, part_caisse_reserve, mode_paiement,
                        reference_paiement, date_paiement, est_en_retard,
                        statut_paiement, commentaire
                    ) VALUES (?, ?, ?, 0.00, ?, ?, ?, NOW(), 1, 'valide', ?)
                ")->execute([
                    $contrib_id_pen,
                    $user_id,
                    (float)$pen['montant'],
                    (float)$pen['montant'],
                    $canal_usr,
                    $ref_pen,
                    'Règlement pénalité -> Caisse du cycle (' . $pen['motif'] . ')'
                ]);
            }

            $pdo->commit();

            // Vérifier si toutes les pénalités et cotisations sont réglées après la dernière séance pour clôturer le cycle
            synchroniser_horloge_et_retards($pdo);
            message_flash('success', 'Votre pénalité de ' . format_fcfa($pen['montant']) . ' a été réglée ! Un reçu a été ajouté dans « Mes Reçus ».');
        }
        header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid . '&tab=caisse');
        exit;
    }

    // ACTION C (ADMIN) : Modifier le % de pénalité partielle du cycle (la non-cotisation vaut automatiquement le double)
    if ($est_admin && $action === 'modifier_taux_penalite') {
        $nouveau_pct = (float)($_POST['pourcentage_penalite'] ?? -1);
        if ($nouveau_pct >= 0 && $nouveau_pct <= 50) {
            $nouveau_montant = round(($montant_cotis_cycle * $nouveau_pct) / 100, 2);
            $pdo->prepare("UPDATE cycles SET montant_penalite_retard = ? WHERE id = ?")
                ->execute([$nouveau_montant, $cid]);
            if ($nouveau_montant > 0) {
                $pdo->prepare("
                    UPDATE penalties p
                    INNER JOIN cycle_members cm ON cm.id = p.cycle_member_id
                    SET p.montant = CASE WHEN p.motif LIKE '%taux double%' THEN ? ELSE ? END
                    WHERE cm.cycle_id = ? AND p.type_operation = 'penalite' AND p.statut = 'en_attente'
                ")->execute([round($nouveau_montant * 2, 2), $nouveau_montant, $cid]);
            } else {
                $pdo->prepare("
                    UPDATE penalties p
                    INNER JOIN cycle_members cm ON cm.id = p.cycle_member_id
                    SET p.statut = 'annule'
                    WHERE cm.cycle_id = ? AND p.type_operation = 'penalite' AND p.statut = 'en_attente'
                ")->execute([$cid]);
            }
            message_flash('success', "Taux de pénalité mis à jour : {$nouveau_pct} % (" . format_fcfa($nouveau_montant) . ") pour cotisation partielle et " . ($nouveau_pct * 2) . " % (" . format_fcfa($nouveau_montant * 2) . ") pour non-cotisation.");
        }
        header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid . '&tab=caisse');
        exit;
    }

    // ACTION D (ADMIN) : Ajouter un bonus ou une pénalité manuelle sur une séance du cycle
    if ($est_admin && $action === 'creer_operation') {
        $cm_cible       = (int)($_POST['cycle_member_id'] ?? 0);
        $num_seance_op  = max(1, (int)($_POST['numero_seance'] ?? $seance_max_ouverte));
        $type_op        = $_POST['type_operation'] ?? '';
        $montant_op     = (float)($_POST['montant'] ?? 0);
        $motif_op       = trim($_POST['motif'] ?? '');

        if ($cm_cible > 0 && in_array($type_op, ['penalite', 'bonus'], true) && $montant_op > 0 && $motif_op !== '') {
            $statut_op   = ($type_op === 'bonus') ? 'regle' : 'en_attente';
            $date_reg_op = ($type_op === 'bonus') ? date_actuelle('Y-m-d H:i:s') : null;
            $motif_complet = str_starts_with($motif_op, 'Séance #') ? $motif_op : "Séance #{$num_seance_op} : {$motif_op}";

            // Les pénalités manuelles gardent contribution_id = NULL pour ne jamais interférer avec la détection automatique des retards de cotisation
            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO penalties (cycle_member_id, contribution_id, numero_seance, type_operation, montant, motif, statut, date_operation, date_reglement, auteur_id)
                VALUES (?, NULL, ?, ?, ?, ?, ?, NOW(), ?, ?)
            ")->execute([$cm_cible, $num_seance_op, $type_op, $montant_op, $motif_complet, $statut_op, $date_reg_op, $user_id]);

            if ($type_op === 'bonus') {
                $pdo->prepare("UPDATE cycles SET solde_caisse_reserve = solde_caisse_reserve + ? WHERE id = ?")
                    ->execute([$montant_op, $cid]);
            }
            $pdo->commit();
            message_flash('success', $type_op === 'bonus' ? 'Bonus encaissé dans la caisse de ce cycle.' : 'Pénalité enregistrée (en attente du règlement par le membre).');
        }
        header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid . '&tab=caisse');
        exit;
    }

    // ACTION E (ADMIN) : Supprimer une pénalité en attente
    // On passe statut = 'annule' au lieu d'un DELETE physique pour empêcher le moteur automatique de la régénérer !
    if ($est_admin && $action === 'supprimer_operation') {
        $penalty_id = (int)($_POST['penalty_id'] ?? 0);
        $stmtDel = $pdo->prepare("
            UPDATE penalties p
            INNER JOIN cycle_members cm ON cm.id = p.cycle_member_id
            SET p.statut = 'annule'
            WHERE p.id = ? AND cm.cycle_id = ? AND p.statut = 'en_attente'
        ");
        $stmtDel->execute([$penalty_id, $cid]);
        if ($stmtDel->rowCount() > 0) {
            synchroniser_horloge_et_retards($pdo);
            message_flash('warning', 'La pénalité a été définitivement supprimée.');
        }
        header('Location: ' . BASE_URL . '/finances.php?cycle_id=' . $cid . '&tab=caisse');
        exit;
    }
}

// Recharger le cycle après les éventuelles mises à jour (ex: clôture auto ou mise à jour du solde caisse)
if ($cid > 0) {
    $stmtReload = $pdo->prepare("SELECT * FROM cycles WHERE id = ? LIMIT 1");
    $stmtReload->execute([$cid]);
    $cycle = $stmtReload->fetch();
}

// 3. LECTURE DES DONNÉES POUR LES ONGLETS DU CYCLE SÉLECTIONNÉ
$mes_seances        = [];
$mes_paiements      = [];
$mes_penalites_dues = 0.0;
$mon_reste_ouvert   = 0.0;
$operations_caisse  = [];
$toutes_cotisations = [];
$membres_cycle_sel  = [];

if ($cycle && $mon_cycle_member) {
    $stmtMesS = $pdo->prepare("
        SELECT c.id AS contribution_id, c.cycle_member_id, c.beneficiaire_cycle_member_id,
               c.numero_seance, c.date_echeance, c.montant_attendu, c.montant_paye, c.statut AS statut_contrib,
               u_ben.nom_complet AS nom_bouffeur, u_ben.canal_paiement AS canal_bouffeur, u_ben.telephone_paiement AS tel_bouffeur
        FROM contributions c
        JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
        JOIN users u_ben ON u_ben.id = cm_ben.user_id
        WHERE c.cycle_member_id = ?
        ORDER BY c.numero_seance ASC
    ");
    $stmtMesS->execute([(int)$mon_cycle_member['id']]);
    $mes_seances = $stmtMesS->fetchAll();

    foreach ($mes_seances as $ms) {
        $est_mon_t = ((int)$ms['cycle_member_id'] === (int)$ms['beneficiaire_cycle_member_id']);
        if (!$est_mon_t && (int)$ms['numero_seance'] <= $seance_max_ouverte) {
            $mon_reste_ouvert += max(0.0, (float)$ms['montant_attendu'] - (float)$ms['montant_paye']);
        }
    }

    $stmtPenSum = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0)
        FROM penalties
        WHERE cycle_member_id = ? AND type_operation = 'penalite' AND statut = 'en_attente'
    ");
    $stmtPenSum->execute([(int)$mon_cycle_member['id']]);
    $mes_penalites_dues = (float)$stmtPenSum->fetchColumn();

    $stmtRecus = $pdo->prepare("
        SELECT p.*, c.numero_seance, u_ben.nom_complet AS nom_bouffeur
        FROM payments p
        JOIN contributions c ON c.id = p.contribution_id
        JOIN cycle_members cm_payeur ON cm_payeur.id = c.cycle_member_id
        JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
        JOIN users u_ben ON u_ben.id = cm_ben.user_id
        WHERE p.paye_par_user_id = ? AND cm_payeur.cycle_id = ?
        ORDER BY p.date_paiement DESC, p.id DESC
    ");
    $stmtRecus->execute([$user_id, $cid]);
    $mes_paiements = $stmtRecus->fetchAll();
}

// Point 2 & Point 6 :
// - Si Admin : voit toutes les pénalités et bonus du cycle sélectionné
// - Si Membre : ne voit QUE ses propres pénalités sur ce cycle (aucun accès à la caisse ni aux pénalités des autres)
if ($cycle) {
    if ($est_admin) {
        $stmtOp = $pdo->prepare("
            SELECT p.*, u.id AS membre_user_id, u.nom_complet
            FROM penalties p
            JOIN cycle_members cm ON cm.id = p.cycle_member_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ? AND p.statut != 'annule'
            ORDER BY p.numero_seance ASC, p.date_operation ASC, p.id ASC
        ");
        $stmtOp->execute([$cid]);
        $operations_caisse = $stmtOp->fetchAll();

        $stmtReg = $pdo->prepare("
            SELECT c.*, u_mem.nom_complet AS mem_nom, u_ben.nom_complet AS ben_nom,
                   (SELECT COALESCE(SUM(p.part_caisse_reserve), 0) FROM payments p WHERE p.contribution_id = c.id) AS part_caisse_reserve
            FROM contributions c
            JOIN cycle_members cm_mem ON cm_mem.id = c.cycle_member_id
            JOIN users u_mem ON u_mem.id = cm_mem.user_id
            JOIN cycle_members cm_ben ON cm_ben.id = c.beneficiaire_cycle_member_id
            JOIN users u_ben ON u_ben.id = cm_ben.user_id
            WHERE cm_mem.cycle_id = ?
            ORDER BY c.numero_seance ASC, (c.statut = 'paye') DESC, u_mem.nom_complet ASC
        ");
        $stmtReg->execute([$cid]);
        $toutes_cotisations = $stmtReg->fetchAll();

        $stmtMC = $pdo->prepare("
            SELECT cm.id, u.nom_complet, u.code_public
            FROM cycle_members cm
            JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ?
            ORDER BY u.nom_complet ASC
        ");
        $stmtMC->execute([$cid]);
        $membres_cycle_sel = $stmtMC->fetchAll();
    } elseif ($mon_cycle_member) {
        $stmtOp = $pdo->prepare("
            SELECT p.*, u.id AS membre_user_id, u.nom_complet
            FROM penalties p
            JOIN cycle_members cm ON cm.id = p.cycle_member_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ? AND cm.user_id = ? AND p.type_operation = 'penalite' AND p.statut != 'annule'
            ORDER BY p.numero_seance ASC, p.date_operation ASC, p.id ASC
        ");
        $stmtOp->execute([$cid, $user_id]);
        $operations_caisse = $stmtOp->fetchAll();
    }
}

$titre_page = $est_admin ? "Cotisations, Reçus & Caisse du Cycle" : "Mes Cotisations, Reçus & Pénalités";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="container-fluid px-3 px-lg-4 py-4">

    <!-- EN-TÊTE + SÉLECTEUR DE CYCLE (POINT 6 : CHAQUE CYCLE A SA CAISSE ET SES PÉNALITÉS) -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">
                <i class="bi bi-wallet2 text-primary me-2"></i><?= $est_admin ? 'Cotisations, Reçus & Caisse du Cycle' : 'Mes Cotisations, Reçus & Pénalités' ?>
            </h1>
            <p class="text-muted small mb-0">
                <?= $cycle ? 'Cycle affiché : <strong>' . e($cycle['titre']) . '</strong> (' . strtoupper($cycle['statut']) . ')' : 'Aucun cycle actif' ?>
            </p>
        </div>

        <?php if (!empty($cycles_dispo)): ?>
            <form method="GET" action="finances.php" class="d-flex align-items-center gap-2 m-0">
                <input type="hidden" name="tab" value="<?= e($onglet_actif) ?>">
                <label class="small text-muted fw-semibold text-nowrap">Cycle :</label>
                <select name="cycle_id" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()" style="min-width: 240px;">
                    <?php foreach ($cycles_dispo as $cd): ?>
                        <option value="<?= (int)$cd['id'] ?>" <?= ($cycle && (int)$cd['id'] === $cid) ? 'selected' : '' ?>>
                            <?= e($cd['titre']) ?> — Caisse : <?= format_fcfa($cd['solde_caisse_reserve']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <!-- 1. BANDEAU DES 3 CARTES KPI -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-djangui h-100 p-4 border-left-navy">
                <span class="text-muted small fw-semibold">Mon Reste à Cotiser (Séances ouvertes)</span>
                <h3 class="fw-bold text-primary mt-1 mb-1"><?= format_fcfa($mon_reste_ouvert) ?></h3>
                <span class="small text-muted">
                    <?= $cycle ? 'Jusqu\'à la Séance #' . $seance_max_ouverte : 'Aucun cycle actif' ?>
                </span>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Point 3 & Point 6 : Tout le monde voit le Solde Caisse de Réserve du cycle sélectionné -->
            <div class="card card-djangui h-100 p-4 border-left-emerald">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small fw-semibold">Solde Caisse de Réserve (ce cycle)</span>
                        <h3 class="fw-bold text-success mt-1 mb-1"><?= format_fcfa($cycle['solde_caisse_reserve'] ?? 0) ?></h3>
                        <span class="small text-muted d-block">
                            Partielle : <strong><?= $pourcentage_partiel ?> %</strong> (<?= format_fcfa($penalite_partielle) ?>) · Non-cotisation : <strong><?= $pourcentage_double ?> %</strong> (<?= format_fcfa($penalite_non_cotis) ?>)
                        </span>
                    </div>
                    <?php if ($est_admin && $cycle): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalCaisseAdmin">
                            <i class="bi bi-sliders me-1"></i>Gérer
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card card-djangui h-100 p-4 border-left-<?= $mes_penalites_dues > 0 ? 'danger' : 'warning' ?>">
                <span class="text-muted small fw-semibold">Mes Pénalités en Attente (ce cycle)</span>
                <h3 class="fw-bold <?= $mes_penalites_dues > 0 ? 'text-danger' : 'text-success' ?> mt-1 mb-1">
                    <?= format_fcfa($mes_penalites_dues) ?>
                </h3>
                <span class="small text-muted">
                    <?= $mes_penalites_dues > 0 ? 'Augmente de +' . format_fcfa($penalite_partielle) . ' à chaque séance non soldée' : 'Aucune pénalité impayée sur ce cycle' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- 2. BARRE D'ONGLETS FLUIDES -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <ul class="nav nav-pills-djangui" id="financesTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $onglet_actif === 'cotiser' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tabCotiser" type="button">
                    <i class="bi bi-credit-card-2-front me-1"></i>1. Mes Séances à Cotiser
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $onglet_actif === 'recus' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tabRecus" type="button">
                    <i class="bi bi-receipt me-1"></i>2. Mes Reçus (<?= count($mes_paiements) ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <!-- Point 2 : Pour un simple membre, l'onglet s'appelle "Mes Pénalités" et ne contient que ses propres pénalités -->
                <button class="nav-link <?= $onglet_actif === 'caisse' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tabCaisse" type="button">
                    <i class="bi <?= $est_admin ? 'bi-piggy-bank' : 'bi-exclamation-diamond' ?> me-1"></i>
                    <?= $est_admin ? '3. Caisse du Cycle & Pénalités' : '3. Mes Pénalités (' . count($operations_caisse) . ')' ?>
                </button>
            </li>
            <?php if ($est_admin): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $onglet_actif === 'registre' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#tabRegistre" type="button">
                        <i class="bi bi-clipboard-data me-1"></i>4. Registre Global (Admin)
                    </button>
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="tab-content">
        <!-- =============================================================== -->
        <!-- ONGLET 1 : MES SÉANCES À COTISER                                -->
        <!-- =============================================================== -->
        <div class="tab-pane fade <?= $onglet_actif === 'cotiser' ? 'show active' : '' ?>" id="tabCotiser">
            <div class="card card-djangui p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-0">Mes Séances de Cotisation — <?= e($cycle['titre'] ?? '') ?></h5>
                        <p class="text-muted small mb-0">
                            Séance ouverte actuellement : <strong>Séance #<?= $seance_max_ouverte ?></strong>. Vous ne pouvez pas cotiser pour une séance future tant que la séance précédente n'est pas clôturée.
                        </p>
                    </div>
                </div>

                <?php if (empty($mes_seances)): ?>
                    <div class="p-4 rounded-3 box-adaptive text-center text-muted">
                        Aucune séance de cotisation pour ce cycle.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-djangui align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Séance</th>
                                    <th>Bénéficiaire (Bouffeur)</th>
                                    <th>Période</th>
                                    <th>Versé / Attendu</th>
                                    <th>Reste à régler</th>
                                    <th>Statut</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $aujourdhui = date_actuelle('Y-m-d');
                                $cumul_dettes_ant = 0.0;
                                foreach ($mes_seances as $s):
                                    $num_s           = (int)$s['numero_seance'];
                                    $est_mon_tour    = ((int)$s['cycle_member_id'] === (int)$s['beneficiaire_cycle_member_id']);
                                    $deja_paye       = ($s['statut_contrib'] === 'paye' || (float)$s['montant_paye'] >= (float)$s['montant_attendu']);
                                    $reste_seance    = max(0.0, (float)$s['montant_attendu'] - (float)$s['montant_paye']);
                                    $en_retard       = (!$deja_paye && !$est_mon_tour && strtotime($s['date_echeance']) < strtotime($aujourdhui));
                                    $est_verrouillee = ($num_s > $seance_max_ouverte);
                                    $periode_label   = formater_periode_seance($cycle['date_debut'], $num_s, $cycle['frequence'], $s['date_echeance']);
                                    $max_payable     = round($reste_seance + $cumul_dettes_ant + $mes_penalites_dues, 2);
                                ?>
                                <tr class="<?= $est_mon_tour ? 'row-highlight-djangui' : '' ?>">
                                    <td class="fw-bold">
                                        #<?= $num_s ?>
                                        <?php if ($est_mon_tour): ?>
                                            <span class="badge bg-primary ms-1">Mon Tour</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= e($s['nom_bouffeur']) ?></div>
                                        <small class="text-muted"><?= strtoupper(e($s['canal_bouffeur'])) ?> : <?= e($s['tel_bouffeur']) ?></small>
                                    </td>
                                    <td class="small fw-semibold"><?= e($periode_label) ?></td>
                                    <td>
                                        <strong><?= format_fcfa($s['montant_paye']) ?></strong>
                                        <span class="text-muted small">/ <?= format_fcfa($s['montant_attendu']) ?></span>
                                    </td>
                                    <td class="fw-bold <?= $en_retard ? 'text-danger' : '' ?>">
                                        <?= $est_mon_tour ? '0 FCFA (Sur pot)' : format_fcfa($reste_seance) ?>
                                    </td>
                                    <td>
                                        <?php if ($est_mon_tour && $deja_paye): ?>
                                            <span class="badge bg-info text-dark">Compensé sur pot</span>
                                        <?php elseif ($deja_paye): ?>
                                            <span class="badge bg-success">Payé</span>
                                        <?php elseif ($est_verrouillee): ?>
                                            <span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>Verrouillée</span>
                                        <?php elseif ($en_retard): ?>
                                            <span class="badge bg-danger">En retard</span>
                                        <?php elseif ((float)$s['montant_paye'] > 0): ?>
                                            <span class="badge bg-warning text-dark">Partiel</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">En attente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($deja_paye || $est_mon_tour): ?>
                                            <span class="text-success small fw-bold"><i class="bi bi-check2-all"></i> À jour</span>
                                        <?php elseif ($est_verrouillee): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                                <i class="bi bi-lock-fill me-1"></i>Séance fermée
                                            </button>
                                        <?php else: ?>
                                            <button type="button"
                                                    class="btn btn-sm <?= $en_retard ? 'btn-danger' : 'btn-glow-emerald' ?>"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalPaiement"
                                                    data-contrib-id="<?= (int)$s['contribution_id'] ?>"
                                                    data-seance-num="<?= $num_s ?>"
                                                    data-bouffeur="<?= e($s['nom_bouffeur']) ?>"
                                                    data-reste-seance="<?= $reste_seance ?>"
                                                    data-dettes-ant="<?= $cumul_dettes_ant ?>"
                                                    data-penalites="<?= $mes_penalites_dues ?>"
                                                    data-max-payable="<?= $max_payable ?>">
                                                <i class="bi bi-cash-coin me-1"></i>Payer
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                                    if (!$est_mon_tour && !$deja_paye) {
                                        $cumul_dettes_ant = round($cumul_dettes_ant + $reste_seance, 2);
                                    }
                                endforeach;
                                ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- =============================================================== -->
        <!-- ONGLET 2 : MES REÇUS VENTILÉS                                   -->
        <!-- =============================================================== -->
        <div class="tab-pane fade <?= $onglet_actif === 'recus' ? 'show active' : '' ?>" id="tabRecus">
            <div class="card card-djangui p-4">
                <h5 class="fw-bold mb-3"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Historique de mes paiements & ventilation</h5>
                <?php if (empty($mes_paiements)): ?>
                    <p class="text-muted small mb-0">Aucun paiement enregistré sur ce cycle pour le moment.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-djangui align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Référence & Date</th>
                                    <th>Séance & Bouffeur</th>
                                    <th>Canal</th>
                                    <th class="text-end">Total Versé</th>
                                    <th class="text-end">Vers Bouffeur</th>
                                    <th class="text-end">Vers Caisse Cycle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mes_paiements as $p): ?>
                                    <tr>
                                        <td>
                                            <code class="fw-bold"><?= e($p['reference_paiement']) ?></code>
                                            <div class="small text-muted"><?= date('d/m/Y H:i', strtotime($p['date_paiement'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold">Séance #<?= (int)$p['numero_seance'] ?> — <?= e($p['nom_bouffeur']) ?></div>
                                            <small class="text-muted"><?= e($p['commentaire']) ?></small>
                                        </td>
                                        <td><span class="badge bg-info-subtle text-info border"><?= strtoupper(e($p['mode_paiement'])) ?></span></td>
                                        <td class="text-end fw-bold"><?= format_fcfa($p['montant_total']) ?></td>
                                        <td class="text-end text-success fw-semibold"><?= format_fcfa($p['part_beneficiaire']) ?></td>
                                        <td class="text-end text-warning fw-semibold"><?= format_fcfa($p['part_caisse_reserve']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- =============================================================== -->
        <!-- ONGLET 3 : CAISSE DU CYCLE & PÉNALITÉS (AVEC SÉPARATEURS COLORÉS PAR SÉANCE — POINTS 2, 6, 7, 8, 9) -->
        <!-- =============================================================== -->
        <div class="tab-pane fade <?= $onglet_actif === 'caisse' ? 'show active' : '' ?>" id="tabCaisse">
            <div class="card card-djangui p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">
                            <i class="bi <?= $est_admin ? 'bi-piggy-bank text-success' : 'bi-exclamation-diamond text-danger' ?> me-2"></i>
                            <?= $est_admin ? 'Caisse de Réserve & Pénalités — ' . e($cycle['titre'] ?? '') : 'Mes Pénalités sur ' . e($cycle['titre'] ?? '') ?>
                        </h5>
                        <p class="text-muted small mb-0">
                            <strong>Barème de ce cycle :</strong> Cotisation partielle = <strong><?= $pourcentage_partiel ?> % (<?= format_fcfa($penalite_partielle) ?>)</strong> · Non-cotisation (0 FCFA) = <strong><?= $pourcentage_double ?> % (<?= format_fcfa($penalite_non_cotis) ?>)</strong> · Majoration par séance supplémentaire non soldée = <strong>+<?= format_fcfa($penalite_partielle) ?></strong>.
                        </p>
                    </div>
                    <?php if ($est_admin && $cycle): ?>
                        <button type="button" class="btn btn-sm btn-glow-blue" data-bs-toggle="modal" data-bs-target="#modalCaisseAdmin">
                            <i class="bi bi-plus-circle me-1"></i>Taux % / Ajouter Bonus ou Pénalité
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($operations_caisse)): ?>
                    <div class="p-4 rounded-3 box-adaptive text-center text-muted">
                        <?= $est_admin ? 'Aucune pénalité ni bonus enregistré sur ce cycle.' : 'Vous n\'avez aucune pénalité enregistrée sur ce cycle.' ?>
                    </div>
                <?php else: ?>
                    <?php
                        // Palette de couleurs distinctes par numéro de séance (Point 9 : traits et bandeaux de couleur pour séparer les séances)
                        $couleurs_seances = [
                            1 => ['border' => '#2563eb', 'bg' => 'rgba(37, 99, 235, 0.10)',  'badge' => 'bg-primary'],
                            2 => ['border' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.10)', 'badge' => 'bg-success'],
                            3 => ['border' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.12)', 'badge' => 'bg-warning text-dark'],
                            4 => ['border' => '#8b5cf6', 'bg' => 'rgba(139, 92, 246, 0.10)', 'badge' => 'bg-info text-dark'],
                            5 => ['border' => '#ec4899', 'bg' => 'rgba(236, 72, 153, 0.10)', 'badge' => 'bg-danger'],
                            6 => ['border' => '#06b6d4', 'bg' => 'rgba(6, 182, 212, 0.10)',  'badge' => 'bg-info text-dark'],
                        ];
                        $totaux_par_seance = [];
                        foreach ($operations_caisse as $op_calc) {
                            $ns_c = (int)($op_calc['numero_seance'] ?? 1);
                            $totaux_par_seance[$ns_c] = ($totaux_par_seance[$ns_c] ?? 0.0) + (float)$op_calc['montant'];
                        }
                    ?>
                    <div class="table-responsive">
                        <table class="table table-djangui align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Séance</th>
                                    <th>Opération & Motif</th>
                                    <?php if ($est_admin): ?>
                                        <th>Membre</th>
                                    <?php endif; ?>
                                    <th>Montant</th>
                                    <th>Date</th>
                                    <th>Statut</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $seance_precedente = null;
                                foreach ($operations_caisse as $op):
                                    $ns = (int)($op['numero_seance'] ?? 1);
                                    $palette = $couleurs_seances[(($ns - 1) % 6) + 1];
                                    $nouvelle_seance = ($seance_precedente !== $ns);
                                    $seance_precedente = $ns;
                                    $periode_s_op = $cycle ? formater_periode_seance($cycle['date_debut'], $ns, $cycle['frequence']) : '';
                                    $nb_cols = $est_admin ? 7 : 6;
                                ?>
                                    <?php if ($nouvelle_seance): ?>
                                        <!-- BANDEAU ET TRAIT DE COULEUR SÉPARATEUR DE SÉANCE (POINT 9) -->
                                        <tr style="border-top: 3px solid <?= $palette['border'] ?>; background: <?= $palette['bg'] ?>;">
                                            <td colspan="<?= $nb_cols ?>" class="py-2 px-3">
                                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge <?= $palette['badge'] ?> rounded-pill px-3 py-1">
                                                            SÉANCE #<?= $ns ?>
                                                        </span>
                                                        <span class="small fw-bold" style="color: <?= $palette['border'] ?>;">
                                                            <?= e($periode_s_op) ?>
                                                        </span>
                                                    </div>
                                                    <span class="small fw-bold" style="color: <?= $palette['border'] ?>;">
                                                        Total Séance #<?= $ns ?> : <?= format_fcfa($totaux_par_seance[$ns] ?? 0) ?>
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                    <tr style="border-left: 4px solid <?= $palette['border'] ?>;">
                                        <td class="fw-bold" style="color: <?= $palette['border'] ?>;">#<?= $ns ?></td>
                                        <td>
                                            <?php if ($op['type_operation'] === 'bonus'): ?>
                                                <span class="badge bg-success-subtle text-success mb-1">Bonus Caisse</span>
                                            <?php elseif (str_contains($op['motif'], 'taux double')): ?>
                                                <span class="badge bg-danger mb-1">Pénalité Non-Cotisation (2x)</span>
                                            <?php elseif (str_contains($op['motif'], 'Majoration')): ?>
                                                <span class="badge bg-warning text-dark mb-1">Majoration Séance suivante (+1x)</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger mb-1">Pénalité Cotisation Partielle (1x)</span>
                                            <?php endif; ?>
                                            <div class="small text-muted"><?= e($op['motif']) ?></div>
                                        </td>
                                        <?php if ($est_admin): ?>
                                            <td class="fw-semibold"><?= e($op['nom_complet']) ?></td>
                                        <?php endif; ?>
                                        <td class="fw-bold"><?= format_fcfa($op['montant']) ?></td>
                                        <td class="small"><?= date('d/m/Y', strtotime($op['date_operation'])) ?></td>
                                        <td>
                                            <span class="badge <?= $op['statut'] === 'regle' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                                <?= $op['statut'] === 'regle' ? 'Réglée' : 'En attente' ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($op['statut'] === 'en_attente'): ?>
                                                <?php if ((int)$op['membre_user_id'] === $user_id): ?>
                                                    <form method="POST" action="finances.php?cycle_id=<?= $cid ?>&tab=caisse" class="d-inline">
                                                        <?= champ_csrf() ?>
                                                        <input type="hidden" name="action" value="regler_ma_penalite">
                                                        <input type="hidden" name="cycle_id" value="<?= $cid ?>">
                                                        <input type="hidden" name="penalty_id" value="<?= (int)$op['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-glow-emerald">
                                                            <i class="bi bi-wallet2 me-1"></i>Régler ma pénalité
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($est_admin): ?>
                                                    <form method="POST" action="finances.php?cycle_id=<?= $cid ?>&tab=caisse" class="d-inline" onsubmit="return confirm('Supprimer cette pénalité en attente ?');">
                                                        <?= champ_csrf() ?>
                                                        <input type="hidden" name="action" value="supprimer_operation">
                                                        <input type="hidden" name="cycle_id" value="<?= $cid ?>">
                                                        <input type="hidden" name="penalty_id" value="<?= (int)$op['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger ms-1">
                                                            <i class="bi bi-trash3 me-1"></i>Supprimer
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- =============================================================== -->
        <!-- ONGLET 4 : REGISTRE GLOBAL DES COTISATIONS (ADMIN UNIQUEMENT)   -->
        <!-- =============================================================== -->
        <?php if ($est_admin): ?>
        <div class="tab-pane fade <?= $onglet_actif === 'registre' ? 'show active' : '' ?>" id="tabRegistre">
            <div class="card card-djangui p-4">
                <h5 class="fw-bold mb-3">
                    <i class="bi bi-clipboard-data text-primary me-2"></i>Registre complet des cotisations — <?= e($cycle['titre'] ?? '') ?>
                </h5>

                <?php if (empty($toutes_cotisations)): ?>
                    <div class="p-4 rounded-3 box-adaptive text-center text-muted">
                        Aucune cotisation générée pour ce cycle.
                    </div>
                <?php else: ?>
                    <?php
                        // Palette de couleurs par séance + calcul des sous-totaux par séance
                        $couleurs_reg = [
                            1 => ['border' => '#2563eb', 'bg' => 'rgba(37, 99, 235, 0.10)',  'badge' => 'bg-primary'],
                            2 => ['border' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.10)', 'badge' => 'bg-success'],
                            3 => ['border' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.12)', 'badge' => 'bg-warning text-dark'],
                            4 => ['border' => '#8b5cf6', 'bg' => 'rgba(139, 92, 246, 0.10)', 'badge' => 'bg-info text-dark'],
                            5 => ['border' => '#ec4899', 'bg' => 'rgba(236, 72, 153, 0.10)', 'badge' => 'bg-danger'],
                            6 => ['border' => '#06b6d4', 'bg' => 'rgba(6, 182, 212, 0.10)',  'badge' => 'bg-info text-dark'],
                        ];
                        $stats_reg_seance = [];
                        foreach ($toutes_cotisations as $tc_calc) {
                            $ns_r = (int)$tc_calc['numero_seance'];
                            if (!isset($stats_reg_seance[$ns_r])) {
                                $stats_reg_seance[$ns_r] = ['paye' => 0.0, 'attendu' => 0.0, 'bouffeur' => $tc_calc['ben_nom']];
                            }
                            $stats_reg_seance[$ns_r]['paye']    += (float)$tc_calc['montant_paye'];
                            $stats_reg_seance[$ns_r]['attendu'] += (float)$tc_calc['montant_attendu'];
                        }
                    ?>
                    <div class="table-responsive">
                        <table class="table table-djangui align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Séance</th>
                                    <th>Membre cotisant</th>
                                    <th>Bouffeur</th>
                                    <th>Période</th>
                                    <th class="text-end">Versé / Attendu</th>
                                    <th class="text-end">Part Caisse</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $seance_reg_prec = null;
                                foreach ($toutes_cotisations as $tc):
                                    $ns_tc        = (int)$tc['numero_seance'];
                                    $pal_reg      = $couleurs_reg[(($ns_tc - 1) % 6) + 1];
                                    $nouv_s_reg   = ($seance_reg_prec !== $ns_tc);
                                    $seance_reg_prec = $ns_tc;
                                    $est_bf       = ((int)$tc['cycle_member_id'] === (int)$tc['beneficiaire_cycle_member_id']);
                                    $per_tc       = formater_periode_seance($cycle['date_debut'], $ns_tc, $cycle['frequence'], $tc['date_echeance']);
                                ?>
                                    <?php if ($nouv_s_reg): ?>
                                        <!-- BANDEAU ET TRAIT DE COULEUR SÉPARATEUR DE SÉANCE -->
                                        <tr style="border-top: 3px solid <?= $pal_reg['border'] ?>; background: <?= $pal_reg['bg'] ?>;">
                                            <td colspan="7" class="py-2 px-3">
                                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                                        <span class="badge <?= $pal_reg['badge'] ?> rounded-pill px-3 py-1">
                                                            SÉANCE #<?= $ns_tc ?>
                                                        </span>
                                                        <span class="small fw-bold" style="color: <?= $pal_reg['border'] ?>;">
                                                            <?= e($per_tc) ?> — Bouffeur : <?= e($stats_reg_seance[$ns_tc]['bouffeur']) ?>
                                                        </span>
                                                    </div>
                                                    <span class="small fw-bold" style="color: <?= $pal_reg['border'] ?>;">
                                                        Collecté : <?= format_fcfa($stats_reg_seance[$ns_tc]['paye']) ?> / <?= format_fcfa($stats_reg_seance[$ns_tc]['attendu']) ?>
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                    <tr style="border-left: 4px solid <?= $pal_reg['border'] ?>;">
                                        <td class="fw-bold" style="color: <?= $pal_reg['border'] ?>;">#<?= $ns_tc ?></td>
                                        <td>
                                            <?= e($tc['mem_nom']) ?>
                                            <?php if ($est_bf): ?><span class="badge bg-info-subtle text-info ms-1">Bouffeur</span><?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?= e($tc['ben_nom']) ?></td>
                                        <td class="small"><?= e($per_tc) ?></td>
                                        <td class="text-end fw-bold"><?= format_fcfa($tc['montant_paye']) ?> / <?= format_fcfa($tc['montant_attendu']) ?></td>
                                        <td class="text-end text-warning"><?= format_fcfa($tc['part_caisse_reserve'] ?? 0) ?></td>
                                        <td>
                                            <?php if ($est_bf && $tc['statut'] === 'paye'): ?>
                                                <span class="badge bg-info text-dark">Sur pot</span>
                                            <?php elseif ($tc['statut'] === 'paye'): ?>
                                                <span class="badge bg-success">Payé</span>
                                            <?php elseif ($tc['statut'] === 'en_retard'): ?>
                                                <span class="badge bg-danger">En retard</span>
                                            <?php elseif ($tc['statut'] === 'partiel'): ?>
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
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- MODALE MEMBRE : PAYER MA COTISATION AU MONTANT EXACT -->
<div class="modal fade" id="modalPaiement" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content card-djangui">
            <form method="POST" action="finances.php?cycle_id=<?= $cid ?>">
                <?= champ_csrf() ?>
                <input type="hidden" name="action" value="payer_cotisation">
                <input type="hidden" name="cycle_id" value="<?= $cid ?>">
                <input type="hidden" name="contribution_id" id="inputContribId">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-cash-coin me-2 text-success"></i>Régler ma cotisation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="p-3 rounded-3 box-adaptive mb-3 small">
                        <p class="mb-1"><strong>Séance :</strong> <span id="resumeSeance"></span></p>
                        <p class="mb-1"><strong>Bouffeur :</strong> <span id="resumeBouffeur"></span></p>
                        <p class="mb-1"><strong>Reste sur cette séance :</strong> <span id="resumeResteSeance" class="fw-bold"></span></p>
                        <div id="blocDettesAnt" class="d-none text-warning-emphasis">
                            <p class="mb-1"><strong>+ Dettes séances précédentes :</strong> <span id="resumeDettesAnt"></span></p>
                        </div>
                        <div id="blocPenalite" class="d-none text-danger">
                            <p class="mb-1"><strong>+ Pénalités en attente :</strong> <span id="resumePenalite"></span></p>
                        </div>
                        <hr class="my-2">
                        <p class="mb-0 fw-bold text-primary">Plafond maximum autorisé : <span id="resumeTotalMax"></span></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Montant exact à payer maintenant (FCFA) *</label>
                        <div class="input-group mb-2">
                            <input type="number" name="montant_saisi" id="inputMontantSaisi" class="form-control form-control-lg fw-bold text-success" min="1" step="any" required>
                            <span class="input-group-text fw-bold">FCFA</span>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnPayerSeanceSeule">
                                Juste cette séance
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success d-none" id="btnToutSolder">
                                Tout solder (séance + dettes + pénalités)
                            </button>
                        </div>
                    </div>

                    <label class="form-label fw-semibold small">Canal de paiement :</label>
                    <div class="d-flex gap-2">
                        <div class="form-check p-2 rounded-3 box-adaptive flex-fill">
                            <input class="form-check-input" type="radio" name="mode_paiement" id="m1" value="momo" checked>
                            <label class="form-check-label small fw-bold" for="m1">MTN MoMo</label>
                        </div>
                        <div class="form-check p-2 rounded-3 box-adaptive flex-fill">
                            <input class="form-check-input" type="radio" name="mode_paiement" id="m2" value="om">
                            <label class="form-check-label small fw-bold" for="m2">Orange Money</label>
                        </div>
                        <div class="form-check p-2 rounded-3 box-adaptive flex-fill">
                            <input class="form-check-input" type="radio" name="mode_paiement" id="m3" value="especes">
                            <label class="form-check-label small fw-bold" for="m3">Espèces</label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-glow-emerald">Confirmer le paiement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE ADMIN : MODIFIER LE % DE PÉNALITÉ PARTIELLE OU AJOUTER UN BONUS/PÉNALITÉ -->
<?php if ($est_admin): ?>
<div class="modal fade" id="modalCaisseAdmin" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content card-djangui">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-piggy-bank me-2 text-primary"></i>Caisse & Pénalités — <?= e($cycle['titre'] ?? '') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($cycle): ?>
                    <form method="POST" action="finances.php?cycle_id=<?= $cid ?>&tab=caisse" class="p-3 rounded-3 box-adaptive mb-3">
                        <?= champ_csrf() ?>
                        <input type="hidden" name="action" value="modifier_taux_penalite">
                        <input type="hidden" name="cycle_id" value="<?= $cid ?>">
                        <label class="form-label fw-bold small">Taux de pénalité pour cotisation partielle (%) :</label>
                        <div class="input-group mb-2">
                            <input type="number" name="pourcentage_penalite" class="form-control fw-bold" min="0" max="50" step="0.5" value="<?= $pourcentage_partiel ?>" required>
                            <span class="input-group-text">%</span>
                            <button type="submit" class="btn btn-glow-blue">Enregistrer %</button>
                        </div>
                        <div class="small text-muted">
                            <i class="bi bi-info-circle me-1"></i>La pénalité pour <strong>non-cotisation (0 FCFA)</strong> est automatiquement fixée au <strong>double</strong> de ce pourcentage.
                        </div>
                    </form>

                    <form method="POST" action="finances.php?cycle_id=<?= $cid ?>&tab=caisse">
                        <?= champ_csrf() ?>
                        <input type="hidden" name="action" value="creer_operation">
                        <input type="hidden" name="cycle_id" value="<?= $cid ?>">
                        <h6 class="fw-bold mb-2">Nouvelle opération sur ce cycle</h6>
                        <div class="row g-2 mb-2">
                            <div class="col-8">
                                <label class="form-label small fw-semibold">Membre</label>
                                <select name="cycle_member_id" class="form-select form-select-sm" required>
                                    <?php foreach ($membres_cycle_sel as $mc): ?>
                                        <option value="<?= (int)$mc['id'] ?>"><?= e($mc['nom_complet']) ?> (<?= e($mc['code_public']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-semibold">Séance #</label>
                                <input type="number" name="numero_seance" class="form-control form-control-sm fw-bold" min="1" value="<?= $seance_max_ouverte ?>" required>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Type</label>
                                <select name="type_operation" class="form-select form-select-sm">
                                    <option value="bonus">Bonus (encaissé)</option>
                                    <option value="penalite">Pénalité (en attente)</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Montant (FCFA)</label>
                                <input type="number" name="montant" class="form-control form-control-sm" min="1" step="any" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Motif *</label>
                            <input type="text" name="motif" class="form-control form-control-sm" maxlength="255" required>
                        </div>
                        <button type="submit" class="btn btn-glow-emerald w-100">Enregistrer l'opération</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('modalPaiement');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        const btn         = event.relatedTarget;
        const contribId   = btn.getAttribute('data-contrib-id');
        const seanceNum   = btn.getAttribute('data-seance-num');
        const bouffeur    = btn.getAttribute('data-bouffeur');
        const resteSeance = parseFloat(btn.getAttribute('data-reste-seance')) || 0;
        const dettesAnt   = parseFloat(btn.getAttribute('data-dettes-ant')) || 0;
        const penalites   = parseFloat(btn.getAttribute('data-penalites')) || 0;
        const maxPayable  = parseFloat(btn.getAttribute('data-max-payable')) || resteSeance;

        document.getElementById('inputContribId').value          = contribId;
        document.getElementById('resumeSeance').textContent      = 'Séance n°' + seanceNum;
        document.getElementById('resumeBouffeur').textContent    = bouffeur;
        document.getElementById('resumeResteSeance').textContent = resteSeance.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('resumeTotalMax').textContent    = maxPayable.toLocaleString('fr-FR') + ' FCFA';

        const inputMontant  = document.getElementById('inputMontantSaisi');
        const btnSeance     = document.getElementById('btnPayerSeanceSeule');
        const btnTout       = document.getElementById('btnToutSolder');

        inputMontant.max   = maxPayable;
        inputMontant.value = resteSeance;

        if (btnSeance) {
            btnSeance.textContent = 'Juste cette séance (' + resteSeance.toLocaleString('fr-FR') + ' FCFA)';
            btnSeance.onclick = function () { inputMontant.value = resteSeance; };
        }
        if (btnTout) {
            if (maxPayable > resteSeance) {
                btnTout.textContent = 'Tout solder (' + maxPayable.toLocaleString('fr-FR') + ' FCFA)';
                btnTout.classList.remove('d-none');
                btnTout.onclick = function () { inputMontant.value = maxPayable; };
            } else {
                btnTout.classList.add('d-none');
            }
        }

        const blocDettes = document.getElementById('blocDettesAnt');
        if (dettesAnt > 0) {
            document.getElementById('resumeDettesAnt').textContent = dettesAnt.toLocaleString('fr-FR') + ' FCFA';
            blocDettes.classList.remove('d-none');
        } else {
            blocDettes.classList.add('d-none');
        }

        const blocPen = document.getElementById('blocPenalite');
        if (penalites > 0) {
            document.getElementById('resumePenalite').textContent = penalites.toLocaleString('fr-FR') + ' FCFA';
            blocPen.classList.remove('d-none');
        } else {
            blocPen.classList.add('d-none');
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
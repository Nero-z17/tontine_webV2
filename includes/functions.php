<?php
// includes/functions.php — Moteur métier Djangui V3 (Certifié Multi-Scénarios) :
// - Verrouillage séquentiel des séances
// - Pénalité partielle (1x %) vs Non-cotisation (2x %)
// - Escalade progressive des pénalités au fil des séances écoulées
// - Suppression définitive d'une pénalité par l'admin (statut = 'annule' non régénéré)
// - Auto-compensation du bouffeur et retenue sur pot (dettes + pénalités) sans jamais fausser montant_pot_recu
// - Clôture automatique du cycle après la dernière séance dès que tout est réglé

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

$racine_fs = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$doc_root  = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$sous_chem = ($doc_root !== '' && str_starts_with($racine_fs, $doc_root))
    ? substr($racine_fs, strlen($doc_root))
    : '/djangui';
define('BASE_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($sous_chem, '/'));

// ============================================================================
// 1. HORLOGE VIRTUELLE & PLANCHER DATE DE CRÉATION DE LA TONTINE
// ============================================================================
define('SIM_CLOCK_FILE', __DIR__ . '/sim_clock.json');

function obtenir_decalage_temps(): int {
    if (!file_exists(SIM_CLOCK_FILE)) {
        return 0;
    }
    $data = json_decode((string)@file_get_contents(SIM_CLOCK_FILE), true);
    return isset($data['offset_seconds']) ? (int)$data['offset_seconds'] : 0;
}

function sauvegarder_decalage_temps(int $offset_seconds): void {
    @file_put_contents(SIM_CLOCK_FILE, json_encode([
        'offset_seconds' => $offset_seconds,
        'updated_at'     => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT));
}

function temps_actuel(): int {
    return time() + obtenir_decalage_temps();
}

function date_actuelle(string $format = 'Y-m-d'): string {
    return date($format, temps_actuel());
}

function obtenir_timestamp_min_tontine(PDO $pdo): int {
    $tontine_id = (int)($_SESSION['tontine_id'] ?? 0);
    if ($tontine_id > 0) {
        $stmt = $pdo->prepare("SELECT LEAST(DATE(created_at), DATE(date_debut_essai)) FROM tontines WHERE id = ? LIMIT 1");
        $stmt->execute([$tontine_id]);
        $date_min = $stmt->fetchColumn();
    } else {
        $stmt = $pdo->query("SELECT MIN(LEAST(DATE(created_at), DATE(date_debut_essai))) FROM tontines");
        $date_min = $stmt ? $stmt->fetchColumn() : false;
    }

    if ($date_min) {
        $ts = strtotime($date_min . ' 00:00:00');
        if ($ts !== false) {
            return $ts;
        }
    }
    return strtotime('2026-01-01 00:00:00');
}

// ============================================================================
// 2. CALCUL DES DATES ET INTERVALLES DE SÉANCES
// ============================================================================
function calculer_echeance_seance(string $date_debut_cycle, int $numero_seance, string $frequence): string {
    if ($numero_seance <= 1) {
        return date('Y-m-d', strtotime($date_debut_cycle));
    }
    $nb_periodes = $numero_seance - 1;
    $decalage = match ($frequence) {
        'hebdo'     => '+' . ($nb_periodes * 7) . ' days',
        'bimensuel' => '+' . ($nb_periodes * 15) . ' days',
        default     => '+' . $nb_periodes . ' months',
    };
    return date('Y-m-d', strtotime($date_debut_cycle . ' ' . $decalage));
}

function formater_periode_seance(string $date_debut_cycle, int $numero_seance, string $frequence, ?string $date_echeance = null): string {
    $ech = $date_echeance ?: calculer_echeance_seance($date_debut_cycle, $numero_seance, $frequence);
    if ($numero_seance <= 1) {
        return 'Le ' . date('d/m/Y', strtotime($ech));
    }
    $ech_precedente = calculer_echeance_seance($date_debut_cycle, $numero_seance - 1, $frequence);
    $debut_seance   = date('d/m/Y', strtotime($ech_precedente . ' +1 day'));
    return 'Du ' . $debut_seance . ' au ' . date('d/m/Y', strtotime($ech));
}

// ============================================================================
// 3. VERROUILLAGE SÉQUENTIEL DES SÉANCES
// ============================================================================
function obtenir_seance_ouverte_max(PDO $pdo, int $cycle_id): int {
    $stmt = $pdo->prepare("
        SELECT co.numero_seance,
               MIN(co.date_echeance) AS date_echeance,
               SUM(CASE WHEN co.cycle_member_id != co.beneficiaire_cycle_member_id AND co.montant_paye < co.montant_attendu THEN 1 ELSE 0 END) AS nb_impayes
        FROM contributions co
        INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
        WHERE cm.cycle_id = ?
        GROUP BY co.numero_seance
        ORDER BY co.numero_seance ASC
    ");
    $stmt->execute([$cycle_id]);
    $seances = $stmt->fetchAll();

    if (empty($seances)) {
        return 1;
    }

    $aujourdhui = date_actuelle('Y-m-d');
    $seance_ouverte = 1;

    foreach ($seances as $s) {
        $num = (int)$s['numero_seance'];
        $ech = $s['date_echeance'];
        $impayes = (int)$s['nb_impayes'];

        $seance_ouverte = $num;

        $est_cloturee = ($impayes === 0) || (strtotime($aujourdhui) > strtotime($ech));
        if (!$est_cloturee) {
            break;
        }
    }

    return $seance_ouverte;
}

/**
 * Recalcule exactement montant_pot_recu et pot_totalement_recu pour tous les membres d'un cycle
 * à partir de la somme réelle des cotisations versées (empêche tout blocage en "En régularisation").
 */
function resynchroniser_pots_cycle(PDO $pdo, int $cycle_id): void {
    $stmt = $pdo->prepare("
        SELECT cm.id AS cm_id,
               cm.montant_pot_attendu,
               COALESCE(SUM(co.montant_paye), 0) AS total_collecte
        FROM cycle_members cm
        LEFT JOIN contributions co ON co.beneficiaire_cycle_member_id = cm.id
        WHERE cm.cycle_id = ?
        GROUP BY cm.id, cm.montant_pot_attendu
    ");
    $stmt->execute([$cycle_id]);

    $stmtUpd = $pdo->prepare("
        UPDATE cycle_members
        SET montant_pot_recu = ?,
            pot_totalement_recu = ?,
            date_reception_pot = CASE WHEN ? = 1 AND date_reception_pot IS NULL THEN NOW() ELSE date_reception_pot END
        WHERE id = ?
    ");

    foreach ($stmt->fetchAll() as $row) {
        $collecte = round((float)$row['total_collecte'], 2);
        $attendu  = round((float)$row['montant_pot_attendu'], 2);
        $complet  = ($attendu > 0 && $collecte + 0.01 >= $attendu) ? 1 : 0;
        $stmtUpd->execute([$collecte, $complet, $complet, (int)$row['cm_id']]);
    }
}

// ============================================================================
// 4. SYNCHRONISATION HORLOGE, PÉNALITÉS PROGRESSIVES, RETENUE SUR POT & CLÔTURE AUTO
// ============================================================================
function synchroniser_horloge_et_retards(PDO $pdo): void {
    $ts = temps_actuel();

    // 1. Aligner l'horloge de la session MySQL (CURDATE(), NOW(), CURRENT_TIMESTAMP)
    $pdo->exec("SET SESSION timestamp = " . (int)$ts);

    // 2. Si on a reculé le temps : remettre en 'en_attente' ou 'partiel' les cotisations futures
    $pdo->exec("
        UPDATE contributions
        SET statut = CASE WHEN montant_paye > 0 THEN 'partiel' ELSE 'en_attente' END
        WHERE montant_paye < montant_attendu
          AND date_echeance >= CURDATE()
          AND statut = 'en_retard'
    ");

    // 3. Si on a reculé le temps : supprimer les pénalités/majorations 'en_attente' ou 'annule' dont la séance est redevenue future
    $pdo->exec("
        DELETE p FROM penalties p
        INNER JOIN cycle_members cm ON cm.id = p.cycle_member_id
        INNER JOIN contributions co_seance
            ON co_seance.cycle_member_id = cm.id AND co_seance.numero_seance = p.numero_seance
        WHERE p.statut IN ('en_attente', 'annule')
          AND p.type_operation = 'penalite'
          AND co_seance.date_echeance >= CURDATE()
    ");

    // 4. Auto-compensation de la propre part du bouffeur sur les séances ouvertes
    $stmtCyclesActifs = $pdo->query("
        SELECT c.id, c.tontine_id, c.statut, c.montant_penalite_retard, t.admin_id
        FROM cycles c
        INNER JOIN tontines t ON t.id = c.tontine_id
        WHERE c.statut IN ('actif', 'termine') AND c.tirage_verrouille = 1
    ");
    $cycles_actifs = $stmtCyclesActifs ? $stmtCyclesActifs->fetchAll() : [];

    foreach ($cycles_actifs as $cyc) {
        $cid = (int)$cyc['id'];
        $max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);

        // Sur un cycle actif, auto-compenser la propre part du bouffeur jusqu'à la séance ouverte
        if ($cyc['statut'] === 'actif') {
            $stmtAutoSelf = $pdo->prepare("
                SELECT co.id AS contribution_id,
                       co.cycle_member_id,
                       co.numero_seance,
                       co.montant_attendu,
                       co.montant_paye,
                       cm.user_id,
                       u.canal_paiement
                FROM contributions co
                INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
                INNER JOIN users u ON u.id = cm.user_id
                WHERE cm.cycle_id = ?
                  AND co.cycle_member_id = co.beneficiaire_cycle_member_id
                  AND co.numero_seance <= ?
                  AND co.statut != 'paye'
            ");
            $stmtAutoSelf->execute([$cid, $max_ouverte]);

            foreach ($stmtAutoSelf->fetchAll() as $selfCo) {
                $reste_self = max(0.0, (float)$selfCo['montant_attendu'] - (float)$selfCo['montant_paye']);
                if ($reste_self > 0) {
                    $pdo->prepare("UPDATE contributions SET montant_paye = montant_attendu, statut = 'paye' WHERE id = ?")
                        ->execute([(int)$selfCo['contribution_id']]);

                    $ref = 'AUTO-POT-S' . (int)$selfCo['numero_seance'] . '-' . (int)$selfCo['contribution_id'];
                    $pdo->prepare("
                        INSERT INTO payments (
                            contribution_id, paye_par_user_id, montant_total,
                            part_beneficiaire, part_caisse_reserve, mode_paiement,
                            reference_paiement, date_paiement, est_en_retard,
                            statut_paiement, commentaire
                        ) VALUES (?, ?, ?, ?, 0.00, ?, ?, NOW(), 0, 'valide', ?)
                    ")->execute([
                        (int)$selfCo['contribution_id'],
                        (int)$selfCo['user_id'],
                        $reste_self,
                        $reste_self,
                        $selfCo['canal_paiement'] ?: 'momo',
                        $ref,
                        "Compensation automatique sur le pot (Bouffeur de la Séance #{$selfCo['numero_seance']})"
                    ]);
                }
            }
        }

        resynchroniser_pots_cycle($pdo, $cid);
    }

    // 5. Passer en 'en_retard' les cotisations échues non soldées (hors bouffeur de la séance)
    $pdo->exec("
        UPDATE contributions
        SET statut = 'en_retard'
        WHERE montant_paye < montant_attendu
          AND cycle_member_id != beneficiaire_cycle_member_id
          AND date_echeance < CURDATE()
          AND statut != 'en_retard'
    ");

    // 6. POINTS 7 & 8 : GÉNÉRATION DES PÉNALITÉS PAR SÉANCE
    //    Important : $stmtCheckPenSeance cherche toute ligne (y compris statut = 'annule' supprimée par l'admin)
    //    pour ne JAMAIS recréer une pénalité que l'administrateur a supprimée !
    $stmtCheckPenSeance = $pdo->prepare("
        SELECT id FROM penalties
        WHERE contribution_id = ? AND numero_seance = ? AND type_operation = 'penalite'
        LIMIT 1
    ");
    $stmtInsPenSeance = $pdo->prepare("
        INSERT INTO penalties (cycle_member_id, contribution_id, numero_seance, type_operation, montant, motif, statut, date_operation, auteur_id)
        VALUES (?, ?, ?, 'penalite', ?, ?, 'en_attente', NOW(), ?)
    ");

    foreach ($cycles_actifs as $cyc) {
        if ($cyc['statut'] !== 'actif') {
            continue;
        }
        $cid        = (int)$cyc['id'];
        $admin_id   = (int)$cyc['admin_id'];
        $p_partiel  = (float)$cyc['montant_penalite_retard'];
        $p_non_cot  = round($p_partiel * 2, 2);

        if ($p_partiel <= 0) {
            continue;
        }

        $stmtSeancesEchues = $pdo->prepare("
            SELECT co.numero_seance, MIN(co.date_echeance) AS date_echeance
            FROM contributions co
            INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
            WHERE cm.cycle_id = ?
            GROUP BY co.numero_seance
            HAVING MIN(co.date_echeance) < CURDATE()
            ORDER BY co.numero_seance ASC
        ");
        $stmtSeancesEchues->execute([$cid]);
        $seances_echues = $stmtSeancesEchues->fetchAll();

        foreach ($seances_echues as $se) {
            $k          = (int)$se['numero_seance'];
            $date_ech_k = $se['date_echeance'];

            // A) Pénalité initiale sur les cotisations impayées de la séance K elle-même
            $stmtImpK = $pdo->prepare("
                SELECT co.id AS contribution_id, co.cycle_member_id, co.montant_attendu, co.montant_paye
                FROM contributions co
                INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
                WHERE cm.cycle_id = ?
                  AND co.numero_seance = ?
                  AND co.cycle_member_id != co.beneficiaire_cycle_member_id
                  AND co.montant_paye < co.montant_attendu
            ");
            $stmtImpK->execute([$cid, $k]);

            foreach ($stmtImpK->fetchAll() as $imp) {
                $stmtCheckPenSeance->execute([(int)$imp['contribution_id'], $k]);
                if (!$stmtCheckPenSeance->fetchColumn()) {
                    $paye_actuel = (float)$imp['montant_paye'];
                    if ($paye_actuel <= 0) {
                        $montant_pen = $p_non_cot;
                        $motif_pen   = sprintf(
                            'Séance #%d : Non-cotisation à l\'échéance du %s (taux double)',
                            $k,
                            date('d/m/Y', strtotime($date_ech_k))
                        );
                    } else {
                        $montant_pen = $p_partiel;
                        $motif_pen   = sprintf(
                            'Séance #%d : Cotisation partielle en retard (échéance du %s)',
                            $k,
                            date('d/m/Y', strtotime($date_ech_k))
                        );
                    }
                    $stmtInsPenSeance->execute([
                        (int)$imp['cycle_member_id'],
                        (int)$imp['contribution_id'],
                        $k,
                        $montant_pen,
                        $motif_pen,
                        $admin_id
                    ]);
                }
            }

            // B) Escalade / Majoration à la fin de la séance K (K > 1) pour les anciennes séances S < K non soldées
            if ($k > 1) {
                $stmtAncNonSoldes = $pdo->prepare("
                    SELECT DISTINCT co.id AS contribution_id, co.cycle_member_id, co.numero_seance AS seance_origine
                    FROM contributions co
                    INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
                    LEFT JOIN penalties p_att
                        ON p_att.contribution_id = co.id
                       AND p_att.type_operation = 'penalite'
                       AND p_att.statut = 'en_attente'
                       AND p_att.numero_seance < ?
                    WHERE cm.cycle_id = ?
                      AND co.numero_seance < ?
                      AND co.cycle_member_id != co.beneficiaire_cycle_member_id
                      AND (co.montant_paye < co.montant_attendu OR p_att.id IS NOT NULL)
                ");
                $stmtAncNonSoldes->execute([$k, $cid, $k]);

                foreach ($stmtAncNonSoldes->fetchAll() as $anc) {
                    $stmtCheckPenSeance->execute([(int)$anc['contribution_id'], $k]);
                    if (!$stmtCheckPenSeance->fetchColumn()) {
                        $s_orig    = (int)$anc['seance_origine'];
                        $motif_maj = sprintf(
                            'Séance #%d : Majoration pour impayé/pénalité non soldé de la Séance #%d (+pénalité partielle)',
                            $k,
                            $s_orig
                        );
                        $stmtInsPenSeance->execute([
                            (int)$anc['cycle_member_id'],
                            (int)$anc['contribution_id'],
                            $k,
                            $p_partiel,
                            $motif_maj,
                            $admin_id
                        ]);
                    }
                }
            }
        }
    }

    // 7. RETENUE AUTOMATIQUE SUR LE POT DU BOUFFEUR (SANS JAMAIS ALTÉRER montant_pot_recu)
    foreach ($cycles_actifs as $cyc) {
        $cid = (int)$cyc['id'];
        $max_ouverte = obtenir_seance_ouverte_max($pdo, $cid);

        $stmtBouffeurs = $pdo->prepare("
            SELECT cm.id AS cm_id, cm.user_id, cm.rang_beneficiaire, u.canal_paiement
            FROM cycle_members cm
            INNER JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ?
              AND cm.rang_beneficiaire IS NOT NULL
              AND cm.rang_beneficiaire <= ?
            ORDER BY cm.rang_beneficiaire ASC
        ");
        $stmtBouffeurs->execute([$cid, $max_ouverte]);

        foreach ($stmtBouffeurs->fetchAll() as $bf) {
            $bf_cm_id   = (int)$bf['cm_id'];
            $bf_user_id = (int)$bf['user_id'];
            $bf_rang    = (int)$bf['rang_beneficiaire'];
            $bf_canal   = $bf['canal_paiement'] ?: 'momo';

            // Somme réellement versée par les AUTRES membres dans le pot de ce bouffeur
            $stmtCashAutres = $pdo->prepare("
                SELECT COALESCE(SUM(montant_paye), 0)
                FROM contributions
                WHERE beneficiaire_cycle_member_id = ? AND cycle_member_id != ?
            ");
            $stmtCashAutres->execute([$bf_cm_id, $bf_cm_id]);
            $cash_autres = (float)$stmtCashAutres->fetchColumn();

            // Somme déjà prélevée par retenue automatique sur le pot de ce bouffeur (RET-DETTE-S{rang} ou RET-PEN-S{rang})
            $stmtDejaRetenu = $pdo->prepare("
                SELECT COALESCE(SUM(p.montant_total), 0)
                FROM payments p
                INNER JOIN contributions co ON co.id = p.contribution_id
                INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
                WHERE cm.cycle_id = ?
                  AND p.paye_par_user_id = ?
                  AND p.reference_paiement LIKE ?
            ");
            $stmtDejaRetenu->execute([$cid, $bf_user_id, 'RET-%-S' . $bf_rang . '-%']);
            $deja_retenu = (float)$stmtDejaRetenu->fetchColumn();

            $pot_disponible = round(max(0.0, $cash_autres - $deja_retenu), 2);
            if ($pot_disponible <= 0) {
                continue;
            }

            // A) Déduire d'abord ses dettes de cotisations des séances précédentes (< son tour)
            $stmtDettes = $pdo->prepare("
                SELECT id, beneficiaire_cycle_member_id, numero_seance, montant_attendu, montant_paye
                FROM contributions
                WHERE cycle_member_id = ?
                  AND beneficiaire_cycle_member_id != ?
                  AND numero_seance < ?
                  AND montant_paye < montant_attendu
                ORDER BY numero_seance ASC
            ");
            $stmtDettes->execute([$bf_cm_id, $bf_cm_id, $bf_rang]);

            foreach ($stmtDettes->fetchAll() as $dette) {
                if ($pot_disponible <= 0) {
                    break;
                }
                $reste_dette = round(max(0.0, (float)$dette['montant_attendu'] - (float)$dette['montant_paye']), 2);
                $prelevement = round(min($pot_disponible, $reste_dette), 2);

                if ($prelevement > 0) {
                    $nouveau_paye   = round((float)$dette['montant_paye'] + $prelevement, 2);
                    $nouveau_statut = ($nouveau_paye + 0.01 >= (float)$dette['montant_attendu']) ? 'paye' : 'partiel';

                    $pdo->prepare("UPDATE contributions SET montant_paye = ?, statut = ? WHERE id = ?")
                        ->execute([$nouveau_paye, $nouveau_statut, (int)$dette['id']]);

                    $ref = 'RET-DETTE-S' . $bf_rang . '-C' . (int)$dette['id'] . '-' . random_int(100, 999);
                    $pdo->prepare("
                        INSERT INTO payments (
                            contribution_id, paye_par_user_id, montant_total,
                            part_beneficiaire, part_caisse_reserve, mode_paiement,
                            reference_paiement, date_paiement, est_en_retard,
                            statut_paiement, commentaire
                        ) VALUES (?, ?, ?, ?, 0.00, ?, ?, NOW(), 1, 'valide', ?)
                    ")->execute([
                        (int)$dette['id'],
                        $bf_user_id,
                        $prelevement,
                        $prelevement,
                        $bf_canal,
                        $ref,
                        "Retenue automatique sur son pot (Tour #{$bf_rang}) pour régler sa dette de la Séance #{$dette['numero_seance']}"
                    ]);

                    $pot_disponible = round($pot_disponible - $prelevement, 2);
                }
            }

            // B) Déduire ensuite ses pénalités en attente sur son pot et les verser dans la Caisse DU CYCLE
            $stmtPenAttente = $pdo->prepare("
                SELECT id, contribution_id, numero_seance, montant, motif
                FROM penalties
                WHERE cycle_member_id = ?
                  AND type_operation = 'penalite'
                  AND statut = 'en_attente'
                ORDER BY numero_seance ASC, id ASC
            ");
            $stmtPenAttente->execute([$bf_cm_id]);

            foreach ($stmtPenAttente->fetchAll() as $pen) {
                if ($pot_disponible <= 0) {
                    break;
                }
                $montant_pen     = round((float)$pen['montant'], 2);
                $prelevement_pen = round(min($pot_disponible, $montant_pen), 2);

                if ($prelevement_pen > 0) {
                    // Créditer la caisse de réserve DU CYCLE
                    $pdo->prepare("UPDATE cycles SET solde_caisse_reserve = solde_caisse_reserve + ? WHERE id = ?")
                        ->execute([$prelevement_pen, $cid]);

                    // Trouver la cotisation de rattachement pour tracer le reçu RET-PEN dans payments
                    $co_pen_id = !empty($pen['contribution_id']) ? (int)$pen['contribution_id'] : 0;
                    if ($co_pen_id <= 0) {
                        $stmtFindCo = $pdo->prepare("
                            SELECT id FROM contributions
                            WHERE cycle_member_id = ?
                            ORDER BY (numero_seance = ?) DESC, numero_seance ASC
                            LIMIT 1
                        ");
                        $stmtFindCo->execute([$bf_cm_id, (int)$pen['numero_seance']]);
                        $co_pen_id = (int)$stmtFindCo->fetchColumn();
                    }

                    if ($co_pen_id > 0) {
                        $ref_pen = 'RET-PEN-S' . $bf_rang . '-P' . (int)$pen['id'] . '-' . random_int(100, 999);
                        $pdo->prepare("
                            INSERT INTO payments (
                                contribution_id, paye_par_user_id, montant_total,
                                part_beneficiaire, part_caisse_reserve, mode_paiement,
                                reference_paiement, date_paiement, est_en_retard,
                                statut_paiement, commentaire
                            ) VALUES (?, ?, ?, 0.00, ?, ?, ?, NOW(), 1, 'valide', ?)
                        ")->execute([
                            $co_pen_id,
                            $bf_user_id,
                            $prelevement_pen,
                            $prelevement_pen,
                            $bf_canal,
                            $ref_pen,
                            "Retenue automatique sur son pot (Tour #{$bf_rang}) -> Caisse du cycle ({$pen['motif']})"
                        ]);
                    }

                    if ($prelevement_pen + 0.01 >= $montant_pen) {
                        $nouveau_motif = str_contains($pen['motif'], '[Déduit sur pot]')
                            ? $pen['motif']
                            : $pen['motif'] . ' [Déduit sur pot]';
                        $pdo->prepare("UPDATE penalties SET statut = 'regle', date_reglement = NOW(), motif = ? WHERE id = ?")
                            ->execute([$nouveau_motif, (int)$pen['id']]);
                    } else {
                        $reste_pen = round($montant_pen - $prelevement_pen, 2);
                        $pdo->prepare("UPDATE penalties SET montant = ?, motif = ? WHERE id = ?")
                            ->execute([
                                $reste_pen,
                                $pen['motif'] . ' (acompte de ' . format_fcfa($prelevement_pen) . ' déduit sur pot)',
                                (int)$pen['id']
                            ]);
                    }

                    $pot_disponible = round($pot_disponible - $prelevement_pen, 2);
                }
            }
        }

        // Resynchroniser les pots après les retenues éventuelles
        resynchroniser_pots_cycle($pdo, $cid);

        // 8. POINT 4 : CLÔTURE AUTOMATIQUE DU CYCLE
        if ($cyc['statut'] === 'actif') {
            $stmtEtatCycle = $pdo->prepare("
                SELECT COUNT(co.id) AS total_contrib,
                       MAX(co.date_echeance) AS derniere_echeance,
                       SUM(CASE WHEN co.montant_paye < co.montant_attendu THEN 1 ELSE 0 END) AS nb_cotis_impayees
                FROM contributions co
                INNER JOIN cycle_members cm ON cm.id = co.cycle_member_id
                WHERE cm.cycle_id = ?
            ");
            $stmtEtatCycle->execute([$cid]);
            $etatCycle = $stmtEtatCycle->fetch();

            if ($etatCycle && (int)$etatCycle['total_contrib'] > 0 && !empty($etatCycle['derniere_echeance'])) {
                $derniere_ech_passee = (strtotime($etatCycle['derniere_echeance']) < strtotime(date_actuelle('Y-m-d')));
                $cotis_toutes_payees = ((int)$etatCycle['nb_cotis_impayees'] === 0);

                $stmtPenNonReglees = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM penalties p
                    INNER JOIN cycle_members cm ON cm.id = p.cycle_member_id
                    WHERE cm.cycle_id = ?
                      AND p.type_operation = 'penalite'
                      AND p.statut = 'en_attente'
                ");
                $stmtPenNonReglees->execute([$cid]);
                $nb_pen_attente = (int)$stmtPenNonReglees->fetchColumn();

                if ($derniere_ech_passee && $cotis_toutes_payees && $nb_pen_attente === 0) {
                    $pdo->prepare("UPDATE cycles SET statut = 'termine', date_fin = CURDATE() WHERE id = ?")
                        ->execute([$cid]);
                }
            }
        }
    }
}

// ============================================================================
// 5. FONCTIONS STANDARDS DE SÉCURITÉ ET D'AFFICHAGE
// ============================================================================
function e($texte) {
    return htmlspecialchars((string)$texte, ENT_QUOTES, 'UTF-8');
}

function format_fcfa($montant) {
    return number_format((float)$montant, 0, ',', ' ') . ' FCFA';
}

function message_flash($type, $texte) {
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_texte'] = $texte;
}

function verifier_connexion() {
    global $pdo;
    if (empty($_SESSION['user_id'])) {
        message_flash('warning', 'Veuillez vous connecter pour accéder à cette page.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT role, statut_membre, statut_validation, tontine_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $u = $stmt->fetch();
        if (!$u || $u['statut_membre'] !== 'actif' || $u['statut_validation'] !== 'accepte') {
            $_SESSION = [];
            session_destroy();
            session_start();
            message_flash('danger', 'Votre session a été fermée car votre statut de membre a changé.');
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        $_SESSION['role']       = $u['role'];
        $_SESSION['tontine_id'] = (int)$u['tontine_id'];
    }
}

function verifier_admin() {
    verifier_connexion();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        message_flash('danger', 'Accès refusé : cette action est réservée à l\'administrateur de la tontine.');
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
}

function champ_csrf() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

function verifier_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token_recu = $_POST['csrf_token'] ?? '';
        if (empty($_SESSION['csrf_token']) || $token_recu !== $_SESSION['csrf_token']) {
            die('Erreur de sécurité (CSRF). Veuillez recharger la page et réessayer.');
        }
    }
}

// ============================================================================
// 6. INTERCEPTION DES BOUTONS DE TEMPS DU FOOTER (POST) & SYNCHRONISATION
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['djangui_time_action'])) {
    verifier_csrf();
    $time_action = $_POST['djangui_time_action'];
    $ts_courant  = temps_actuel();
    $ts_min      = (isset($pdo) && $pdo instanceof PDO) ? obtenir_timestamp_min_tontine($pdo) : strtotime('2026-01-01');
    $bloque_min  = false;

    if ($time_action === 'reset') {
        sauvegarder_decalage_temps(0);
        $nouveau_ts = time();
    } elseif ($time_action === 'goto_date' && !empty($_POST['target_date'])) {
        $cible_ts = strtotime($_POST['target_date'] . ' ' . date('H:i:s'));
        if ($cible_ts !== false) {
            if ($cible_ts < $ts_min) {
                $cible_ts   = $ts_min;
                $bloque_min = true;
            }
            sauvegarder_decalage_temps($cible_ts - time());
            $nouveau_ts = $cible_ts;
        } else {
            $nouveau_ts = $ts_courant;
        }
    } else {
        $modif = match ($time_action) {
            '-1m'  => '-1 month',
            '-15d' => '-15 days',
            '-7d'  => '-7 days',
            '-1d'  => '-1 day',
            '+1d'  => '+1 day',
            '+7d'  => '+7 days',
            '+15d' => '+15 days',
            '+1m'  => '+1 month',
            default => ''
        };
        if ($modif !== '') {
            $nouveau_ts = strtotime($modif, $ts_courant);
            if ($nouveau_ts < $ts_min) {
                $nouveau_ts = $ts_min;
                $bloque_min = true;
            }
            sauvegarder_decalage_temps($nouveau_ts - time());
        } else {
            $nouveau_ts = $ts_courant;
        }
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        synchroniser_horloge_et_retards($pdo);
    }

    if ($bloque_min) {
        message_flash('warning', 'Action bloquée : impossible de reculer à une date antérieure à la création de la tontine (' . date('d/m/Y', $ts_min) . ').');
    } else {
        message_flash('info', 'Horloge de simulation réglée au : ' . date('d/m/Y', $nouveau_ts));
    }

    $redirection = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/index.php');
    header('Location: ' . $redirection);
    exit;
}

if (isset($pdo) && $pdo instanceof PDO) {
    synchroniser_horloge_et_retards($pdo);
}
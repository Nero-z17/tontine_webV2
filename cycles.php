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

    // Détail des cotisations par séance (chargé pour l'Admin et pour l'export officiel PDF / Excel)
    $mode_export = $_GET['export'] ?? '';
    if ($est_admin || in_array($mode_export, ['excel', 'pdf'], true)) {
        $stmtDet = $pdo->prepare("
            SELECT c.id AS contribution_id, c.numero_seance, c.date_echeance,
                   c.montant_attendu, c.montant_paye, c.statut,
                   (c.cycle_member_id = c.beneficiaire_cycle_member_id) AS est_bouffeur,
                   u.nom_complet, u.telephone, u.canal_paiement, u.telephone_paiement,
                   (SELECT p.reference_paiement FROM payments p WHERE p.contribution_id = c.id ORDER BY p.id DESC LIMIT 1) AS derniere_ref
            FROM contributions c
            JOIN cycle_members cm ON cm.id = c.cycle_member_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ?
            ORDER BY c.numero_seance ASC, est_bouffeur DESC, (c.statut = 'paye') DESC, u.nom_complet ASC
        ");
        $stmtDet->execute([$cid]);
        foreach ($stmtDet->fetchAll() as $d) {
            $cotisants_par_seance[(int)$d['numero_seance']][] = $d;
        }
    }

    // Export officiel Excel (.xlsx) ou PDF (.pdf)
    if (in_array($mode_export, ['excel', 'pdf'], true)) {
        $stmtTontineInfo = $pdo->prepare("SELECT nom, ville, code_tontine, devise FROM tontines WHERE id = ? LIMIT 1");
        $stmtTontineInfo->execute([$tontine_id]);
        $tontine_info = $stmtTontineInfo->fetch() ?: ['nom' => 'Ma Tontine', 'ville' => 'Douala', 'code_tontine' => 'TON', 'devise' => 'FCFA'];

        $stmtPenExp = $pdo->prepare("
            SELECT p.*, u.nom_complet, u.telephone
            FROM penalties p
            JOIN cycle_members cm ON cm.id = p.cycle_member_id
            JOIN users u ON u.id = cm.user_id
            WHERE cm.cycle_id = ?
            ORDER BY p.numero_seance ASC, p.date_operation ASC, p.id ASC
        ");
        $stmtPenExp->execute([$cid]);
        $penalites_export = $stmtPenExp->fetchAll();

        $slug_cycle = preg_replace('/[^A-Za-z0-9_-]+/', '_', $cycle_choisi['titre']);
        $slug_cycle = trim($slug_cycle, '_') ?: ('Cycle_' . (int)$cycle_choisi['numero_cycle']);

        // ====================================================================
        // EXPORT 1 : VRAI FICHIER EXCEL PROFESSIONNEL (.XLSX MULTI-FEUILLES)
        // ====================================================================
        if ($mode_export === 'excel') {
            $xml_escape = static function ($v): string {
                return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            };
            $col_letter = static function (int $c): string {
                $s = '';
                while ($c > 0) {
                    $m = ($c - 1) % 26;
                    $s = chr(65 + $m) . $s;
                    $c = (int)(($c - $m) / 26);
                }
                return $s;
            };
            $build_sheet_xml = static function (array $rows, array $col_widths, array $merges = []) use ($xml_escape, $col_letter): string {
                $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
                $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
                $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="1"/></sheetViews>';
                $xml .= '<cols>';
                foreach ($col_widths as $idx => $w) {
                    $c_num = $idx + 1;
                    $xml .= '<col min="' . $c_num . '" max="' . $c_num . '" width="' . $w . '" customWidth="1"/>';
                }
                $xml .= '</cols><sheetData>';
                foreach ($rows as $r_idx => $r_info) {
                    $r_num = $r_idx + 1;
                    $ht = isset($r_info['height']) ? ' ht="' . $r_info['height'] . '" customHeight="1"' : ' ht="20" customHeight="1"';
                    $xml .= '<row r="' . $r_num . '"' . $ht . '>';
                    foreach ($r_info['cells'] as $c_idx => $cell) {
                        $ref = $col_letter($c_idx + 1) . $r_num;
                        $val = $cell['v'] ?? '';
                        $st  = (int)($cell['s'] ?? 0);
                        $tp  = $cell['t'] ?? 'str';
                        if ($tp === 'num' && is_numeric($val)) {
                            $xml .= '<c r="' . $ref . '" s="' . $st . '"><v>' . $val . '</v></c>';
                        } else {
                            $xml .= '<c r="' . $ref . '" s="' . $st . '" t="inlineStr"><is><t xml:space="preserve">' . $xml_escape($val) . '</t></is></c>';
                        }
                    }
                    $xml .= '</row>';
                }
                $xml .= '</sheetData>';
                if (!empty($merges)) {
                    $xml .= '<mergeCells count="' . count($merges) . '">';
                    foreach ($merges as $m_ref) {
                        $xml .= '<mergeCell ref="' . $m_ref . '"/>';
                    }
                    $xml .= '</mergeCells>';
                }
                $xml .= '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>';
                $xml .= '</worksheet>';
                return $xml;
            };

            // Styles OpenXML :
            // 0 = Normal, 1 = Bandeau Titre Principal (Vert Forêt #0B241C, Blanc Gras 13pt),
            // 2 = Sous-titre bandeau (Vert Menthe #E6F4EA, Vert Foncé Gras 10pt),
            // 3 = Bandeau Section / Séance (Émeraude #107C41, Blanc Gras 11pt),
            // 4 = En-tête Tableau (Ardoise #1E293B, Blanc Gras 10pt centré),
            // 5 = Cellule Texte Standard avec bordure,
            // 6 = Cellule Texte Gras avec bordure,
            // 7 = Cellule Montant FCFA (#,##0 "FCFA") avec bordure,
            // 8 = Cellule Montant FCFA Gras Total (Fond #E6F4EA) avec bordure,
            // 9 = Cellule Texte Total Gras (Fond #E6F4EA) avec bordure,
            // 10 = Cellule Statut Centrée Gras avec bordure,
            // 11 = Cellule Clé KPI (Fond #F1F5F9, Gras) avec bordure
            $montant_cotis_exp = (float)$cycle_choisi['montant_cotisation'];
            $pen_part_exp      = (float)$cycle_choisi['montant_penalite_retard'];
            $pen_double_exp    = round($pen_part_exp * 2, 2);
            $pct_part_exp      = $montant_cotis_exp > 0 ? round(($pen_part_exp / $montant_cotis_exp) * 100, 1) : 0;
            $pct_double_exp    = round($pct_part_exp * 2, 1);
            $solde_caisse_exp  = (float)($cycle_choisi['solde_caisse_reserve'] ?? 0);

            // ---------------- FEUILLE 1 : SYNTHÈSE & CALENDRIER DES SÉANCES ----------------
            $r1 = [];
            $m1 = ['A1:J1', 'A2:J2', 'A4:J4', 'A11:J11'];
            $r1[] = ['height' => 28, 'cells' => array_replace(array_fill(0, 10, ['v' => '', 's' => 1]), [0 => ['v' => 'BILAN OFFICIEL DU CYCLE — ' . mb_strtoupper($tontine_info['nom']) . ' (' . $tontine_info['ville'] . ')', 's' => 1]])];
            $r1[] = ['height' => 22, 'cells' => array_replace(array_fill(0, 10, ['v' => '', 's' => 2]), [0 => ['v' => 'Cycle : ' . $cycle_choisi['titre'] . '  |  Statut : ' . strtoupper($cycle_choisi['statut']) . '  |  Date d\'édition : ' . date_actuelle('d/m/Y H:i'), 's' => 2]])];
            $r1[] = ['height' => 12, 'cells' => []];
            $r1[] = ['height' => 24, 'cells' => array_replace(array_fill(0, 10, ['v' => '', 's' => 3]), [0 => ['v' => '1. PARAMÈTRES GÉNÉRAUX & SYNTHÈSE FINANCIÈRE DU CYCLE', 's' => 3]])];

            $kpi_rows = [
                ['Titre du Cycle', $cycle_choisi['titre'], 'Statut du Cycle', strtoupper($cycle_choisi['statut']), 'Fréquence', ucfirst($cycle_choisi['frequence'])],
                ['Cotisation / Séance', $montant_cotis_exp, 'Date de Début', date('d/m/Y', strtotime($cycle_choisi['date_debut'])), 'Date de Fin', !empty($cycle_choisi['date_fin']) ? date('d/m/Y', strtotime($cycle_choisi['date_fin'])) : 'En cours'],
                ['Pénalité Partielle (1x)', $pen_part_exp . ' FCFA (' . $pct_part_exp . ' %)', 'Pénalité Non-Cotisation (2x)', $pen_double_exp . ' FCFA (' . $pct_double_exp . ' %)', 'Séance Actuelle', 'Séance #' . $seance_max_ouverte],
                ['Total Attendu (Cycle)', ['v' => $total_attendu_cycle, 't' => 'num', 's' => 7], 'Total Collecté (Cycle)', ['v' => $total_collecte_cycle, 't' => 'num', 's' => 7], 'Reliquat à Recouvrer', ['v' => $total_reliquat_cycle, 't' => 'num', 's' => 7]],
                ['Solde Caisse Réserve (Cycle)', ['v' => $solde_caisse_exp, 't' => 'num', 's' => 8], 'Nombre de Séances', count($seances_bilan) . ' séances', 'Code Tontine', $tontine_info['code_tontine']]
            ];
            foreach ($kpi_rows as $kr) {
                $cells = [];
                for ($i = 0; $i < 6; $i++) {
                    $item = $kr[$i];
                    if ($i % 2 === 0) {
                        $cells[] = ['v' => $item, 's' => 11];
                    } elseif (is_array($item)) {
                        $cells[] = $item;
                    } elseif (is_numeric($item)) {
                        $cells[] = ['v' => $item, 't' => 'num', 's' => 7];
                    } else {
                        $cells[] = ['v' => (string)$item, 's' => 6];
                    }
                }
                $r1[] = ['height' => 21, 'cells' => $cells];
            }

            $r1[] = ['height' => 14, 'cells' => []];
            $r1[] = ['height' => 24, 'cells' => array_replace(array_fill(0, 10, ['v' => '', 's' => 3]), [0 => ['v' => '2. CALENDRIER DES SÉANCES & ORDRE DES BÉNÉFICIAIRES (BOUFFEURS)', 's' => 3]])];
            $r1[] = ['height' => 24, 'cells' => [
                ['v' => 'Séance', 's' => 4],
                ['v' => 'Période de la Séance', 's' => 4],
                ['v' => 'Date Échéance', 's' => 4],
                ['v' => 'Bénéficiaire (Bouffeur)', 's' => 4],
                ['v' => 'Canal & N° Paiement', 's' => 4],
                ['v' => 'Pot Attendu (FCFA)', 's' => 4],
                ['v' => 'Pot Collecté (FCFA)', 's' => 4],
                ['v' => 'Retenue sur Pot (FCFA)', 's' => 4],
                ['v' => 'Net Versé au Bouffeur (FCFA)', 's' => 4],
                ['v' => 'Statut de la Séance', 's' => 4],
            ]];

            $tot_ret_cycle = 0.0;
            $tot_net_cycle = 0.0;
            foreach ($seances_bilan as $sb) {
                $ns    = (int)$sb['numero_seance'];
                $per_s = formater_periode_seance($cycle_choisi['date_debut'], $ns, $cycle_choisi['frequence'], $sb['date_echeance']);
                $ret_s = obtenir_retenue_sur_pot($pdo, $cid, (int)$sb['bouffeur_user_id'], $ns);
                $net_s = max(0.0, (float)$sb['total_seance_paye'] - $ret_s);
                $tot_ret_cycle += $ret_s;
                $tot_net_cycle += $net_s;

                $est_act    = ($cycle_choisi['statut'] === 'actif' && $ns === $seance_max_ouverte);
                $est_fermee = ($cycle_choisi['statut'] === 'actif' && $ns > $seance_max_ouverte);
                $pot_ok     = ((int)$sb['nb_payes'] >= (int)$sb['nb_cotisations'] || (float)$sb['total_seance_paye'] + 0.01 >= (float)$sb['montant_pot_attendu'] || (int)$sb['pot_totalement_recu'] === 1);
                if ($pot_ok) {
                    $st_lbl = 'Pot complet (100%)';
                } elseif ($est_fermee) {
                    $st_lbl = 'Verrouillée';
                } elseif ($est_act) {
                    $st_lbl = 'Séance en cours';
                } else {
                    $st_lbl = 'En régularisation';
                }

                $r1[] = ['height' => 20, 'cells' => [
                    ['v' => 'Séance #' . $ns, 's' => 6],
                    ['v' => $per_s, 's' => 5],
                    ['v' => date('d/m/Y', strtotime($sb['date_echeance'])), 's' => 10],
                    ['v' => $sb['nom_bouffeur'], 's' => 6],
                    ['v' => strtoupper($sb['canal_paiement']) . ' : ' . $sb['telephone_paiement'], 's' => 5],
                    ['v' => (float)$sb['montant_pot_attendu'], 't' => 'num', 's' => 7],
                    ['v' => (float)$sb['total_seance_paye'], 't' => 'num', 's' => 7],
                    ['v' => $ret_s, 't' => 'num', 's' => 7],
                    ['v' => $net_s, 't' => 'num', 's' => 7],
                    ['v' => $st_lbl . ' (' . (int)$sb['nb_payes'] . '/' . (int)$sb['nb_cotisations'] . ')', 's' => 10],
                ]];
            }
            $r1[] = ['height' => 23, 'cells' => [
                ['v' => 'TOTAL CYCLE', 's' => 9],
                ['v' => count($seances_bilan) . ' séances', 's' => 9],
                ['v' => '', 's' => 9],
                ['v' => '', 's' => 9],
                ['v' => 'Cumul Global :', 's' => 9],
                ['v' => $total_attendu_cycle, 't' => 'num', 's' => 8],
                ['v' => $total_collecte_cycle, 't' => 'num', 's' => 8],
                ['v' => $tot_ret_cycle, 't' => 'num', 's' => 8],
                ['v' => $tot_net_cycle, 't' => 'num', 's' => 8],
                ['v' => 'Reliquat : ' . number_format($total_reliquat_cycle, 0, ',', ' ') . ' FCFA', 's' => 9],
            ]];

            // ---------------- FEUILLE 2 : DÉTAIL COMPLET SÉANCE PAR SÉANCE ----------------
            $r2 = [];
            $m2 = ['A1:I1', 'A2:I2'];
            $r2[] = ['height' => 28, 'cells' => array_replace(array_fill(0, 9, ['v' => '', 's' => 1]), [0 => ['v' => 'DÉTAIL DES COTISATIONS SÉANCE PAR SÉANCE — ' . mb_strtoupper($cycle_choisi['titre']), 's' => 1]])];
            $r2[] = ['height' => 22, 'cells' => array_replace(array_fill(0, 9, ['v' => '', 's' => 2]), [0 => ['v' => 'Tontine : ' . $tontine_info['nom'] . '  |  Chaque séance liste tous les membres participants, leurs versements et leurs reliquats', 's' => 2]])];

            foreach ($seances_bilan as $sb) {
                $ns    = (int)$sb['numero_seance'];
                $per_s = formater_periode_seance($cycle_choisi['date_debut'], $ns, $cycle_choisi['frequence'], $sb['date_echeance']);
                $ret_s = obtenir_retenue_sur_pot($pdo, $cid, (int)$sb['bouffeur_user_id'], $ns);
                $net_s = max(0.0, (float)$sb['total_seance_paye'] - $ret_s);

                $r2[] = ['height' => 12, 'cells' => []];
                $row_hdr_idx = count($r2) + 1;
                $m2[] = 'A' . $row_hdr_idx . ':I' . $row_hdr_idx;
                $info_ret_txt = $ret_s > 0 ? ('  |  Retenue sur pot : -' . number_format($ret_s, 0, ',', ' ') . ' FCFA (Net versé : ' . number_format($net_s, 0, ',', ' ') . ' FCFA)') : '';
                $r2[] = ['height' => 24, 'cells' => array_replace(array_fill(0, 9, ['v' => '', 's' => 3]), [0 => [
                    'v' => 'SÉANCE #' . $ns . ' (' . $per_s . ')  —  BOUFFEUR : ' . mb_strtoupper($sb['nom_bouffeur']) . ' (' . strtoupper($sb['canal_paiement']) . ' : ' . $sb['telephone_paiement'] . ')' . $info_ret_txt,
                    's' => 3
                ]])];

                $r2[] = ['height' => 22, 'cells' => [
                    ['v' => 'N°', 's' => 4],
                    ['v' => 'Membre Participant', 's' => 4],
                    ['v' => 'Téléphone', 's' => 4],
                    ['v' => 'Canal & N° Paiement', 's' => 4],
                    ['v' => 'Rôle dans la Séance', 's' => 4],
                    ['v' => 'Montant Attendu (FCFA)', 's' => 4],
                    ['v' => 'Montant Payé (FCFA)', 's' => 4],
                    ['v' => 'Reste à Payer (FCFA)', 's' => 4],
                    ['v' => 'Statut / Référence', 's' => 4],
                ]];

                $liste_c = $cotisants_par_seance[$ns] ?? [];
                $rang_ligne = 1;
                $st_att = 0.0;
                $st_pay = 0.0;
                $st_res = 0.0;
                foreach ($liste_c as $dc) {
                    $att = (float)$dc['montant_attendu'];
                    $pay = (float)$dc['montant_paye'];
                    $res = max(0.0, $att - $pay);
                    $st_att += $att;
                    $st_pay += $pay;
                    $st_res += $res;

                    $est_b = ((int)$dc['est_bouffeur'] === 1);
                    if ($est_b && $dc['statut'] === 'paye') {
                        $statut_txt = 'Sur Pot (Auto-compensé)';
                    } elseif ($dc['statut'] === 'paye') {
                        $statut_txt = 'Payé' . (!empty($dc['derniere_ref']) ? ' (' . $dc['derniere_ref'] . ')' : '');
                    } elseif ($dc['statut'] === 'en_retard') {
                        $statut_txt = 'En retard';
                    } elseif ($dc['statut'] === 'partiel') {
                        $statut_txt = 'Partiel';
                    } else {
                        $statut_txt = 'En attente';
                    }

                    $r2[] = ['height' => 20, 'cells' => [
                        ['v' => $rang_ligne++, 't' => 'num', 's' => 10],
                        ['v' => $dc['nom_complet'], 's' => $est_b ? 6 : 5],
                        ['v' => $dc['telephone'] ?? '', 's' => 5],
                        ['v' => strtoupper($dc['canal_paiement'] ?? 'momo') . ' : ' . ($dc['telephone_paiement'] ?? ''), 's' => 5],
                        ['v' => $est_b ? 'Bénéficiaire (Bouffeur)' : 'Cotisant', 's' => $est_b ? 6 : 5],
                        ['v' => $att, 't' => 'num', 's' => 7],
                        ['v' => $pay, 't' => 'num', 's' => 7],
                        ['v' => $res, 't' => 'num', 's' => 7],
                        ['v' => $statut_txt, 's' => 10],
                    ]];
                }

                $r2[] = ['height' => 22, 'cells' => [
                    ['v' => '', 's' => 9],
                    ['v' => 'SOUS-TOTAL SÉANCE #' . $ns, 's' => 9],
                    ['v' => '', 's' => 9],
                    ['v' => '', 's' => 9],
                    ['v' => (int)$sb['nb_payes'] . '/' . (int)$sb['nb_cotisations'] . ' soldés', 's' => 9],
                    ['v' => $st_att, 't' => 'num', 's' => 8],
                    ['v' => $st_pay, 't' => 'num', 's' => 8],
                    ['v' => $st_res, 't' => 'num', 's' => 8],
                    ['v' => $ret_s > 0 ? ('Net Bouffeur : ' . number_format($net_s, 0, ',', ' ') . ' FCFA') : 'Pot reversé : 100%', 's' => 9],
                ]];
            }

            // ---------------- FEUILLE 3 : CAISSE DE RÉSERVE & PÉNALITÉS ----------------
            $r3 = [];
            $m3 = ['A1:H1', 'A2:H2', 'A4:H4'];
            $r3[] = ['height' => 28, 'cells' => array_replace(array_fill(0, 8, ['v' => '', 's' => 1]), [0 => ['v' => 'CAISSE DE RÉSERVE, PÉNALITÉS & BONUS — ' . mb_strtoupper($cycle_choisi['titre']), 's' => 1]])];
            $r3[] = ['height' => 22, 'cells' => array_replace(array_fill(0, 8, ['v' => '', 's' => 2]), [0 => ['v' => 'Solde actuel de la Caisse de réserve du cycle : ' . number_format($solde_caisse_exp, 0, ',', ' ') . ' FCFA', 's' => 2]])];
            $r3[] = ['height' => 12, 'cells' => []];
            $r3[] = ['height' => 24, 'cells' => array_replace(array_fill(0, 8, ['v' => '', 's' => 3]), [0 => ['v' => 'HISTORIQUE DÉTAILLÉ DES OPÉRATIONS DE CAISSE PAR SÉANCE', 's' => 3]])];
            $r3[] = ['height' => 22, 'cells' => [
                ['v' => 'Séance', 's' => 4],
                ['v' => 'Membre Concerné', 's' => 4],
                ['v' => 'Téléphone', 's' => 4],
                ['v' => 'Type d\'Opération', 's' => 4],
                ['v' => 'Motif Détaillé', 's' => 4],
                ['v' => 'Montant (FCFA)', 's' => 4],
                ['v' => 'Statut', 's' => 4],
                ['v' => 'Date Opération / Règlement', 's' => 4],
            ]];

            $tot_pen_regle = 0.0;
            $tot_pen_att   = 0.0;
            if (empty($penalites_export)) {
                $r3[] = ['height' => 22, 'cells' => [
                    ['v' => '-', 's' => 10],
                    ['v' => 'Aucune pénalité ni bonus enregistré sur ce cycle.', 's' => 5],
                    ['v' => '', 's' => 5], ['v' => '', 's' => 5], ['v' => '', 's' => 5],
                    ['v' => 0, 't' => 'num', 's' => 7], ['v' => '-', 's' => 10], ['v' => '-', 's' => 10]
                ]];
            } else {
                foreach ($penalites_export as $pe) {
                    $mt_p = (float)$pe['montant'];
                    if ($pe['statut'] === 'regle') {
                        $tot_pen_regle += $mt_p;
                        $st_p = 'Réglé (En caisse)';
                    } elseif ($pe['statut'] === 'annule') {
                        $st_p = 'Annulé par l\'Admin';
                    } else {
                        $tot_pen_att += $mt_p;
                        $st_p = 'En attente';
                    }
                    $dt_op = !empty($pe['date_operation']) ? date('d/m/Y', strtotime($pe['date_operation'])) : '-';
                    $dt_rg = !empty($pe['date_reglement']) ? (' -> Réglé le ' . date('d/m/Y', strtotime($pe['date_reglement']))) : '';
                    $r3[] = ['height' => 20, 'cells' => [
                        ['v' => 'Séance #' . (int)($pe['numero_seance'] ?? 1), 's' => 6],
                        ['v' => $pe['nom_complet'], 's' => 6],
                        ['v' => $pe['telephone'], 's' => 5],
                        ['v' => $pe['type_operation'] === 'bonus' ? 'Bonus volontaire' : 'Pénalité de retard', 's' => 10],
                        ['v' => $pe['motif'], 's' => 5],
                        ['v' => $mt_p, 't' => 'num', 's' => 7],
                        ['v' => $st_p, 's' => 10],
                        ['v' => $dt_op . $dt_rg, 's' => 10],
                    ]];
                }
            }
            $r3[] = ['height' => 23, 'cells' => [
                ['v' => 'BILAN CAISSE', 's' => 9],
                ['v' => 'Solde Réglé en Caisse :', 's' => 9],
                ['v' => $solde_caisse_exp, 't' => 'num', 's' => 8],
                ['v' => 'Total En Attente :', 's' => 9],
                ['v' => $tot_pen_att, 't' => 'num', 's' => 8],
                ['v' => $tot_pen_regle, 't' => 'num', 's' => 8],
                ['v' => 'Encaissé', 's' => 9],
                ['v' => '', 's' => 9],
            ]];

            $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <numFmts count="1">
        <numFmt numFmtId="164" formatCode="#,##0\ &quot;FCFA&quot;"/>
    </numFmts>
    <fonts count="5">
        <font><sz val="10"/><color rgb="FF0F172A"/><name val="Calibri"/></font>
        <font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
        <font><b/><sz val="10"/><color rgb="FF0B241C"/><name val="Calibri"/></font>
        <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
        <font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Calibri"/></font>
    </fonts>
    <fills count="7">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF0B241C"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFE6F4EA"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF107C41"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border>
            <left style="thin"><color rgb="FFCBD5E1"/></left>
            <right style="thin"><color rgb="FFCBD5E1"/></right>
            <top style="thin"><color rgb="FFCBD5E1"/></top>
            <bottom style="thin"><color rgb="FFCBD5E1"/></bottom>
            <diagonal/>
        </border>
    </borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="12">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>
        <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>
        <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>
        <xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
        <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="164" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="4" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>
    </cellXfs>
</styleSheet>';

            $zip_entries = [
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>',
                '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>',
                'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="1_Synthese_et_Seances" sheetId="1" r:id="rId1"/>
        <sheet name="2_Detail_Par_Seance" sheetId="2" r:id="rId2"/>
        <sheet name="3_Caisse_et_Penalites" sheetId="3" r:id="rId3"/>
    </sheets>
</workbook>',
                'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>
    <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>',
                'xl/styles.xml' => $styles_xml,
                'xl/worksheets/sheet1.xml' => $build_sheet_xml($r1, [16, 28, 16, 26, 24, 20, 20, 20, 22, 24], $m1),
                'xl/worksheets/sheet2.xml' => $build_sheet_xml($r2, [8, 28, 18, 24, 24, 20, 20, 20, 28], $m2),
                'xl/worksheets/sheet3.xml' => $build_sheet_xml($r3, [14, 26, 18, 22, 42, 18, 22, 26], $m3),
            ];

            // Assemblage ZIP binaire en mémoire (100% autonome, sans besoin de l'extension php_zip)
            $local_parts   = [];
            $central_parts = [];
            $offset        = 0;
            foreach ($zip_entries as $name => $content) {
                $crc  = crc32($content);
                if ($crc < 0) {
                    $crc += 4294967296;
                }
                $comp = gzdeflate($content, 6);
                $len_raw  = strlen($content);
                $len_comp = strlen($comp);
                $len_name = strlen($name);

                $local_hdr = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, $len_comp, $len_raw, $len_name, 0);
                $local_parts[] = $local_hdr . $name . $comp;

                $central_hdr = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, $len_comp, $len_raw, $len_name, 0, 0, 0, 0, 32, $offset);
                $central_parts[] = $central_hdr . $name;
                $offset += strlen($local_hdr) + $len_name + $len_comp;
            }
            $central_dir = implode('', $central_parts);
            $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($zip_entries), count($zip_entries), strlen($central_dir), $offset, 0);
            $xlsx_binary = implode('', $local_parts) . $central_dir . $eocd;

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="Bilan_' . $slug_cycle . '.xlsx"');
            header('Content-Length: ' . strlen($xlsx_binary));
            header('Cache-Control: max-age=0');
            echo $xlsx_binary;
            exit;
        }

        // ====================================================================
        // EXPORT 2 : VRAI FICHIER PDF MULTI-PAGES (SOBRE, NOIR & BLANC, GRAS CIBLÉ)
        // ====================================================================
        if ($mode_export === 'pdf') {
            $pdf_encode = static function (string $s): string {
                $s = str_replace(['«', '»', '—', '–', '’', '…', '•'], ['"', '"', '-', '-', "'", '...', '-'], $s);
                if (function_exists('iconv')) {
                    $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
                    if ($c !== false) {
                        $s = $c;
                    }
                } else {
                    $s = utf8_decode($s);
                }
                return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
            };
            $trunc = static function (string $s, int $max): string {
                return mb_strlen($s, 'UTF-8') > $max ? (mb_substr($s, 0, $max - 1, 'UTF-8') . '.') : $s;
            };

            $pages_cmds = [];
            $cur_cmds   = [];
            $y          = 800.0;

            $start_new_page = static function () use (&$pages_cmds, &$cur_cmds, &$y): void {
                if (!empty($cur_cmds)) {
                    $pages_cmds[] = implode("\n", $cur_cmds);
                }
                $cur_cmds = [];
                $y = 800.0;
            };
            $rect_fill = static function (float $x, float $y_top, float $w, float $h, float $r, float $g, float $b, bool $stroke = true) use (&$cur_cmds): void {
                $yb = $y_top - $h;
                $op = $stroke ? 'B' : 'f';
                $cur_cmds[] = sprintf('%.3f %.3f %.3f rg 0.25 0.25 0.25 RG 0.5 w %.2f %.2f %.2f %.2f re %s', $r, $g, $b, $x, $yb, $w, $h, $op);
            };
            $text_at = static function (float $x, float $y_base, string $txt, int $size = 9, bool $bold = false, array $rgb = [0, 0, 0]) use (&$cur_cmds, $pdf_encode): void {
                $font = $bold ? '/F2' : '/F1';
                $cur_cmds[] = sprintf('BT %s %d Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET', $font, $size, $rgb[0], $rgb[1], $rgb[2], $x, $y_base, $pdf_encode($txt));
            };
            // $bold peut être true/false (toute la ligne) ou un tableau [true, false, ...] pour mettre en gras uniquement les colonnes importantes
            $draw_table_row = static function (array $cols, array $widths, float $row_h, array $bg_rgb, array $txt_rgb, $bold = false, int $font_size = 8) use (&$cur_cmds, &$y, $rect_fill, $text_at): void {
                $x = 30.0;
                foreach ($cols as $i => $val) {
                    $w = $widths[$i];
                    $is_col_bold = is_array($bold) ? !empty($bold[$i]) : (bool)$bold;
                    $rect_fill($x, $y, $w, $row_h, $bg_rgb[0], $bg_rgb[1], $bg_rgb[2], true);
                    $text_at($x + 4.0, $y - $row_h + 5.5, (string)$val, $font_size, $is_col_bold, $txt_rgb);
                    $x += $w;
                }
                $y -= $row_h;
            };

            $montant_cotis_exp = (float)$cycle_choisi['montant_cotisation'];
            $pen_part_exp      = (float)$cycle_choisi['montant_penalite_retard'];
            $pen_double_exp    = round($pen_part_exp * 2, 2);
            $pct_part_exp      = $montant_cotis_exp > 0 ? round(($pen_part_exp / $montant_cotis_exp) * 100, 1) : 0;
            $pct_double_exp    = round($pct_part_exp * 2, 1);
            $solde_caisse_exp  = (float)($cycle_choisi['solde_caisse_reserve'] ?? 0);

            // En-tête Page 1 : Sobre, fond blanc encadré, titres en gras noir
            $rect_fill(30, $y, 535, 46, 1.0, 1.0, 1.0, true);
            $text_at(40, $y - 19, 'BILAN OFFICIEL DU CYCLE - ' . mb_strtoupper($tontine_info['nom']) . ' (' . $tontine_info['ville'] . ')', 13, true, [0, 0, 0]);
            $text_at(40, $y - 35, 'Cycle : ' . $cycle_choisi['titre'] . '  |  Statut : ' . strtoupper($cycle_choisi['statut']) . '  |  Edite le ' . date_actuelle('d/m/Y H:i'), 9, true, [0, 0, 0]);
            $y -= 56;

            // Section 1 : Synthèse du cycle (libellés en normal, valeurs importantes en gras)
            $rect_fill(30, $y, 535, 20, 0.93, 0.93, 0.93, true);
            $text_at(38, $y - 14, '1. PARAMETRES GENERAUX & SYNTHESE FINANCIERE DU CYCLE', 10, true, [0, 0, 0]);
            $y -= 20;

            $w_kpi = [135, 132.5, 135, 132.5];
            $draw_table_row(['Cotisation / Seance', format_fcfa($montant_cotis_exp), 'Frequence', ucfirst($cycle_choisi['frequence'])], $w_kpi, 18, [1, 1, 1], [0, 0, 0], [false, true, false, true], 8);
            $draw_table_row(['Date de debut', date('d/m/Y', strtotime($cycle_choisi['date_debut'])), 'Date de fin prevue', !empty($cycle_choisi['date_fin']) ? date('d/m/Y', strtotime($cycle_choisi['date_fin'])) : 'En cours'], $w_kpi, 18, [1, 1, 1], [0, 0, 0], [false, true, false, true], 8);
            $draw_table_row(['Penalite partielle (1x)', format_fcfa($pen_part_exp) . ' (' . $pct_part_exp . '%)', 'Non-cotisation (2x)', format_fcfa($pen_double_exp) . ' (' . $pct_double_exp . '%)'], $w_kpi, 18, [1, 1, 1], [0, 0, 0], [false, true, false, true], 8);
            $draw_table_row(['Total Attendu (Cycle)', format_fcfa($total_attendu_cycle), 'Total Collecte (Cycle)', format_fcfa($total_collecte_cycle)], $w_kpi, 18, [1, 1, 1], [0, 0, 0], [true, true, true, true], 8);
            $draw_table_row(['Reliquat a recouvrer', format_fcfa($total_reliquat_cycle), 'Solde Caisse Reserve', format_fcfa($solde_caisse_exp)], $w_kpi, 18, [1, 1, 1], [0, 0, 0], [true, true, true, true], 8);
            $y -= 14;

            // Section 2 : Calendrier récapitulatif des séances
            $rect_fill(30, $y, 535, 20, 0.93, 0.93, 0.93, true);
            $text_at(38, $y - 14, '2. CALENDRIER DES SEANCES & ORDRE DES BENEFICIAIRES (BOUFFEURS)', 10, true, [0, 0, 0]);
            $y -= 20;

            $w_sec = [42, 118, 115, 85, 95, 80];
            $draw_table_row(['Seance', 'Periode / Echeance', 'Beneficiaire (Bouffeur)', 'Pot Collecte / Att.', 'Retenue / Net', 'Statut'], $w_sec, 19, [0.95, 0.95, 0.95], [0, 0, 0], true, 8);

            foreach ($seances_bilan as $sb) {
                if ($y < 80) {
                    $start_new_page();
                    $draw_table_row(['Seance', 'Periode / Echeance', 'Beneficiaire (Bouffeur)', 'Pot Collecte / Att.', 'Retenue / Net', 'Statut'], $w_sec, 19, [0.95, 0.95, 0.95], [0, 0, 0], true, 8);
                }
                $ns    = (int)$sb['numero_seance'];
                $per_s = formater_periode_seance($cycle_choisi['date_debut'], $ns, $cycle_choisi['frequence'], $sb['date_echeance']);
                $ret_s = obtenir_retenue_sur_pot($pdo, $cid, (int)$sb['bouffeur_user_id'], $ns);
                $net_s = max(0.0, (float)$sb['total_seance_paye'] - $ret_s);

                $est_act    = ($cycle_choisi['statut'] === 'actif' && $ns === $seance_max_ouverte);
                $est_fermee = ($cycle_choisi['statut'] === 'actif' && $ns > $seance_max_ouverte);
                $pot_ok     = ((int)$sb['nb_payes'] >= (int)$sb['nb_cotisations'] || (float)$sb['total_seance_paye'] + 0.01 >= (float)$sb['montant_pot_attendu'] || (int)$sb['pot_totalement_recu'] === 1);
                $st_lbl     = $pot_ok ? 'Pot complet' : ($est_fermee ? 'Verrouillee' : ($est_act ? 'En cours' : 'Regularisation'));

                // Gras sur : N° Séance, Nom du Bouffeur, Montant Pot et Statut
                $draw_table_row([
                    '#' . $ns,
                    $trunc($per_s, 26),
                    $trunc($sb['nom_bouffeur'], 23),
                    number_format((float)$sb['total_seance_paye'], 0, ',', ' ') . ' / ' . number_format((float)$sb['montant_pot_attendu'], 0, ',', ' '),
                    $ret_s > 0 ? ('-' . number_format($ret_s, 0, ',', ' ') . ' | Net:' . number_format($net_s, 0, ',', ' ')) : ('Net: ' . number_format($net_s, 0, ',', ' ')),
                    $st_lbl . ' (' . (int)$sb['nb_payes'] . '/' . (int)$sb['nb_cotisations'] . ')'
                ], $w_sec, 18, [1, 1, 1], [0, 0, 0], [true, false, true, true, ($ret_s > 0), true], 7);
            }
            $y -= 16;

            // Section 3 : Détail de chaque séance
            if ($y < 140) {
                $start_new_page();
            }
            $rect_fill(30, $y, 535, 20, 0.93, 0.93, 0.93, true);
            $text_at(38, $y - 14, '3. DETAIL COMPLET DES COTISATIONS SEANCE PAR SEANCE', 10, true, [0, 0, 0]);
            $y -= 24;

            $w_det = [28, 142, 85, 80, 65, 65, 70];
            foreach ($seances_bilan as $sb) {
                $ns      = (int)$sb['numero_seance'];
                $liste_c = $cotisants_par_seance[$ns] ?? [];
                $need_h  = 42 + (count($liste_c) + 1) * 17;
                if ($y - $need_h < 55) {
                    $start_new_page();
                }
                $per_s = formater_periode_seance($cycle_choisi['date_debut'], $ns, $cycle_choisi['frequence'], $sb['date_echeance']);
                $ret_s = obtenir_retenue_sur_pot($pdo, $cid, (int)$sb['bouffeur_user_id'], $ns);
                $net_s = max(0.0, (float)$sb['total_seance_paye'] - $ret_s);

                $rect_fill(30, $y, 535, 19, 0.95, 0.95, 0.95, true);
                $hdr_s = 'SEANCE #' . $ns . ' (' . $per_s . ')  |  Bouffeur : ' . $sb['nom_bouffeur'] . '  |  Pot : ' . format_fcfa($sb['total_seance_paye']) . ' / ' . format_fcfa($sb['montant_pot_attendu']);
                if ($ret_s > 0) {
                    $hdr_s .= ' (Retenue: -' . format_fcfa($ret_s) . ', Net: ' . format_fcfa($net_s) . ')';
                }
                $text_at(36, $y - 13, $trunc($hdr_s, 108), 8, true, [0, 0, 0]);
                $y -= 19;

                $draw_table_row(['N°', 'Membre Participant', 'Telephone', 'Role', 'Attendu', 'Paye', 'Statut'], $w_det, 17, [0.95, 0.95, 0.95], [0, 0, 0], true, 8);
                $num_l = 1;
                foreach ($liste_c as $dc) {
                    if ($y < 60) {
                        $start_new_page();
                        $draw_table_row(['N°', 'Membre Participant', 'Telephone', 'Role', 'Attendu', 'Paye', 'Statut'], $w_det, 17, [0.95, 0.95, 0.95], [0, 0, 0], true, 8);
                    }
                    $est_b = ((int)$dc['est_bouffeur'] === 1);
                    $st_c  = ($est_b && $dc['statut'] === 'paye') ? 'Sur Pot' : ucfirst(str_replace('_', ' ', $dc['statut']));
                    // Le bouffeur de la séance est entièrement en gras ; pour les cotisants, le nom, le montant payé et le statut sont en gras
                    $bold_cols = $est_b ? true : [false, true, false, false, false, true, true];
                    $draw_table_row([
                        (string)$num_l++,
                        $trunc($dc['nom_complet'], 28),
                        $dc['telephone'] ?? '',
                        $est_b ? 'Bouffeur' : 'Cotisant',
                        number_format((float)$dc['montant_attendu'], 0, ',', ' '),
                        number_format((float)$dc['montant_paye'], 0, ',', ' '),
                        $st_c
                    ], $w_det, 16, [1, 1, 1], [0, 0, 0], $bold_cols, 7);
                }
                $y -= 10;
            }

            // Section 4 : Caisse de réserve & Pénalités
            if ($y < 130) {
                $start_new_page();
            }
            $rect_fill(30, $y, 535, 20, 0.93, 0.93, 0.93, true);
            $text_at(38, $y - 14, '4. JOURNAL DE LA CAISSE DE RESERVE & PENALITES DU CYCLE', 10, true, [0, 0, 0]);
            $y -= 20;

            $w_pen = [45, 115, 70, 165, 70, 70];
            $draw_table_row(['Seance', 'Membre', 'Type', 'Motif', 'Montant', 'Statut'], $w_pen, 18, [0.95, 0.95, 0.95], [0, 0, 0], true, 8);
            if (empty($penalites_export)) {
                $draw_table_row(['-', 'Aucune penalite ni bonus sur ce cycle', '-', '-', '0 FCFA', '-'], $w_pen, 17, [1, 1, 1], [0, 0, 0], false, 8);
            } else {
                foreach ($penalites_export as $pe) {
                    if ($y < 60) {
                        $start_new_page();
                        $draw_table_row(['Seance', 'Membre', 'Type', 'Motif', 'Montant', 'Statut'], $w_pen, 18, [0.95, 0.95, 0.95], [0, 0, 0], true, 8);
                    }
                    $draw_table_row([
                        '#' . (int)($pe['numero_seance'] ?? 1),
                        $trunc($pe['nom_complet'], 22),
                        ucfirst($pe['type_operation']),
                        $trunc($pe['motif'], 34),
                        format_fcfa($pe['montant']),
                        ucfirst(str_replace('_', ' ', $pe['statut']))
                    ], $w_pen, 16, [1, 1, 1], [0, 0, 0], [true, true, false, false, true, true], 7);
                }
            }

            if (!empty($cur_cmds)) {
                $pages_cmds[] = implode("\n", $cur_cmds);
            }

            // Construction du binaire PDF 1.4 multi-pages
            $nb_pages = count($pages_cmds);
            $objects  = [];
            $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
            $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
            $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

            $kids = [];
            $obj_id = 5;
            foreach ($pages_cmds as $p_idx => $cmds_str) {
                $p_num = $p_idx + 1;
                $footer_cmd = sprintf(
                    "\n0.30 0.30 0.30 RG 0.5 w 30 38 m 565 38 l S\nBT /F1 8 Tf 0 0 0 rg 30 26 Td (%s) Tj 470 0 Td (Page %d / %d) Tj ET",
                    $pdf_encode('Projet Djangui - Bilan Officiel ' . $tontine_info['nom'] . ' (' . $cycle_choisi['titre'] . ')'),
                    $p_num,
                    $nb_pages
                );
                $stream = $cmds_str . $footer_cmd;
                $content_id = $obj_id++;
                $page_id    = $obj_id++;
                $objects[$content_id] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
                $objects[$page_id]    = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$content_id} 0 R >>";
                $kids[] = "{$page_id} 0 R";
            }
            $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$nb_pages} >>";
            ksort($objects);

            $pdf_out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
            $offsets = [];
            foreach ($objects as $id => $body) {
                $offsets[$id] = strlen($pdf_out);
                $pdf_out .= "{$id} 0 obj\n{$body}\nendobj\n";
            }
            $xref_pos = strlen($pdf_out);
            $max_id   = max(array_keys($objects));
            $pdf_out .= "xref\n0 " . ($max_id + 1) . "\n0000000000 65535 f \n";
            for ($i = 1; $i <= $max_id; $i++) {
                $pdf_out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
            }
            $pdf_out .= "trailer\n<< /Size " . ($max_id + 1) . " /Root 1 0 R >>\nstartxref\n{$xref_pos}\n%%EOF";

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="Bilan_' . $slug_cycle . '.pdf"');
            header('Content-Length: ' . strlen($pdf_out));
            header('Cache-Control: max-age=0');
            echo $pdf_out;
            exit;
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
            <?php if ($cycle_choisi): ?>
                <a href="cycles.php?cycle_id=<?= (int)$cycle_choisi['id'] ?>&export=excel" class="btn btn-sm btn-success fw-semibold shadow-sm" title="Télécharger le bilan complet du cycle et de chaque séance au format Excel (.xlsx)">
                    <i class="bi bi-file-earmark-excel-fill me-1"></i>Excel (.xlsx)
                </a>
                <a href="cycles.php?cycle_id=<?= (int)$cycle_choisi['id'] ?>&export=pdf" class="btn btn-sm btn-danger fw-semibold shadow-sm" title="Télécharger le bilan complet du cycle et de chaque séance au format PDF (.pdf)">
                    <i class="bi bi-file-earmark-pdf-fill me-1"></i>PDF (.pdf)
                </a>
            <?php endif; ?>
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
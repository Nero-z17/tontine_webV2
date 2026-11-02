-- ============================================================================
-- PROJET DJANGUI - SCRIPT SQL FINAL (SCHÉMA + DONNÉES DE DÉMONSTRATION)
-- Compatible XAMPP / WAMP (MySQL 8.0+ / MariaDB 10.4+ / PHP 8.2+)
-- ============================================================================

SET NAMES utf8mb4;
SET TIME_ZONE = '+01:00'; -- Fuseau horaire du Cameroun (WAT)

DROP DATABASE IF EXISTS djangui_db;
CREATE DATABASE djangui_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE djangui_db;

-- ============================================================================
-- 1. TABLE : users (Comptes utilisateurs : Secrétaire/Admin et Membres)
-- ============================================================================
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_public VARCHAR(24) NOT NULL COMMENT 'Identifiant public du membre',
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nom_complet VARCHAR(120) NOT NULL,
    telephone VARCHAR(20) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    numero_cni VARCHAR(60) DEFAULT NULL COMMENT 'Donnee sensible visible uniquement par l admin',
    role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
    statut_membre ENUM('actif', 'inactif', 'sorti') NOT NULL DEFAULT 'actif',
    statut_validation ENUM('en_attente', 'accepte', 'refuse') NOT NULL DEFAULT 'accepte',
    canal_paiement ENUM('momo', 'om', 'especes') NOT NULL DEFAULT 'momo',
    telephone_paiement VARCHAR(20) NOT NULL COMMENT 'Numero MoMo/OM qui envoie les cotisations et recoit le pot',
    tentatives_echouees TINYINT UNSIGNED NOT NULL DEFAULT 0,
    blocage_jusqua DATETIME DEFAULT NULL,
    reset_token VARCHAR(100) DEFAULT NULL,
    reset_expire_at DATETIME DEFAULT NULL,
    tontine_id INT UNSIGNED DEFAULT NULL COMMENT '1 seule tontine autorisee par compte',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_users_code_public UNIQUE (code_public),
    CONSTRAINT uq_users_username UNIQUE (username),
    CONSTRAINT uq_users_telephone UNIQUE (telephone),
    CONSTRAINT uq_users_email UNIQUE (email),
    INDEX idx_users_tontine_statut (tontine_id, statut_membre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. TABLE : tontines (La tontine et son essai SaaS — la caisse est rattachée à chaque cycle)
-- ============================================================================
CREATE TABLE tontines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_tontine VARCHAR(20) NOT NULL,
    code_invitation VARCHAR(24) NOT NULL,
    nom VARCHAR(120) NOT NULL,
    description TEXT DEFAULT NULL,
    ville VARCHAR(80) NOT NULL DEFAULT 'Douala',
    devise CHAR(4) NOT NULL DEFAULT 'FCFA',
    statut ENUM('active', 'suspendue', 'fermee') NOT NULL DEFAULT 'active',
    date_debut_essai DATETIME NOT NULL,
    date_fin_essai DATETIME NOT NULL,
    statut_abonnement ENUM('essai', 'actif', 'expire') NOT NULL DEFAULT 'essai',
    abonnement_valide_jusqua DATETIME DEFAULT NULL,
    admin_id INT UNSIGNED NOT NULL COMMENT 'UNIQUE : 1 admin/secretaire ne gere qu une seule tontine',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_tontines_code UNIQUE (code_tontine),
    CONSTRAINT uq_tontines_invitation UNIQUE (code_invitation),
    CONSTRAINT uq_tontines_admin UNIQUE (admin_id),
    CONSTRAINT fk_tontines_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_tontines_statut (statut, statut_abonnement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liaison de users vers tontines une fois les deux tables créées
ALTER TABLE users
    ADD CONSTRAINT fk_users_tontine
    FOREIGN KEY (tontine_id) REFERENCES tontines(id)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- ============================================================================
-- 3. TABLE : cycles (Cycles successifs de cotisation + Caisse de réserve propre au cycle)
-- ============================================================================
CREATE TABLE cycles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tontine_id INT UNSIGNED NOT NULL,
    numero_cycle INT UNSIGNED NOT NULL,
    titre VARCHAR(100) NOT NULL,
    montant_cotisation DECIMAL(12,2) NOT NULL,
    frequence ENUM('hebdo', 'bimensuel', 'mensuel') NOT NULL DEFAULT 'mensuel',
    montant_penalite_retard DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Penalite pour cotisation partielle (la non-cotisation vaut le double : 2x)',
    solde_caisse_reserve DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Caisse de reserve propre a ce cycle uniquement (Point 6)',
    date_debut DATE NOT NULL,
    date_fin DATE DEFAULT NULL,
    statut ENUM('brouillon', 'actif', 'termine', 'annule') NOT NULL DEFAULT 'brouillon',
    date_tirage DATETIME DEFAULT NULL,
    tirage_verrouille TINYINT(1) NOT NULL DEFAULT 0,
    auteur_tirage_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_cycles_tontine_numero UNIQUE (tontine_id, numero_cycle),
    CONSTRAINT chk_cycles_montant CHECK (montant_cotisation > 0),
    CONSTRAINT chk_cycles_penalite CHECK (montant_penalite_retard >= 0),
    CONSTRAINT chk_cycles_caisse CHECK (solde_caisse_reserve >= 0),
    CONSTRAINT fk_cycles_tontine FOREIGN KEY (tontine_id) REFERENCES tontines(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cycles_auteur_tirage FOREIGN KEY (auteur_tirage_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_cycles_tontine_statut (tontine_id, statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. TABLE : cycle_members (Participants au cycle + Rang + Suivi du Pot)
-- ============================================================================
CREATE TABLE cycle_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rang_beneficiaire INT UNSIGNED DEFAULT NULL COMMENT 'Ordre du tirage au sort (1, 2, 3...)',
    date_prevue_pot DATE DEFAULT NULL,
    montant_pot_attendu DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Total que ce bouffeur doit recevoir sur sa seance',
    montant_pot_recu DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Cumul reel deja recu des cotisants',
    pot_totalement_recu TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 si montant_pot_recu >= montant_pot_attendu',
    date_reception_pot DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_cycle_member_unique UNIQUE (cycle_id, user_id),
    CONSTRAINT uq_cycle_rang_unique UNIQUE (cycle_id, rang_beneficiaire),
    CONSTRAINT fk_cm_cycle FOREIGN KEY (cycle_id) REFERENCES cycles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_cm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. TABLE : contributions (Cotisations attendues par séance et par membre)
-- ============================================================================
CREATE TABLE contributions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_member_id INT UNSIGNED NOT NULL COMMENT 'Le membre qui doit payer cette cotisation',
    beneficiaire_cycle_member_id INT UNSIGNED NOT NULL COMMENT 'Le bouffeur de cette seance qui recoit les fonds',
    numero_seance INT UNSIGNED NOT NULL,
    date_echeance DATE NOT NULL,
    montant_attendu DECIMAL(12,2) NOT NULL,
    montant_paye DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    statut ENUM('en_attente', 'partiel', 'paye', 'en_retard') NOT NULL DEFAULT 'en_attente',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_contrib_membre_seance UNIQUE (cycle_member_id, numero_seance),
    CONSTRAINT chk_contrib_attendu CHECK (montant_attendu > 0),
    CONSTRAINT chk_contrib_paye CHECK (montant_paye >= 0),
    CONSTRAINT fk_contrib_payeur FOREIGN KEY (cycle_member_id) REFERENCES cycle_members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_contrib_bouffeur FOREIGN KEY (beneficiaire_cycle_member_id) REFERENCES cycle_members(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_contrib_echeance_statut (date_echeance, statut),
    INDEX idx_contrib_bouffeur (beneficiaire_cycle_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. TABLE : payments (Paiements effectués par les membres dans leur espace)
-- ============================================================================
CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contribution_id INT UNSIGNED NOT NULL,
    paye_par_user_id INT UNSIGNED NOT NULL COMMENT 'Le membre connecte qui a initie le paiement',
    montant_total DECIMAL(12,2) NOT NULL COMMENT 'Montant total debourse par le membre',
    part_beneficiaire DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Part versee au bouffeur (actuel ou ancien) de la seance',
    part_caisse_reserve DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Part versee a la caisse de reserve du cycle (penalite)',
    mode_paiement ENUM('momo', 'om', 'especes') NOT NULL DEFAULT 'momo',
    reference_paiement VARCHAR(80) DEFAULT NULL,
    date_paiement DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    est_en_retard TINYINT(1) NOT NULL DEFAULT 0,
    statut_paiement ENUM('valide', 'en_attente', 'rejete') NOT NULL DEFAULT 'valide',
    commentaire VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_payments_total CHECK (montant_total > 0),
    CONSTRAINT chk_payments_ventilation CHECK (montant_total = part_beneficiaire + part_caisse_reserve),
    CONSTRAINT fk_payments_contribution FOREIGN KEY (contribution_id) REFERENCES contributions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payments_payeur FOREIGN KEY (paye_par_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_payments_contribution (contribution_id),
    INDEX idx_payments_payeur (paye_par_user_id, date_paiement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. TABLE : penalties (Pénalités progressives par séance et Bonus du cycle)
-- ============================================================================
CREATE TABLE penalties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_member_id INT UNSIGNED NOT NULL,
    contribution_id INT UNSIGNED DEFAULT NULL COMMENT 'Cotisation en retard d origine liee a cette penalite',
    numero_seance INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Seance a laquelle cette penalite, majoration ou ce bonus est rattache (Points 8 & 9)',
    type_operation ENUM('penalite', 'bonus') NOT NULL,
    montant DECIMAL(12,2) NOT NULL,
    motif VARCHAR(255) NOT NULL,
    statut ENUM('en_attente', 'regle', 'annule') NOT NULL DEFAULT 'en_attente',
    date_operation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_reglement DATETIME DEFAULT NULL,
    auteur_id INT UNSIGNED NOT NULL,

    CONSTRAINT chk_penalties_montant CHECK (montant > 0),
    CONSTRAINT fk_penalties_cycle_member FOREIGN KEY (cycle_member_id) REFERENCES cycle_members(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_penalties_contrib FOREIGN KEY (contribution_id) REFERENCES contributions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_penalties_auteur FOREIGN KEY (auteur_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_penalties_membre_statut (cycle_member_id, statut),
    INDEX idx_penalties_seance (cycle_member_id, numero_seance, statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. TABLE : meetings (Réunions programmées et liens Zoom)
-- ============================================================================
CREATE TABLE meetings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tontine_id INT UNSIGNED NOT NULL,
    titre VARCHAR(150) NOT NULL,
    date_heure DATETIME NOT NULL,
    lien_zoom VARCHAR(500) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    statut ENUM('brouillon', 'publiee', 'terminee', 'annulee') NOT NULL DEFAULT 'brouillon',
    cree_par_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_meetings_tontine FOREIGN KEY (tontine_id) REFERENCES tontines(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_meetings_admin FOREIGN KEY (cree_par_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_meetings_tontine_date (tontine_id, statut, date_heure)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. TABLE : subscriptions (Abonnement SaaS : 2 mois gratuits puis 2000 FCFA/mois)
-- ============================================================================
CREATE TABLE subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tontine_id INT UNSIGNED NOT NULL,
    type_periode ENUM('essai', 'mensuel') NOT NULL DEFAULT 'mensuel',
    montant DECIMAL(10,2) NOT NULL DEFAULT 2000.00,
    date_debut DATETIME NOT NULL,
    date_fin DATETIME NOT NULL,
    statut_paiement ENUM('essai', 'paye', 'en_attente', 'simule') NOT NULL DEFAULT 'simule',
    reference_transaction VARCHAR(80) DEFAULT NULL,
    paye_par_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_sub_montant CHECK (montant >= 0),
    CONSTRAINT fk_sub_tontine FOREIGN KEY (tontine_id) REFERENCES tontines(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sub_user FOREIGN KEY (paye_par_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_sub_tontine_fin (tontine_id, date_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. TABLE : admin_transfers (Historique des passations d'administration)
-- ============================================================================
CREATE TABLE admin_transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tontine_id INT UNSIGNED NOT NULL,
    ancien_admin_id INT UNSIGNED NOT NULL,
    nouvel_admin_id INT UNSIGNED NOT NULL,
    ancien_reste_membre TINYINT(1) NOT NULL DEFAULT 1,
    motif VARCHAR(255) DEFAULT NULL,
    date_transfert DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_transfers_tontine FOREIGN KEY (tontine_id) REFERENCES tontines(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_transfers_old_admin FOREIGN KEY (ancien_admin_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_transfers_new_admin FOREIGN KEY (nouvel_admin_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_transfers_tontine (tontine_id, date_transfert)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DONNÉES DE DÉMONSTRATION (Contexte Cameroun - Mot de passe : "password")
-- ============================================================================

-- 1. Utilisateurs : Marie-Claire Ngono (id=1) est la Secrétaire/Admin ET membre cotisante
INSERT INTO users (id, code_public, username, password_hash, nom_complet, telephone, email, numero_cni, role, statut_membre, canal_paiement, telephone_paiement) VALUES
(1, 'DJG-2026-SEC01', 'ngono_secretaire', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Marie-Claire Ngono', '+237699223344', 'mc.ngono@djangui.cm', 'CNI-CE-554433221', 'admin', 'actif', 'om', '+237699223344'),
(2, 'DJG-2026-MBR02', 'fotso_eric', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Éric Fotso', '+237670334455', 'eric.fotso@djangui.cm', 'CNI-OU-998877665', 'member', 'actif', 'momo', '+237670334455'),
(3, 'DJG-2026-MBR03', 'kamga_jp', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jean-Paul Kamga', '+237677112233', 'jp.kamga@djangui.cm', 'CNI-LT-102938475', 'member', 'actif', 'momo', '+237677112233'),
(4, 'DJG-2026-MBR04', 'eboue_nadine', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nadine Eboué', '+237691445566', 'nadine.eboue@djangui.cm', 'CNI-LT-443322110', 'member', 'actif', 'om', '+237691445566'),
(5, 'DJG-2026-MBR05', 'mbarga_paul', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Paul Mbarga', '+237655667788', 'paul.mbarga@djangui.cm', 'CNI-CE-112233445', 'member', 'actif', 'momo', '+237655667788');

-- 2. Tontine
INSERT INTO tontines (id, code_tontine, code_invitation, nom, description, ville, devise, statut, date_debut_essai, date_fin_essai, statut_abonnement, abonnement_valide_jusqua, admin_id, created_at) VALUES
(1, 'TON-DLA-2026', 'INV-AKWA-2026', 'Djangui Solidarité Akwa', 'Tontine mensuelle à 50 000 FCFA. Chaque cycle dispose de sa propre caisse de réserve alimentée par les pénalités et bonus.', 'Douala', 'FCFA', 'active', '2026-07-01 08:00:00', '2026-09-01 08:00:00', 'actif', '2026-11-01 23:59:59', 1, '2026-07-01 08:00:00');

UPDATE users SET tontine_id = 1 WHERE id IN (1, 2, 3, 4, 5);

-- 3. Cycles : Cycle 1 (terminé, caisse = 10 000 FCFA) et Cycle 2 (actif, caisse = 7 500 FCFA)
-- Pénalité cotisation partielle = 2 500 FCFA (5%) -> Pénalité non-cotisation = 5 000 FCFA (10%)
INSERT INTO cycles (id, tontine_id, numero_cycle, titre, montant_cotisation, frequence, montant_penalite_retard, solde_caisse_reserve, date_debut, date_fin, statut, date_tirage, tirage_verrouille, auteur_tirage_id) VALUES
(1, 1, 1, 'Cycle 1 - Printemps 2026 (Terminé)', 50000.00, 'mensuel', 2500.00, 10000.00, '2026-04-01', '2026-08-31', 'termine', '2026-04-01 10:00:00', 1, 1),
(2, 1, 2, 'Cycle 2 - Automne 2026 (Actif)', 50000.00, 'mensuel', 2500.00, 7500.00, '2026-09-01', '2027-01-01', 'actif', '2026-09-01 18:30:00', 1, 1);

-- 4. Participants au Cycle 2 (5 membres x 50 000 = Pot attendu de 250 000 FCFA par tour)
INSERT INTO cycle_members (id, cycle_id, user_id, rang_beneficiaire, date_prevue_pot, montant_pot_attendu, montant_pot_recu, pot_totalement_recu, date_reception_pot) VALUES
(1, 2, 2, 1, '2026-09-01', 250000.00, 250000.00, 1, '2026-09-12 14:30:00'),
(2, 2, 1, 2, '2026-10-01', 250000.00, 200000.00, 0, NULL),
(3, 2, 3, 3, '2026-11-01', 250000.00, 0.00, 0, NULL),
(4, 2, 4, 4, '2026-12-01', 250000.00, 0.00, 0, NULL),
(5, 2, 5, 5, '2027-01-01', 250000.00, 0.00, 0, NULL);

-- 5. Cotisations attendues (Séances 1 à 5 du Cycle 2)
INSERT INTO contributions (id, cycle_member_id, beneficiaire_cycle_member_id, numero_seance, date_echeance, montant_attendu, montant_paye, statut) VALUES
-- Séance 1 (01/09/2026) : Bouffeur = Éric (cm_id = 1)
(1, 1, 1, 1, '2026-09-01', 50000.00, 50000.00, 'paye'),
(2, 2, 1, 1, '2026-09-01', 50000.00, 50000.00, 'paye'),
(3, 3, 1, 1, '2026-09-01', 50000.00, 50000.00, 'paye'),
(4, 4, 1, 1, '2026-09-01', 50000.00, 50000.00, 'paye'),
(5, 5, 1, 1, '2026-09-01', 50000.00, 50000.00, 'paye'),
-- Séance 2 (Du 02/09/2026 au 01/10/2026) : Bouffeur = Marie-Claire (cm_id = 2)
(6, 1, 2, 2, '2026-10-01', 50000.00, 50000.00, 'paye'),
(7, 2, 2, 2, '2026-10-01', 50000.00, 50000.00, 'paye'),
(8, 3, 2, 2, '2026-10-01', 50000.00, 50000.00, 'paye'),
(9, 4, 2, 2, '2026-10-01', 50000.00, 30000.00, 'partiel'),
(10, 5, 2, 2, '2026-10-01', 50000.00, 20000.00, 'en_retard'),
-- Séance 3 (Du 02/10/2026 au 01/11/2026) : Bouffeur = Jean-Paul (cm_id = 3)
(11, 1, 3, 3, '2026-11-01', 50000.00, 0.00, 'en_attente'),
(12, 2, 3, 3, '2026-11-01', 50000.00, 0.00, 'en_attente'),
(13, 3, 3, 3, '2026-11-01', 50000.00, 0.00, 'en_attente'),
(14, 4, 3, 3, '2026-11-01', 50000.00, 0.00, 'en_attente'),
(15, 5, 3, 3, '2026-11-01', 50000.00, 0.00, 'en_attente'),
-- Séance 4 (Du 02/11/2026 au 01/12/2026) : Bouffeur = Nadine (cm_id = 4)
(16, 1, 4, 4, '2026-12-01', 50000.00, 0.00, 'en_attente'),
(17, 2, 4, 4, '2026-12-01', 50000.00, 0.00, 'en_attente'),
(18, 3, 4, 4, '2026-12-01', 50000.00, 0.00, 'en_attente'),
(19, 4, 4, 4, '2026-12-01', 50000.00, 0.00, 'en_attente'),
(20, 5, 4, 4, '2026-12-01', 50000.00, 0.00, 'en_attente'),
-- Séance 5 (Du 02/12/2026 au 01/01/2027) : Bouffeur = Paul (cm_id = 5)
(21, 1, 5, 5, '2027-01-01', 50000.00, 0.00, 'en_attente'),
(22, 2, 5, 5, '2027-01-01', 50000.00, 0.00, 'en_attente'),
(23, 3, 5, 5, '2027-01-01', 50000.00, 0.00, 'en_attente'),
(24, 4, 5, 5, '2027-01-01', 50000.00, 0.00, 'en_attente'),
(25, 5, 5, 5, '2027-01-01', 50000.00, 0.00, 'en_attente');

-- 6. Paiements effectués par les membres
INSERT INTO payments (id, contribution_id, paye_par_user_id, montant_total, part_beneficiaire, part_caisse_reserve, mode_paiement, reference_paiement, date_paiement, est_en_retard, statut_paiement, commentaire) VALUES
(1, 1, 2, 50000.00, 50000.00, 0.00, 'momo', 'AUTO-POT-S1-1', '2026-09-01 10:00:00', 0, 'valide', 'Compensation automatique sur le pot (Bouffeur de la Séance #1)'),
(2, 2, 1, 50000.00, 50000.00, 0.00, 'om',   'OM260901.002',   '2026-09-01 11:30:00', 0, 'valide', 'Cotisation de la Secrétaire pour Éric'),
(3, 3, 3, 50000.00, 50000.00, 0.00, 'momo', 'MOMO260901.003', '2026-09-01 14:15:00', 0, 'valide', 'Cotisation Séance 1'),
(4, 4, 4, 50000.00, 50000.00, 0.00, 'om',   'OM260901.004',   '2026-09-01 16:00:00', 0, 'valide', 'Cotisation Séance 1'),
(5, 5, 5, 20000.00, 20000.00, 0.00, 'momo', 'MOMO260901.005', '2026-09-01 18:00:00', 0, 'valide', 'Paiement partiel initial de Paul (20 000 vers Éric)'),
(6, 5, 5, 32500.00, 30000.00, 2500.00, 'momo', 'MOMO260912.006', '2026-09-12 14:30:00', 1, 'valide', 'Remboursement retard Séance 1 : 30 000 vont à Éric et 2 500 vont à la caisse du Cycle 2'),
(7, 6, 2, 50000.00, 50000.00, 0.00, 'momo', 'MOMO261001.007', '2026-10-01 09:20:00', 0, 'valide', 'Cotisation Séance 2 vers Marie-Claire'),
(8, 7, 1, 50000.00, 50000.00, 0.00, 'om',   'AUTO-POT-S2-7',  '2026-10-01 10:00:00', 0, 'valide', 'Compensation automatique sur le pot (Bouffeur de la Séance #2)'),
(9, 8, 3, 50000.00, 50000.00, 0.00, 'momo', 'MOMO261001.009', '2026-10-01 15:40:00', 0, 'valide', 'Cotisation Séance 2 vers Marie-Claire'),
(10, 9, 4, 30000.00, 30000.00, 0.00, 'om',  'OM261001.010',   '2026-10-01 12:10:00', 0, 'valide', 'Avance Séance 2 de Nadine (reste 20 000 à devoir au bouffeur n°2)'),
(11, 10, 5, 20000.00, 20000.00, 0.00, 'momo', 'MOMO261001.011', '2026-10-01 17:50:00', 0, 'valide', 'Avance Séance 2 de Paul (reste 30 000 à devoir au bouffeur n°2)');

-- 7. Pénalités et Bonus (Caisse de réserve du Cycle 2) avec numero_seance
INSERT INTO penalties (id, cycle_member_id, contribution_id, numero_seance, type_operation, montant, motif, statut, date_operation, date_reglement, auteur_id) VALUES
(1, 5, 5, 1, 'penalite', 2500.00, 'Séance #1 : Cotisation partielle en retard (réglée dans la caisse du cycle)', 'regle', '2026-09-02 08:00:00', '2026-09-12 14:30:00', 1),
(2, 1, NULL, 1, 'bonus', 5000.00, 'Séance #1 : Bonus volontaire d Éric dans la caisse du cycle après réception de son pot', 'regle', '2026-09-12 15:00:00', '2026-09-12 15:00:00', 1),
(3, 5, 10, 2, 'penalite', 2500.00, 'Séance #2 : Cotisation partielle en retard (échéance du 01/10/2026)', 'en_attente', '2026-10-02 09:00:00', NULL, 1);

-- 8. Réunions et liens Zoom
INSERT INTO meetings (id, tontine_id, titre, date_heure, lien_zoom, description, statut, cree_par_id) VALUES
(1, 1, 'Tirage au sort officiel du Cycle 2', '2026-09-01 18:00:00', 'https://zoom.us/j/99887766554', 'Tirage de l ordre des 5 bénéficiaires et rappel des règles de la caisse du cycle.', 'terminee', 1),
(2, 1, 'Réunion Bilan Séance 2 & Régularisations', '2026-10-25 18:30:00', 'https://zoom.us/j/99112233445', 'Suivi des reliquats dus au bouffeur de la Séance 2 et point sur la caisse du cycle.', 'publiee', 1);

-- 9. Abonnements SaaS
INSERT INTO subscriptions (id, tontine_id, type_periode, montant, date_debut, date_fin, statut_paiement, reference_transaction, paye_par_id) VALUES
(1, 1, 'essai', 0.00, '2026-07-01 08:00:00', '2026-09-01 08:00:00', 'essai', 'TRIAL-INIT-2026', 1),
(2, 1, 'mensuel', 2000.00, '2026-09-01 08:00:01', '2026-10-01 23:59:59', 'simule', 'SIM-SAAS-202609', 1),
(3, 1, 'mensuel', 2000.00, '2026-10-01 00:00:00', '2026-11-01 23:59:59', 'simule', 'SIM-SAAS-202610', 1);

-- 10. Historique de transfert d'administrateur
INSERT INTO admin_transfers (id, tontine_id, ancien_admin_id, nouvel_admin_id, ancien_reste_membre, motif, date_transfert) VALUES
(1, 1, 3, 1, 1, 'Passation de la gestion de Jean-Paul Kamga à Marie-Claire Ngono à la fin du Cycle 1.', '2026-08-31 18:00:00');

<?php
// includes/footer.php — Pied de page + Barre de contrôle temporel (Simulation avec plancher de création)
$est_connecte = !empty($_SESSION['user_id']);
$decalage_sec = function_exists('obtenir_decalage_temps') ? obtenir_decalage_temps() : 0;
$date_sim_str = function_exists('date_actuelle') ? date_actuelle('d/m/Y') : date('d/m/Y');
$date_sim_iso = function_exists('date_actuelle') ? date_actuelle('Y-m-d') : date('Y-m-d');
$jours_ecart  = (int)round($decalage_sec / 86400);

$ts_min_tontine = (isset($pdo) && $pdo instanceof PDO && function_exists('obtenir_timestamp_min_tontine'))
    ? obtenir_timestamp_min_tontine($pdo)
    : strtotime('2026-01-01');
$date_min_iso = date('Y-m-d', $ts_min_tontine);
$date_min_str = date('d/m/Y', $ts_min_tontine);
$au_plancher  = ($date_sim_iso <= $date_min_iso);
?>
<footer class="mt-auto py-3 footer-djangui text-center small">
    <div class="container-fluid px-3 px-lg-4">
        <!-- BARRE DE SIMULATION TEMPORELLE (AVANCER / RECULER LE TEMPS) -->
        <div class="p-2 px-3 mb-2 rounded-3 box-adaptive">
            <div class="d-flex flex-column flex-xl-row justify-content-between align-items-center gap-2">
                <div class="d-flex flex-wrap align-items-center justify-content-center gap-2">
                    <span class="badge <?= $decalage_sec !== 0 ? 'bg-warning text-dark' : 'bg-secondary' ?> px-2 py-1">
                        <i class="bi bi-clock-history me-1"></i><?= $decalage_sec !== 0 ? 'TEMPS SIMULÉ' : 'TEMPS RÉEL' ?>
                    </span>
                    <span>
                        Date : <strong class="text-primary"><?= e($date_sim_str) ?></strong>
                        <?php if ($jours_ecart !== 0): ?>
                            <span class="text-muted">(<?= $jours_ecart > 0 ? '+' . $jours_ecart : $jours_ecart ?>j)</span>
                        <?php endif; ?>
                    </span>
                    <span class="badge box-adaptive">Min : <?= e($date_min_str) ?></span>
                </div>

                <form method="POST" class="d-flex flex-wrap justify-content-center align-items-center gap-1 m-0">
                    <?= champ_csrf() ?>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="submit" name="djangui_time_action" value="-1m" class="btn box-adaptive" <?= $au_plancher ? 'disabled' : '' ?>>-1 Mois</button>
                        <button type="submit" name="djangui_time_action" value="-15d" class="btn box-adaptive" <?= $au_plancher ? 'disabled' : '' ?>>-15j</button>
                        <button type="submit" name="djangui_time_action" value="-7d" class="btn box-adaptive" <?= $au_plancher ? 'disabled' : '' ?>>-1 Sem</button>
                        <button type="submit" name="djangui_time_action" value="-1d" class="btn box-adaptive" <?= $au_plancher ? 'disabled' : '' ?>>-1j</button>
                    </div>

                    <?php if ($decalage_sec !== 0): ?>
                        <button type="submit" name="djangui_time_action" value="reset" class="btn btn-sm btn-danger px-2">
                            <i class="bi bi-arrow-counterclockwise"></i> Aujourd'hui
                        </button>
                    <?php endif; ?>

                    <div class="btn-group btn-group-sm" role="group">
                        <button type="submit" name="djangui_time_action" value="+1d" class="btn btn-outline-primary">+1j</button>
                        <button type="submit" name="djangui_time_action" value="+7d" class="btn btn-outline-primary">+1 Sem</button>
                        <button type="submit" name="djangui_time_action" value="+15d" class="btn btn-outline-primary">+15j</button>
                        <button type="submit" name="djangui_time_action" value="+1m" class="btn btn-outline-primary fw-bold">+1 Mois</button>
                    </div>
                </form>

                <form method="POST" class="d-flex align-items-center gap-1 m-0">
                    <?= champ_csrf() ?>
                    <input type="hidden" name="djangui_time_action" value="goto_date">
                    <input type="date" name="target_date" min="<?= e($date_min_iso) ?>" value="<?= e($date_sim_iso) ?>" class="form-control form-control-sm" style="width: 135px;">
                    <button type="submit" class="btn btn-sm btn-glow-blue px-2">Aller</button>
                </form>
            </div>
        </div>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center text-muted">
            <div><strong>DJANGUI</strong> &copy; <?= date_actuelle('Y') ?> — Gestion transparente de tontines au Cameroun.</div>
            <div>Essai gratuit 2 mois puis 2 000 FCFA/mois par tontine</div>
        </div>
    </div>
</footer>

<?php if ($est_connecte): ?>
        </div><!-- /.app-main -->
    </div><!-- /.app-shell -->
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/script.js?v=<?= time() ?>"></script>
</body>
</html>

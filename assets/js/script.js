/**
 * assets/js/script.js — Djangui V3
 * 1. Bascule Mode Clair <-> Mode Sombre
 * 2. Copie en 1 clic du code d'invitation
 * 3. Animation fluide à la Connexion, Création/Adhésion et Déconnexion (Point 5)
 */

document.addEventListener('DOMContentLoaded', function () {
    // =========================================================================
    // 1. BASCULE MODE CLAIR / MODE SOMBRE
    // =========================================================================
    const boutonsTheme = document.querySelectorAll('.js-theme-toggle');

    function majApparenceBoutons(theme) {
        boutonsTheme.forEach(function (btn) {
            const icone = btn.querySelector('.js-theme-icon');
            const texte = btn.querySelector('.js-theme-label');
            if (theme === 'dark') {
                if (icone) icone.className = 'bi bi-sun-fill text-warning js-theme-icon';
                if (texte) texte.textContent = 'Mode Clair';
            } else {
                if (icone) icone.className = 'bi bi-moon-stars-fill text-info js-theme-icon';
                if (texte) texte.textContent = 'Mode Sombre';
            }
        });
    }

    const themeActuel = document.documentElement.getAttribute('data-theme') || 'light';
    majApparenceBoutons(themeActuel);

    boutonsTheme.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const courant = document.documentElement.getAttribute('data-theme') || 'light';
            const suivant = courant === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', suivant);
            document.documentElement.setAttribute('data-bs-theme', suivant);
            localStorage.setItem('djangui_theme', suivant);
            majApparenceBoutons(suivant);
        });
    });

    // =========================================================================
    // 2. ANIMATION CONNEXION / DÉCONNEXION (SPINNER + BARRE DE CHARGEMENT)
    // =========================================================================
    if (!document.getElementById('djanguiLoaderStyle')) {
        const styleTag = document.createElement('style');
        styleTag.id = 'djanguiLoaderStyle';
        styleTag.textContent = `
            .djangui-loader-overlay {
                position: fixed;
                inset: 0;
                z-index: 99999;
                background: rgba(7, 11, 25, 0.85);
                backdrop-filter: blur(14px);
                -webkit-backdrop-filter: blur(14px);
                display: none;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 1.5rem;
                text-align: center;
            }
            [data-theme="light"] .djangui-loader-overlay {
                background: rgba(241, 245, 249, 0.88);
            }
            .djangui-loader-overlay.active {
                display: flex !important;
            }
            .djangui-loader-card {
                background: var(--dj-bg-surface, #ffffff);
                color: var(--dj-text-main, #0f172a);
                border: 1px solid var(--dj-border, rgba(37,99,235,0.25));
                border-radius: 1.4rem;
                padding: 2.2rem 2rem;
                max-width: 390px;
                width: 100%;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            }
            .djangui-spinner-wrapper {
                position: relative;
                width: 82px;
                height: 82px;
                margin: 0 auto 1.2rem auto;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .djangui-spinner-ring {
                position: absolute;
                inset: 0;
                border-radius: 50%;
                border: 4px solid rgba(37, 99, 235, 0.18);
                border-top-color: #2563eb;
                border-right-color: #10b981;
                animation: djSpin 0.85s linear infinite;
            }
            .djangui-spinner-ring-inner {
                position: absolute;
                inset: 11px;
                border-radius: 50%;
                border: 3px dashed rgba(16, 185, 129, 0.45);
                animation: djSpinRev 1.5s linear infinite;
            }
            .djangui-spinner-icon {
                font-size: 2rem;
            }
            .djangui-progress-track {
                width: 100%;
                height: 8px;
                background: rgba(148, 163, 184, 0.25);
                border-radius: 999px;
                overflow: hidden;
                margin-top: 1.25rem;
            }
            .djangui-progress-fill {
                width: 0%;
                height: 100%;
                border-radius: 999px;
                background: linear-gradient(90deg, #2563eb 0%, #0ea5e9 50%, #10b981 100%);
                transition: width 1.4s cubic-bezier(0.22, 1, 0.36, 1);
            }
            @keyframes djSpin { to { transform: rotate(360deg); } }
            @keyframes djSpinRev { to { transform: rotate(-360deg); } }
        `;
        document.head.appendChild(styleTag);
    }

    const loaderHTML = `
        <div id="djanguiAuthLoader" class="djangui-loader-overlay">
            <div class="djangui-loader-card">
                <div class="djangui-spinner-wrapper">
                    <div class="djangui-spinner-ring"></div>
                    <div class="djangui-spinner-ring-inner"></div>
                    <i id="djanguiLoaderIcon" class="bi bi-safe2-fill text-primary djangui-spinner-icon"></i>
                </div>
                <h5 id="djanguiLoaderTitle" class="fw-bold mb-1">Connexion sécurisée...</h5>
                <p id="djanguiLoaderSubtitle" class="text-muted small mb-0">
                    Vérification de vos identifiants et ouverture de votre espace
                </p>
                <div class="djangui-progress-track">
                    <div id="djanguiLoaderBar" class="djangui-progress-fill"></div>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', loaderHTML);

    const loaderOverlay  = document.getElementById('djanguiAuthLoader');
    const loaderTitle    = document.getElementById('djanguiLoaderTitle');
    const loaderSubtitle = document.getElementById('djanguiLoaderSubtitle');
    const loaderIcon     = document.getElementById('djanguiLoaderIcon');
    const loaderBar      = document.getElementById('djanguiLoaderBar');

    function lancerAnimationEtContinuer(titre, sousTitre, iconeClass, actionFinale) {
        loaderTitle.textContent = titre;
        loaderSubtitle.textContent = sousTitre;
        loaderIcon.className = 'bi ' + iconeClass + ' djangui-spinner-icon';

        loaderBar.style.transition = 'none';
        loaderBar.style.width = '0%';
        loaderOverlay.classList.add('active');

        setTimeout(function () {
            loaderBar.style.transition = 'width 1.35s cubic-bezier(0.22, 1, 0.36, 1)';
            loaderBar.style.width = '100%';
        }, 30);

        setTimeout(function () {
            actionFinale();
        }, 1450);
    }

    // A. Déconnexion : tous les liens pointant vers logout
    document.querySelectorAll('a[href*="logout"]').forEach(function (lien) {
        lien.addEventListener('click', function (e) {
            e.preventDefault();
            const destination = this.href;
            lancerAnimationEtContinuer(
                'Déconnexion en cours...',
                'Fermeture sécurisée de votre session Djangui',
                'bi-power text-danger',
                function () {
                    window.location.href = destination;
                }
            );
        });
    });

    // B. Connexion, Création et Adhésion tontine : interception des formulaires POST
    document.querySelectorAll('form').forEach(function (form) {
        const actionAttr = (form.getAttribute('action') || '').toLowerCase();
        const urlActuelle = window.location.pathname.toLowerCase();

        const estFormAuth =
            actionAttr.includes('login') ||
            actionAttr.includes('register-tontine') ||
            actionAttr.includes('join-tontine') ||
            urlActuelle.includes('login') ||
            urlActuelle.includes('register-tontine') ||
            urlActuelle.includes('join-tontine');

        if (estFormAuth && (form.method || '').toUpperCase() === 'POST') {
            form.addEventListener('submit', function (e) {
                if (form.dataset.djanguiSubmitting === '1') {
                    return;
                }

                const champsVides = Array.from(form.querySelectorAll('[required]')).some(function (champ) {
                    return champ.value.trim() === '';
                });
                if (champsVides) {
                    return;
                }

                e.preventDefault();
                form.dataset.djanguiSubmitting = '1';

                if (e.submitter && e.submitter.name) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = e.submitter.name;
                    hidden.value = e.submitter.value;
                    form.appendChild(hidden);
                }

                let titre = 'Connexion à votre compte...';
                let sousTitre = 'Vérification de vos accès et chargement de votre tontine';
                let icone = 'bi-shield-lock-fill text-primary';

                if (actionAttr.includes('register') || urlActuelle.includes('register')) {
                    titre = 'Création de votre tontine...';
                    sousTitre = 'Initialisation de votre groupe et des 2 mois d\'essai gratuit';
                    icone = 'bi-rocket-takeoff-fill text-success';
                } else if (actionAttr.includes('join') || urlActuelle.includes('join')) {
                    titre = 'Adhésion à la tontine...';
                    sousTitre = 'Validation du code d\'invitation et création de votre espace membre';
                    icone = 'bi-person-check-fill text-primary';
                }

                lancerAnimationEtContinuer(titre, sousTitre, icone, function () {
                    HTMLFormElement.prototype.submit.call(form);
                });
            });
        }
    });
});

// 3. Copie rapide du code d'invitation dans le presse-papiers
function copierCodeInvitation(code, btn) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(code).then(function () {
        const htmlInitial = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copié !';
        setTimeout(function () {
            btn.innerHTML = htmlInitial;
        }, 1800);
    });
}

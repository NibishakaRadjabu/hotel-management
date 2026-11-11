document.addEventListener('DOMContentLoaded', () => {
    const occupancyBar = document.querySelector('[data-progress]');
    if (occupancyBar) occupancyBar.style.width = `${occupancyBar.dataset.progress}%`;

    const loader = document.getElementById('hotel-loader');
    let loaderTimer;
    let loaderStep = 0;
    let loaderProgress = 8;

    const showLoader = () => {
        if (!loader || !loader.hidden) return;

        const message = document.getElementById('loader-message');
        const progress = document.getElementById('loader-progress');
        const percent = document.getElementById('loader-percent');
        const progressBar = loader.querySelector('[role="progressbar"]');
        const staff = [...loader.querySelectorAll('.loader-staff-member')];
        const messages = [
            'Welcoming guests...',
            'Preparing rooms...',
            'Checking reservations...',
            'Organizing hotel services...',
            'Almost ready to welcome you...'
        ];

        loaderStep = 0;
        loaderProgress = 8;
        loader.hidden = false;
        loader.setAttribute('aria-hidden', 'false');

        const updateProgress = () => {
            if (loaderProgress >= 92) {
                window.clearInterval(loaderTimer);
                return;
            }

            loaderProgress = Math.min(loaderProgress + 4, 92);
            loaderStep = Math.min(Math.floor((loaderProgress - 8) / 17), staff.length - 1);
            progress.style.width = `${loaderProgress}%`;
            percent.textContent = `${loaderProgress}%`;
            progressBar.setAttribute('aria-valuenow', String(loaderProgress));
            message.textContent = messages[loaderStep];
            staff.forEach((member, index) => {
                const state = member.querySelector('.staff-state');
                member.classList.toggle('is-active', index === loaderStep);
                member.classList.toggle('is-complete', index < loaderStep);
                state.textContent = index < loaderStep ? '✓' : index === loaderStep ? '⟳' : '•';
            });
        };

        updateProgress();
        loaderTimer = window.setInterval(updateProgress, 140);
    };

    window.addEventListener('pageshow', () => {
        window.clearInterval(loaderTimer);
        if (loader) {
            loader.hidden = true;
            loader.setAttribute('aria-hidden', 'true');
        }
        document.querySelectorAll('form[data-submitting="true"]').forEach((form) => {
            delete form.dataset.submitting;
        });
    });

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;

        const destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin || destination.hash && destination.pathname === window.location.pathname && destination.search === window.location.search) return;
        showLoader();
    });

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }

            form.dataset.submitting = 'true';
            showLoader();
        });
    });

    const form = document.getElementById('booking-form');
    if (form) {
        const room = document.getElementById('room-id');
        const checkin = document.getElementById('checkin-date');
        const checkout = document.getElementById('checkout-date');
        const nightsLabel = document.getElementById('stay-nights');
        const totalLabel = document.getElementById('booking-total');

        const updateEstimate = () => {
            const start = new Date(`${checkin.value}T00:00:00`);
            const end = new Date(`${checkout.value}T00:00:00`);
            const rate = Number(room.selectedOptions[0]?.dataset.price);
            const nights = Math.round((end - start) / 86400000);
            if (!checkin.value || !checkout.value || !Number.isFinite(rate) || nights < 1) {
                nightsLabel.textContent = 'Select valid dates and a room';
                totalLabel.textContent = '— RWF';
                return;
            }
            nightsLabel.textContent = `${nights} night${nights === 1 ? '' : 's'} × ${new Intl.NumberFormat().format(rate)} RWF`;
            totalLabel.textContent = `${new Intl.NumberFormat().format(nights * rate)} RWF`;
        };

        [room, checkin, checkout].forEach((input) => input.addEventListener('change', updateEstimate));
        checkin.addEventListener('change', () => {
            if (checkin.value) {
                const nextDay = new Date(`${checkin.value}T00:00:00`);
                nextDay.setDate(nextDay.getDate() + 1);
                checkout.min = nextDay.toISOString().slice(0, 10);
                if (checkout.value && checkout.value < checkout.min) checkout.value = '';
            }
            updateEstimate();
        });
    }

    document.querySelectorAll('[data-confirm]').forEach((button) => {
        button.addEventListener('click', (event) => {
            if (!window.confirm(button.dataset.confirm)) {
                event.preventDefault();
                const form = button.closest('form');
                if (form) delete form.dataset.submitting;
            }
        });
    });

    document.querySelectorAll('[data-copy-link]').forEach((button) => {
        button.addEventListener('click', async () => {
            const input = document.querySelector(button.dataset.copyLink);
            const status = button.closest('.invitation-panel')?.querySelector('[data-copy-status]');
            if (!input || !status) return;

            try {
                await navigator.clipboard.writeText(input.value);
                status.textContent = 'Link copied. Share it only with the invited staff member.';
            } catch {
                input.focus();
                input.select();
                status.textContent = document.execCommand('copy')
                    ? 'Link copied. Share it only with the invited staff member.'
                    : 'Copy failed. Select and copy the link manually.';
            }
        });
    });
});

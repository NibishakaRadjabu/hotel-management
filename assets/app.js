document.addEventListener('DOMContentLoaded', () => {
    const languagePicker = document.getElementById('language-select');
    if (window.hotelI18n) {
        window.hotelI18n.applyLanguage();
        if (languagePicker) {
            languagePicker.addEventListener('change', () => {
                window.hotelI18n.setLanguage(languagePicker.value);
                document.dispatchEvent(new Event('hotel-language-change'));
            });
        }
    }

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
        ].map((text) => window.hotelI18n?.dictionaries[window.hotelI18n.getLanguage()]?.[text] || text);

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
                const language = window.hotelI18n?.getLanguage() || 'en';
                const dictionary = window.hotelI18n?.dictionaries[language] || {};
                nightsLabel.textContent = dictionary['Select valid dates and a room'] || 'Select valid dates and a room';
                totalLabel.textContent = '— RWF';
                return;
            }

            const language = window.hotelI18n?.getLanguage() || 'en';
            const dictionary = window.hotelI18n?.dictionaries[language] || {};
            const nightLabel = dictionary[nights === 1 ? 'night' : 'nights'] || (nights === 1 ? 'night' : 'nights');
            nightsLabel.textContent = `${nights} ${nightLabel} × ${new Intl.NumberFormat(language).format(rate)} RWF`;
            totalLabel.textContent = `${new Intl.NumberFormat().format(nights * rate)} RWF`;
        };

        [room, checkin, checkout].forEach((input) => input.addEventListener('change', updateEstimate));
        document.addEventListener('hotel-language-change', updateEstimate);
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

    const publicCheckin = document.querySelector('input[name="checkin_date"][type="date"]');
    const publicCheckout = document.querySelector('input[name="checkout_date"][type="date"]');
    if (publicCheckin && publicCheckout) {
        const updatePublicCheckoutMinimum = () => {
            const start = new Date(`${publicCheckin.value}T00:00:00`);
            if (Number.isNaN(start.getTime())) return;
            start.setDate(start.getDate() + 1);
            publicCheckout.min = start.toISOString().slice(0, 10);
            if (publicCheckout.value && publicCheckout.value < publicCheckout.min) {
                publicCheckout.value = publicCheckout.min;
            }
        };
        publicCheckin.addEventListener('change', updatePublicCheckoutMinimum);
        updatePublicCheckoutMinimum();
    }

    const paypalDepositPreview = document.querySelector('[data-paypal-deposit-preview]');
    if (paypalDepositPreview) {
        const depositAmount = paypalDepositPreview.querySelector('[data-paypal-deposit-amount]');
        const updatePaypalDeposit = () => {
            const selectedRoom = document.querySelector('input[name="room_id"]:checked');
            const depositRwf = Number(selectedRoom?.dataset.depositRwf);
            const exchangeRate = Number(paypalDepositPreview.dataset.rwfPerUnit);
            if (!depositAmount || !Number.isFinite(depositRwf) || !Number.isFinite(exchangeRate) || exchangeRate <= 0) return;
            const language = window.hotelI18n?.getLanguage() || 'en';
            const currency = paypalDepositPreview.dataset.currency;
            const amount = new Intl.NumberFormat(language, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(depositRwf / exchangeRate);
            depositAmount.textContent = `${amount} ${currency}`;
        };
        document.querySelectorAll('input[name="room_id"]').forEach((input) => {
            input.addEventListener('change', updatePaypalDeposit);
        });
        updatePaypalDeposit();
    }

    document.querySelectorAll('[data-hotel-slideshow]').forEach((slideshow) => {
        const slides = [...slideshow.querySelectorAll('.hotel-slide')];
        const dots = [...slideshow.querySelectorAll('[data-slide-to]')];
        if (slides.length < 2) return;

        let currentSlide = 0;
        const showSlide = (nextIndex) => {
            currentSlide = (nextIndex + slides.length) % slides.length;
            slides.forEach((slide, index) => {
                slide.hidden = index !== currentSlide;
                slide.classList.toggle('is-active', index === currentSlide);
            });
            dots.forEach((dot, index) => {
                dot.classList.toggle('is-active', index === currentSlide);
                dot.setAttribute('aria-current', index === currentSlide ? 'true' : 'false');
            });
        };

        slideshow.querySelector('[data-slide-previous]')?.addEventListener('click', () => showSlide(currentSlide - 1));
        slideshow.querySelector('[data-slide-next]')?.addEventListener('click', () => showSlide(currentSlide + 1));
        dots.forEach((dot) => {
            dot.addEventListener('click', () => showSlide(Number(dot.dataset.slideTo)));
        });

        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            window.setInterval(() => {
                if (!document.hidden) showSlide(currentSlide + 1);
            }, 5000);
        }
    });

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
            const language = window.hotelI18n?.getLanguage() || 'en';
            const dictionary = window.hotelI18n?.dictionaries[language] || {};
            const copiedMessage = dictionary['Link copied. Share it only with the invited staff member.']
                || 'Link copied. Share it only with the invited staff member.';
            const failedMessage = dictionary['Copy failed. Select and copy the link manually.']
                || 'Copy failed. Select and copy the link manually.';

            try {
                await navigator.clipboard.writeText(input.value);
                status.textContent = copiedMessage;
            } catch {
                input.focus();
                input.select();
                status.textContent = document.execCommand('copy')
                    ? copiedMessage
                    : failedMessage;
            }
        });
    });
});

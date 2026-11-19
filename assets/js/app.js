(() => {
    const roleSelect = document.querySelector('[data-role-select]');
    const providerFields = document.querySelector('[data-provider-fields]');
    const categoryGroups = document.querySelectorAll('[data-role-category]');
    const serviceModeSelect = document.querySelector('[data-service-mode]');
    const serviceModuleSelect = document.querySelector('[data-service-module]');
    const servicePriceField = document.querySelector('[data-service-price-field]');
    const servicePriceInput = document.querySelector('[data-service-price]');
    const serviceNameInput = document.querySelector('[data-service-name]');

    const updateProviderFields = () => {
        if (!roleSelect || !providerFields) return;
        const needsProfile = ['tutor', 'service_provider'].includes(roleSelect.value);
        providerFields.hidden = !needsProfile;
        providerFields.querySelectorAll('input, textarea, select').forEach((field) => {
            field.required = needsProfile && ['qualifications', 'location', 'national_id', 'certificate'].includes(field.name);
        });
        categoryGroups.forEach((group) => {
            const active = roleSelect.value === 'tutor' && group.dataset.roleCategory === 'tutoring'
                || roleSelect.value === 'service_provider' && group.dataset.roleCategory === 'home_services';
            group.hidden = !active;
            group.querySelectorAll('input').forEach((input) => {
                input.disabled = !active;
            });
        });
        if (serviceModeSelect) {
            const physicalOnly = roleSelect.value === 'service_provider';
            serviceModeSelect.querySelectorAll('option').forEach((option) => {
                const unavailable = physicalOnly && option.value !== 'physical';
                option.hidden = unavailable;
                option.disabled = unavailable;
            });
            if (physicalOnly) serviceModeSelect.value = 'physical';
        }
    };

    if (roleSelect) {
        roleSelect.addEventListener('change', updateProviderFields);
        updateProviderFields();
    }

    document.querySelectorAll('[data-tutor-levels]').forEach((group) => {
        const updateLevels = () => {
            const active = roleSelect?.value === 'tutor';
            group.hidden = !active;
            group.querySelectorAll('input').forEach((input) => {
                input.disabled = !active;
            });
        };
        roleSelect?.addEventListener('change', updateLevels);
        updateLevels();
    });

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('.password-input-wrap')?.querySelector('input');
            if (!input) return;
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', String(visible));
            button.querySelector('[data-eye-open]').hidden = visible;
            button.querySelector('[data-eye-closed]').hidden = !visible;
        });
    });

    const updateServicePriceField = () => {
        if (!serviceModuleSelect || !servicePriceField || !servicePriceInput) return;
        servicePriceField.hidden = false;
        servicePriceInput.required = true;
        servicePriceInput.disabled = false;
        const label = servicePriceField.firstChild;
        if (label && label.nodeType === Node.TEXT_NODE) {
            const isTutoring = serviceModuleSelect.value === 'tutoring';
            const isLaundry = serviceNameInput && serviceNameInput.value.trim().toLowerCase() === 'laundry';
            label.textContent = isTutoring
                ? 'Hourly price per student (KES)'
                : (isLaundry ? 'Price per laundry basket (KES)' : 'Price per service (KES)');
        }
    };
    if (serviceModuleSelect) {
        serviceModuleSelect.addEventListener('change', updateServicePriceField);
        serviceNameInput?.addEventListener('input', updateServicePriceField);
        updateServicePriceField();
    }

    const serviceFilter = document.querySelector('[data-service-filter]');
    if (serviceFilter) {
        const cards = [...document.querySelectorAll('[data-service-admin-card]')];
        serviceFilter.addEventListener('input', () => {
            const query = serviceFilter.value.trim().toLocaleLowerCase();
            cards.forEach((card) => {
                card.hidden = !card.dataset.name.includes(query)
                    && !card.dataset.module.replace('_', ' ').includes(query);
            });
        });
    }

    document.querySelectorAll('[data-booking-service]').forEach((link) => {
        link.addEventListener('click', () => {
            try {
                localStorage.setItem('tutalinkBooking', JSON.stringify({
                    service: link.dataset.bookingService,
                    provider: link.dataset.bookingProvider,
                    savedAt: Date.now()
                }));
            } catch (error) {
                // The server-side session remains the source of truth if browser storage is unavailable.
            }
        });
    });

    const loginForm = document.querySelector('[data-login-form]');
    if (loginForm) {
        try {
            const intent = JSON.parse(localStorage.getItem('tutalinkBooking') || 'null');
            if (intent && Date.now() - intent.savedAt < 86400000) {
                loginForm.querySelector('[name="pending_service_id"]').value = intent.service;
                loginForm.querySelector('[name="pending_provider_id"]').value = intent.provider;
            }
        } catch (error) {
            // Ignore malformed browser storage; validated server-side intent is used instead.
        }
    }

    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });

    document.querySelectorAll('[data-conversation-popover]').forEach((popover) => {
        const toggle = popover.querySelector('summary');
        const frame = popover.querySelector('iframe');
        if (!toggle || !frame) return;
        const openLabel = toggle.textContent.trim();
        const bookingCard = popover.closest('.dashboard-booking');
        const positionFrame = () => {
            if (!popover.open) return;
            const trigger = toggle.getBoundingClientRect();
            const width = Math.min(440, window.innerWidth - 24);
            const height = Math.min(430, window.innerHeight - 24);
            const left = Math.min(
                Math.max(12, trigger.right - width),
                window.innerWidth - width - 12
            );
            const below = window.innerHeight - trigger.bottom - 8;
            const preferredTop = below >= height ? trigger.bottom + 8 : trigger.top - height - 8;
            const top = Math.min(
                Math.max(12, preferredTop),
                window.innerHeight - height - 12
            );
            frame.style.width = `${width}px`;
            frame.style.height = `${height}px`;
            frame.style.left = `${left}px`;
            frame.style.top = `${top}px`;
        };
        const repositionOnViewportChange = () => positionFrame();
        popover.addEventListener('toggle', () => {
            toggle.textContent = popover.open ? 'Close conversation' : openLabel;
            toggle.setAttribute('aria-expanded', String(popover.open));
            if (bookingCard) bookingCard.classList.toggle('conversation-open', popover.open);
            if (popover.open) {
                document.querySelectorAll('[data-conversation-popover][open]').forEach((otherPopover) => {
                    if (otherPopover !== popover) otherPopover.open = false;
                });
                window.requestAnimationFrame(positionFrame);
                window.addEventListener('resize', repositionOnViewportChange);
                window.addEventListener('scroll', repositionOnViewportChange, true);
            } else {
                window.removeEventListener('resize', repositionOnViewportChange);
                window.removeEventListener('scroll', repositionOnViewportChange, true);
            }
        });
    });

    document.querySelectorAll('.message-thread').forEach((thread) => {
        thread.scrollTop = thread.scrollHeight;
    });

    document.querySelectorAll('[data-toast]').forEach((toast) => {
        window.setTimeout(() => toast.remove(), 3000);
    });

    document.querySelectorAll('[data-showcase-toggle]').forEach((button) => {
        const carousel = button.closest('.visual-main')?.querySelector('.visual-carousel');
        if (!carousel) return;
        const cards = carousel.querySelectorAll('.showcase-content');
        button.addEventListener('click', () => {
            const paused = button.getAttribute('aria-pressed') === 'true';
            button.setAttribute('aria-pressed', String(!paused));
            button.textContent = paused ? 'Pause showcase' : 'Resume showcase';
            carousel.style.animationPlayState = paused ? 'running' : 'paused';
            cards.forEach((card) => {
                card.style.animationPlayState = paused ? 'running' : 'paused';
            });
        });
    });

    document.querySelectorAll('[data-quote-form]').forEach((form) => {
        const amountField = form.querySelector('[name="amount"]');
        const rate = Number(form.dataset.rate);
        const format = (cents) => new Intl.NumberFormat('en-KE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(cents / 100);
        const updateQuote = () => {
            const amount = Number(amountField.value);
            const totalCents = Number.isFinite(amount) && amount > 0 ? Math.round(amount * 100) : 0;
            const commissionCents = Math.round(totalCents * rate / 100);
            form.querySelector('[data-quote-total]').textContent = `KES ${format(totalCents)}`;
            form.querySelector('[data-quote-commission]').textContent = `KES ${format(commissionCents)}`;
            form.querySelector('[data-quote-earnings]').textContent = `KES ${format(totalCents - commissionCents)}`;
        };
        amountField.addEventListener('input', updateQuote);
        updateQuote();
    });

    document.querySelectorAll('[data-session-hours]').forEach((hoursField) => {
        const total = hoursField.closest('label').querySelector('[data-session-total]');
        const studentCount = hoursField.form.querySelector('[data-student-count]');
        const hourlyRate = Number(hoursField.dataset.hourlyRate);
        const format = new Intl.NumberFormat('en-KE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        const updateSessionTotal = () => {
            const hours = Number(hoursField.value);
            const students = Number(studentCount?.value || 1);
            total.textContent = Number.isFinite(hours) && hours > 0
                ? `KES ${format.format(hourlyRate * hours * students)}`
                : 'Choose a session length';
        };
        hoursField.addEventListener('input', updateSessionTotal);
        studentCount?.addEventListener('input', updateSessionTotal);
        updateSessionTotal();
    });

    const cartFormat = new Intl.NumberFormat('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    const updateCartTotals = () => {
        let cartTotal = 0;
        document.querySelectorAll('.cart-item').forEach((card) => {
            const hoursField = card.querySelector('[data-cart-hours]');
            const studentsField = card.querySelector('[data-cart-students]');
            const basketsField = card.querySelector('[data-cart-baskets]');
            const hours = Number(hoursField?.value || 1);
            const students = Number(studentsField?.value || 1);
            const baskets = Number(basketsField?.value || 1);
            const rate = Number(card.dataset.cartRate);
            const quantity = hoursField ? hours * students : baskets;
            const lineTotal = Number.isFinite(quantity) && Number.isFinite(rate)
                ? Math.max(0, quantity * rate)
                : 0;
            card.querySelector('[data-cart-line-total]').textContent = `KES ${cartFormat.format(lineTotal)}`;
            cartTotal += lineTotal;
        });
        const total = document.querySelector('[data-cart-total]');
        if (total) total.textContent = `KES ${cartFormat.format(cartTotal)}`;
    };
    document.querySelectorAll('[data-cart-hours], [data-cart-students], [data-cart-baskets]').forEach((field) => {
        field.addEventListener('input', updateCartTotals);
    });
    if (document.querySelector('[data-cart-total]')) updateCartTotals();

    document.querySelectorAll('[data-service-quantity]').forEach((field) => {
        const total = field.closest('label').querySelector('[data-service-total]');
        const unitPrice = Number(field.dataset.unitPrice);
        const updateServiceTotal = () => {
            const quantity = Number(field.value);
            total.textContent = Number.isFinite(quantity) && quantity > 0
                ? `KES ${cartFormat.format(unitPrice * quantity)}`
                : 'Choose a quantity';
        };
        field.addEventListener('input', updateServiceTotal);
        updateServiceTotal();
    });

    if (document.querySelector('.flash-success')) {
        try {
            localStorage.removeItem('tutalinkBooking');
        } catch (error) {
            // Storage is optional.
        }
    }
})();

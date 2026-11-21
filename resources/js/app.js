import './bootstrap';
import Alpine from 'alpinejs';

let pendingRequests = 0;
let visibleSince = 0;
let hideTimer;
let showTimer;
let loadingOverlay;

function overlay() {
    if (loadingOverlay) return loadingOverlay;
    if (!document.body) return null;

    loadingOverlay = document.createElement('div');
    loadingOverlay.className = 'global-loading';
    loadingOverlay.hidden = true;
    loadingOverlay.setAttribute('role', 'status');
    loadingOverlay.setAttribute('aria-live', 'polite');
    loadingOverlay.innerHTML = '<div class="global-loading__card"><span class="global-loading__spinner" aria-hidden="true"></span><strong>Memuat data...</strong><span>Mohon tunggu sebentar</span></div>';
    document.body.append(loadingOverlay);
    return loadingOverlay;
}

function startLoading() {
    clearTimeout(hideTimer);
    if (pendingRequests++ === 0) {
        clearTimeout(showTimer);
        showTimer = setTimeout(() => {
            if (!pendingRequests) return;
            const element = overlay();
            if (element) {
                element.hidden = false;
                visibleSince = Date.now();
            }
        }, 180);
    }
}

function stopLoading() {
    pendingRequests = Math.max(0, pendingRequests - 1);
    if (pendingRequests) return;
    clearTimeout(showTimer);
    if (!loadingOverlay || loadingOverlay.hidden) return;
    hideTimer = setTimeout(() => {
        if (pendingRequests === 0 && loadingOverlay) loadingOverlay.hidden = true;
    }, Math.max(0, 240 - (Date.now() - visibleSince)));
}

const originalFetch = window.fetch.bind(window);
window.fetch = async (...args) => {
    startLoading();
    try {
        return await originalFetch(...args);
    } finally {
        stopLoading();
    }
};

document.addEventListener('submit', (event) => {
    if (!event.defaultPrevented) startLoading();
});

let fieldErrorId = 0;
document.addEventListener('invalid', (event) => {
    const field = event.target;
    if (!field.matches?.('.field')) return;
    event.preventDefault();
    const firstInvalid = document.querySelector('.field[aria-invalid="true"]');
    if (!firstInvalid) field.focus();
    const id = field.dataset.validationErrorId || `field-error-${++fieldErrorId}`;
    field.dataset.validationErrorId = id;
    field.setAttribute('aria-invalid', 'true');
    if (!field.getAttribute('aria-describedby')?.split(' ').includes(id)) {
        field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), id].filter(Boolean).join(' '));
    }
    let message = document.getElementById(id);
    if (!message) {
        message = document.createElement('span');
        message.id = id;
        message.className = 'field-hint-error';
        message.setAttribute('role', 'alert');
        field.insertAdjacentElement('afterend', message);
    }
    const validity = field.validity;
    message.textContent = validity.valueMissing ? 'Kolom ini wajib diisi.'
        : validity.typeMismatch ? 'Format isian tidak valid.'
        : validity.rangeUnderflow ? `Nilai minimal ${field.min}.`
        : validity.rangeOverflow ? `Nilai maksimal ${field.max}.`
        : validity.tooShort ? `Minimal ${field.minLength} karakter.`
        : validity.tooLong ? `Maksimal ${field.maxLength} karakter.`
        : validity.stepMismatch ? 'Masukkan angka sesuai kelipatan yang diizinkan.'
        : field.validationMessage;
}, true);

function clearFieldError(event) {
    const field = event.target;
    const id = field.dataset?.validationErrorId;
    if (!id || !field.validity.valid) return;
    document.getElementById(id)?.remove();
    field.removeAttribute('aria-invalid');
    field.setAttribute('aria-describedby', (field.getAttribute('aria-describedby') || '').split(' ').filter((value) => value && value !== id).join(' '));
    delete field.dataset.validationErrorId;
}
document.addEventListener('input', clearFieldError);
document.addEventListener('change', clearFieldError);

document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('nav[role="navigation"] a[href]');
    if (!link || new URL(link.href).origin !== window.location.origin) return;
    const nav = link.closest('nav');
    const card = nav.closest('.card') || nav.parentElement?.previousElementSibling;
    if (card?.classList.contains('card')) setTimeout(() => {
        if (!document.hidden) card.classList.add('table-loading');
    }, 180);
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        pendingRequests = 0;
        clearTimeout(hideTimer);
        clearTimeout(showTimer);
        if (loadingOverlay) loadingOverlay.hidden = true;
        document.querySelectorAll('.table-loading').forEach((table) => table.classList.remove('table-loading'));
    }
});

const paymentPage = document.getElementById('payment-live');
if (paymentPage) {
    let navigating = false;
    const showResult = (status) => {
        if (navigating || !['paid', 'cancelled'].includes(status)) return;
        navigating = true;
        const result = status === 'paid' ? 'success' : 'cancelled';
        window.location.assign(`${paymentPage.dataset.detailUrl}?payment=${result}`);
    };
    const checkState = () => {
        if (!navigating) window.axios.get(paymentPage.dataset.stateUrl)
            .then(({ data }) => showResult(data.status))
            .catch(() => {});
    };

    if (window.Echo) {
        window.Echo.private(`sales.${paymentPage.dataset.saleId}`)
            .listen('.payment.updated', (event) => showResult(event.status))
            .subscribed(checkState);
    }
    checkState();
    window.setInterval(checkState, 30000);
}

if (document.body) overlay();
else document.addEventListener('DOMContentLoaded', overlay, { once: true });

window.Alpine = Alpine;
Alpine.start();

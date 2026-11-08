// The stylesheet is linked in base.html.twig: a CSS import would become a data: URL in the
// import map, which the Content-Security-Policy (rightly) blocks.
import { initPasskeys } from './js/passkey.js';

// Same key as in js/theme-init.js, which also migrates the pre-rename key "ems-theme".
const THEME_KEY = 'zwille-theme';

// --- Toasts -----------------------------------------------------------------------------

// Short, non-blocking confirmations in the polite live region of base.html.twig.
function toast(message) {
    const region = document.getElementById('toasts');
    if (!region || !message) return;
    const item = document.createElement('div');
    item.className = 'toast';
    item.textContent = message;
    region.append(item);
    setTimeout(() => {
        item.classList.add('leaving');
        setTimeout(() => item.remove(), 250);
    }, 2600);
}

// --- Copy -------------------------------------------------------------------------------

// Browsers only offer the Clipboard API on HTTPS (or localhost). Plain-HTTP installs,
// e.g. reached via IP and port, fall back to the classic copy command on a hidden textarea.
async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }
    const buffer = document.createElement('textarea');
    buffer.value = text;
    buffer.setAttribute('readonly', '');
    buffer.className = 'copy-buffer';
    document.body.append(buffer);
    buffer.select();
    const copied = document.execCommand('copy');
    buffer.remove();
    if (!copied) {
        throw new Error('copy command rejected');
    }
}

// Original button content (icons included), restored after the "copied" feedback.
const copyLabels = new WeakMap();

// Copy buttons: <button data-copy="text"> or data-copy-target="#input">
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy], [data-copy-target]');
    if (!button) return;
    const target = button.dataset.copyTarget ? document.querySelector(button.dataset.copyTarget) : null;
    const text = target ? target.value ?? target.textContent : button.dataset.copy;
    try {
        await copyText(text);
        if (!copyLabels.has(button)) copyLabels.set(button, button.innerHTML);
        button.textContent = button.dataset.copiedLabel || '✓';
        clearTimeout(button.copyTimer);
        button.copyTimer = setTimeout(() => { button.innerHTML = copyLabels.get(button); }, 1500);
        button.focus();
        toast((document.body.dataset.copiedToast || '%text%').replace('%text%', text.length > 80 ? text.slice(0, 77) + '…' : text));
    } catch {
        // Last resort: show the text so it can be copied by hand.
        window.prompt(document.body.dataset.copyPrompt || '', text);
    }
});

// --- Forms ------------------------------------------------------------------------------

// Confirm before destructive submits: <form data-confirm="Really?"> or, when a form has
// several submit buttons, a single button <button data-confirm="Really?">.
document.addEventListener('submit', (event) => {
    const message = event.submitter?.dataset?.confirm ?? event.target.dataset?.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

// Collection rows (localised targets): add from data-prototype, remove per row.
document.addEventListener('click', (event) => {
    const add = event.target.closest('[data-collection-add]');
    if (add) {
        const holder = document.querySelector(add.dataset.collectionAdd);
        const index = Number(holder.dataset.index || holder.children.length);
        const wrapper = document.createElement('div');
        wrapper.innerHTML = holder.dataset.prototype.replace(/__name__/g, String(index)).trim();
        holder.append(...wrapper.childNodes);
        holder.dataset.index = String(index + 1);
        holder.lastElementChild?.querySelector('input')?.focus();
        return;
    }
    const remove = event.target.closest('[data-collection-remove]');
    if (remove) {
        remove.closest('.collection-row')?.remove();
    }
});

// Auto-submit selects in filter bars: <select data-autosubmit>
document.addEventListener('change', (event) => {
    if (event.target.matches('[data-autosubmit]')) {
        event.target.form?.requestSubmit();
    }
});

// --- Bulk selection ---------------------------------------------------------------------

// Row checkboxes live in the table and point at the bar via form="bulk-form"; the header
// checkbox <input data-check-all="<selector>"> toggles all of them. The bar (sticky at the
// bottom) only shows while something is selected; without JavaScript it is always visible.
const bulkBar = document.querySelector('[data-bulk-bar]');
const rowBoxes = () => document.querySelectorAll(`input[form="${bulkBar?.id}"][name="ids[]"]`);

function updateBulkBar() {
    if (!bulkBar) return;
    const boxes = [...rowBoxes()];
    const selected = boxes.filter((box) => box.checked).length;
    bulkBar.classList.toggle('has-selection', selected > 0);
    const count = bulkBar.querySelector('[data-bulk-count]');
    if (count && selected > 0) {
        count.textContent = count.dataset.template.replace('__count__', String(selected));
    }
    bulkBar.querySelector('[data-bulk-clear]')?.toggleAttribute('hidden', selected === 0);
    const all = document.querySelector('[data-check-all]');
    if (all) {
        all.checked = selected > 0 && selected === boxes.length;
        all.indeterminate = selected > 0 && selected < boxes.length;
    }
}

document.addEventListener('change', (event) => {
    const all = event.target.closest('[data-check-all]');
    if (all) {
        document.querySelectorAll(all.dataset.checkAll).forEach((box) => {
            box.checked = all.checked;
        });
    }
    if (all || event.target.matches('input[name="ids[]"]')) {
        updateBulkBar();
    }
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-bulk-clear]')) return;
    rowBoxes().forEach((box) => { box.checked = false; });
    updateBulkBar();
});

// Browsers restore checkbox state on back navigation: reflect it.
updateBulkBar();

// --- Navigation drawer (below 960px) ---------------------------------------------------

const sidebar = document.getElementById('sidebar');
const navToggle = document.querySelector('[data-nav-toggle]');
const backdrop = document.querySelector('.nav-backdrop');
const drawerQuery = window.matchMedia('(max-width: 959.98px)');

function setNav(open) {
    if (!sidebar || !navToggle) return;
    document.body.classList.toggle('nav-open', open);
    navToggle.setAttribute('aria-expanded', String(open));
    backdrop?.toggleAttribute('hidden', !open);
    // Keep keyboard focus inside the open drawer.
    document.querySelector('.shell-main')?.toggleAttribute('inert', open);
    if (open) {
        (sidebar.querySelector('.nav-link.active') || sidebar.querySelector('a, button'))?.focus();
    }
}

navToggle?.addEventListener('click', () => setNav(!document.body.classList.contains('nav-open')));
document.addEventListener('click', (event) => {
    if (event.target.closest('[data-nav-close]') && document.body.classList.contains('nav-open')) {
        setNav(false);
        navToggle?.focus();
    }
});
drawerQuery.addEventListener('change', () => setNav(false));

// --- User menu and theme ---------------------------------------------------------------

const usermenu = document.querySelector('[data-usermenu]');
document.addEventListener('click', (event) => {
    if (usermenu?.open && !usermenu.contains(event.target)) {
        usermenu.open = false;
    }
});

function storedTheme() {
    try {
        return window.localStorage.getItem(THEME_KEY) || 'system';
    } catch {
        return 'system';
    }
}

function applyTheme(theme) {
    const root = document.documentElement;
    if (theme === 'light' || theme === 'dark') {
        root.setAttribute('data-theme', theme);
    } else {
        root.removeAttribute('data-theme');
    }
    document.querySelectorAll('[data-theme-choice]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.themeChoice === theme));
    });
}

const themeSwitch = document.querySelector('[data-theme-switch]');
if (themeSwitch) {
    themeSwitch.hidden = false;
    applyTheme(storedTheme());
    themeSwitch.addEventListener('click', (event) => {
        const button = event.target.closest('[data-theme-choice]');
        if (!button) return;
        const theme = button.dataset.themeChoice;
        try {
            if (theme === 'system') {
                window.localStorage.removeItem(THEME_KEY);
            } else {
                window.localStorage.setItem(THEME_KEY, theme);
            }
        } catch {
            // Not persisted (storage blocked), but still applied for this page.
        }
        applyTheme(theme);
    });
}

// --- Flash messages --------------------------------------------------------------------

// Every flash gets a close button; success messages fade out by themselves, errors and
// warnings stay until closed.
function dismiss(flash) {
    flash.classList.add('leaving');
    setTimeout(() => flash.remove(), 260);
}

document.querySelectorAll('.flash').forEach((flash) => {
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn btn-ghost btn-icon btn-sm flash-close';
    close.setAttribute('aria-label', document.body.dataset.dismissLabel || '×');
    close.textContent = '×';
    close.addEventListener('click', () => dismiss(flash));
    flash.classList.add('dismissible');
    flash.append(close);
    if (flash.classList.contains('flash-success')) {
        setTimeout(() => {
            // Keep it while the user is reading or interacting with it.
            if (!flash.matches(':hover, :focus-within')) dismiss(flash);
        }, 6000);
    }
});

// --- Keyboard shortcuts ----------------------------------------------------------------

// "/" focuses the search, "n" opens the new-link form, Escape closes drawer and user menu.
// Shortcuts never fire while typing in a field or together with a modifier key.
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        if (document.body.classList.contains('nav-open')) {
            setNav(false);
            navToggle?.focus();
        }
        if (usermenu?.open) {
            usermenu.open = false;
            usermenu.querySelector('summary')?.focus();
        }
        return;
    }
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return;
    const target = event.target;
    if (target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]')) return;

    if (event.key === '/') {
        const search = document.querySelector('[data-shortcut-search]');
        if (search) {
            event.preventDefault();
            search.focus();
            search.select();
        }
    } else if (event.key === 'n' || event.key === 'N') {
        const link = document.querySelector('[data-shortcut-new]');
        if (link) {
            event.preventDefault();
            window.location.assign(link.href);
        }
    }
});

initPasskeys();

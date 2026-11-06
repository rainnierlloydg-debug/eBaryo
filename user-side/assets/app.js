'use strict';

document.querySelectorAll('[data-open]').forEach(button => {
    button.addEventListener('click', () => document.getElementById(button.dataset.open)?.showModal());
});
document.querySelectorAll('[data-close]').forEach(button => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
});
document.querySelectorAll('[data-auto-open]').forEach(dialog => dialog.showModal());
document.querySelectorAll('[data-lock-dialog]').forEach(dialog => {
    dialog.addEventListener('cancel', event => event.preventDefault());
});
document.querySelectorAll('[data-edit]').forEach(button => {
    button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.edit);
        const inputs = target?.matches('input') ? [target] : [...(target?.querySelectorAll('input') ?? [])];
        inputs.forEach(input => { input.readOnly = false; });
        inputs[0]?.focus();
        inputs[0]?.select();
    });
});
document.querySelectorAll('form[method="post"]').forEach(form => {
    form.addEventListener('submit', event => {
        if (form.dataset.submitting) { event.preventDefault(); return; }
        form.dataset.submitting = 'true';
        form.querySelectorAll('button[type="submit"]').forEach(button => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        });
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting]').forEach(form => {
        delete form.dataset.submitting;
        form.querySelectorAll('[aria-busy="true"]').forEach(button => {
            button.disabled = false;
            button.removeAttribute('aria-busy');
        });
    });
});

/*
 * Shared client-side form validation (customer + admin pages).
 *
 * Works automatically on every <form method="post"> and on forms with data-validate.
 * Uses normal HTML attributes: required, type="email", minlength, maxlength,
 * min, max, step, pattern, accept. Extra data attributes:
 *   data-phone-input            numbers only, 7-15 digits (letters/symbols blocked)
 *   data-password-strength      at least one letter and one number
 *   data-match="#otherField"    value must equal another field (password confirmation)
 *   data-max-size="5242880"     file size limit in bytes
 *   data-counter                show "x / max characters" under a textarea
 *   data-msg-required / data-msg-pattern / data-msg-min / data-msg-max   custom messages
 *   data-error-target="#id"     put the error message after another element
 *   data-no-lock                do not disable the submit button after submitting
 *
 * Frontend validation only improves usability - every rule is checked again in PHP.
 */
(function () {
    'use strict';

    var MSG = {
        required: 'This field is required.',
        empty: 'This field cannot be empty.',
        email: 'Please enter a valid email address.',
        phone: 'Please enter a valid phone number using numbers only. (7 to 15 digits)',
        phoneKey: 'Only numbers (0-9) are allowed.',
        passwordShort: 'Password must be at least 8 characters long.',
        passwordWeak: 'Password must contain at least one letter and one number.',
        mismatch: 'Passwords do not match. Please try again.',
        tooLong: 'Your message exceeds the maximum allowed character limit.',
        number: 'Please enter a valid number.',
        wholeNumber: 'Please enter a whole number.',
        choice: 'Please select a valid option.',
        fileType: 'Invalid file format. Please upload an accepted file type.',
        fileSize: 'File size exceeds the maximum allowed limit.',
        duplicate: 'This request has already been submitted.',
        processing: 'Processing...'
    };

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

    function isCheckable(el) { return el.type === 'checkbox' || el.type === 'radio'; }

    function fields(form) {
        return Array.prototype.filter.call(form.elements, function (el) {
            if (!el.name && !el.id) return false;
            if (el.disabled || el.readOnly && el.type !== 'file') return false;
            var tag = el.tagName;
            if (tag === 'BUTTON' || tag === 'FIELDSET' || tag === 'OBJECT' || tag === 'OUTPUT') return false;
            return ['hidden', 'submit', 'button', 'reset', 'image'].indexOf(el.type) === -1;
        });
    }

    /* ---------- where the message goes ---------- */
    function anchorFor(el) {
        if (el.dataset.errorTarget) {
            var target = document.querySelector(el.dataset.errorTarget);
            if (target) return target;
        }
        var parent = el.parentElement;
        // inputs wrapped with an icon / show-password button
        if (parent && parent.children.length > 1 && /relative/.test(parent.getAttribute('style') || '') && parent.tagName === 'DIV') {
            return parent;
        }
        if (isCheckable(el) && parent && parent.tagName === 'LABEL') return parent;
        return el;
    }

    function errorElFor(el) {
        if (el._fvError && el._fvError.isConnected) return el._fvError;
        var anchor = anchorFor(el);
        var next = anchor.nextElementSibling;
        if (next && next.classList.contains('field-error')) {
            el._fvError = next;
            return next;
        }
        return null;
    }

    function showError(el, message) {
        var box = errorElFor(el);
        if (!box) {
            box = document.createElement('div');
            box.className = 'field-error';
            box.setAttribute('role', 'alert');
            var anchor = anchorFor(el);
            anchor.insertAdjacentElement('afterend', box);
            el._fvError = box;
        }
        box.innerHTML = '';
        var icon = document.createElement('i');
        icon.className = 'fa-solid fa-circle-exclamation';
        var text = document.createElement('span');
        text.textContent = message;
        box.appendChild(icon);
        box.appendChild(document.createTextNode(' '));
        box.appendChild(text);
        box.style.display = '';
        el.classList.add('is-invalid');
        el.setAttribute('aria-invalid', 'true');
    }

    function clearError(el) {
        var box = errorElFor(el);
        if (box) box.remove();
        el._fvError = null;
        el.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
    }

    /* ---------- rules ---------- */
    function messageFor(el) {
        var value = isCheckable(el) ? '' : String(el.value || '');
        var trimmed = value.trim();
        var tag = el.tagName;

        if (el.type === 'file') {
            var file = el.files && el.files[0];
            if (!file) return el.required ? (el.dataset.msgRequired || MSG.required) : '';
            var maxSize = parseInt(el.dataset.maxSize || '0', 10);
            if (maxSize && file.size > maxSize) {
                return MSG.fileSize + ' (Maximum ' + Math.round(maxSize / 1048576) + ' MB.)';
            }
            var accept = (el.getAttribute('accept') || '').toLowerCase();
            if (accept) {
                var ok = accept.split(',').some(function (rule) {
                    rule = rule.trim();
                    if (!rule) return false;
                    if (rule.charAt(0) === '.') return file.name.toLowerCase().slice(-rule.length) === rule;
                    if (rule.slice(-2) === '/*') return (file.type || '').indexOf(rule.slice(0, -1)) === 0;
                    return file.type === rule;
                });
                // Some formats (e.g. HEIC) have no browser MIME type: let the server decide
                if (!ok && file.type) return el.dataset.msgType || MSG.fileType;
            }
            return '';
        }

        if (isCheckable(el)) {
            if (el.required && el.type === 'checkbox' && !el.checked) return el.dataset.msgRequired || MSG.required;
            if (el.required && el.type === 'radio') {
                var group = el.form ? el.form.querySelectorAll('input[type="radio"][name="' + el.name + '"]') : [el];
                var any = Array.prototype.some.call(group, function (r) { return r.checked; });
                if (!any) return el.dataset.msgRequired || MSG.choice;
            }
            return '';
        }

        if (trimmed === '') {
            if (el.required) {
                if (tag === 'SELECT') return el.dataset.msgRequired || MSG.choice;
                return el.dataset.msgRequired || (tag === 'TEXTAREA' ? MSG.empty : MSG.required);
            }
            return '';
        }

        if (el.dataset.phoneInput !== undefined) {
            if (!/^[0-9]{7,15}$/.test(trimmed)) return MSG.phone;
        }

        if (el.type === 'email' && !EMAIL_RE.test(trimmed)) return MSG.email;

        var minLen = parseInt(el.getAttribute('minlength') || '0', 10);
        var lenValue = el.type === 'password' ? value : trimmed;
        if (minLen && lenValue.length < minLen) {
            if (el.dataset.msgMinlength) return el.dataset.msgMinlength;
            if (el.type === 'password') return MSG.passwordShort;
            return 'This field must be at least ' + minLen + ' characters long.';
        }
        var maxLen = parseInt(el.getAttribute('maxlength') || '0', 10);
        if (maxLen && lenValue.length > maxLen) {
            if (tag === 'TEXTAREA') return MSG.tooLong + ' (Maximum ' + maxLen + ' characters.)';
            return 'This field must not exceed ' + maxLen + ' characters.';
        }

        if (el.dataset.passwordStrength !== undefined && (!/[A-Za-z]/.test(value) || !/[0-9]/.test(value))) {
            return MSG.passwordWeak;
        }

        if (el.dataset.match) {
            var other = document.querySelector(el.dataset.match);
            if (other && other.value !== value) return el.dataset.msgMatch || MSG.mismatch;
        }

        if (el.type === 'number') {
            if (el.validity && el.validity.badInput) return el.dataset.msgNumber || MSG.number;
            if (!/^-?\d+(\.\d+)?$/.test(trimmed)) return el.dataset.msgNumber || MSG.number;
            var num = parseFloat(trimmed);
            var step = el.getAttribute('step');
            if ((!step || step === '1') && !/^-?\d+$/.test(trimmed)) return el.dataset.msgNumber || MSG.wholeNumber;
            if (step && step !== 'any' && step !== '1') {
                var decimals = (step.split('.')[1] || '').length;
                var parts = trimmed.split('.');
                if (parts[1] && parts[1].length > decimals) {
                    return el.dataset.msgNumber || ('Please enter a valid amount with up to ' + decimals + ' decimal places.');
                }
            }
            var min = el.getAttribute('min');
            var max = el.getAttribute('max');
            if (min !== null && min !== '' && num < parseFloat(min)) return el.dataset.msgMin || ('Value must be at least ' + min + '.');
            if (max !== null && max !== '' && num > parseFloat(max)) return el.dataset.msgMax || ('Value must not be greater than ' + max + '.');
        }

        var pattern = el.getAttribute('pattern');
        if (pattern && el.dataset.phoneInput === undefined) {
            var re;
            try { re = new RegExp('^(?:' + pattern + ')$', 'u'); } catch (e) { re = null; }
            if (re && !re.test(trimmed)) return el.dataset.msgPattern || el.title || 'Please match the requested format.';
        }

        if (tag === 'SELECT' && el.required && el.selectedIndex >= 0 && el.options[el.selectedIndex].disabled) {
            return MSG.choice;
        }
        return '';
    }

    function validateField(el) {
        var message = messageFor(el);
        if (message) showError(el, message); else clearError(el);
        return !message;
    }

    /* ---------- phone fields ---------- */
    function setupPhone(el) {
        if (el._fvPhone) return;
        el._fvPhone = true;
        el.setAttribute('inputmode', 'numeric');
        if (!el.getAttribute('maxlength')) el.setAttribute('maxlength', '15');
        el.addEventListener('keydown', function (e) {
            if (e.ctrlKey || e.metaKey || e.altKey || !e.key || e.key.length !== 1) return;
            if (!/[0-9]/.test(e.key)) {
                e.preventDefault();
                showError(el, MSG.phoneKey);
            }
        });
        el.addEventListener('input', function () {
            var digits = el.value.replace(/[^0-9]/g, '').slice(0, 15);
            if (digits !== el.value) {
                el.value = digits;
                showError(el, MSG.phoneKey);
            }
        });
    }

    /* ---------- character counters ---------- */
    function setupCounter(el) {
        var max = parseInt(el.getAttribute('maxlength') || '0', 10);
        if (!max || el._fvCounter) return;
        var counter = document.createElement('small');
        counter.className = 'char-counter';
        el.insertAdjacentElement('afterend', counter);
        el._fvCounter = counter;
        var update = function () {
            var len = el.value.length;
            counter.textContent = len + ' / ' + max + ' characters';
            counter.classList.toggle('over', len >= max);
        };
        el.addEventListener('input', update);
        update();
    }

    /* ---------- submit locking ---------- */
    function lockForm(form) {
        form.dataset.submitting = '1';
        var buttons = form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
        Array.prototype.forEach.call(buttons, function (btn) {
            if (btn.name) { // keep the button's value in the request
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = btn.name;
                hidden.value = btn.value;
                form.appendChild(hidden);
            }
            btn._fvHtml = btn.innerHTML;
            btn.disabled = true;
            btn.classList.add('btn-loading');
            if (btn.tagName === 'BUTTON') {
                btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span>' + MSG.processing;
            } else {
                btn.value = MSG.processing;
            }
        });
    }

    function unlockForm(form) {
        delete form.dataset.submitting;
        var buttons = form.querySelectorAll('.btn-loading');
        Array.prototype.forEach.call(buttons, function (btn) {
            btn.disabled = false;
            btn.classList.remove('btn-loading');
            if (btn._fvHtml !== undefined) btn.innerHTML = btn._fvHtml;
        });
    }

    function setupForm(form) {
        if (form._fvReady) return;
        form._fvReady = true;
        form.noValidate = true; // our messages replace the browser bubbles

        fields(form).forEach(function (el) {
            if (el.dataset.phoneInput !== undefined) setupPhone(el);
            if (el.tagName === 'TEXTAREA' && el.dataset.counter !== undefined) setupCounter(el);

            var evt = (el.tagName === 'SELECT' || el.type === 'file' || isCheckable(el)) ? 'change' : 'input';
            el.addEventListener(evt, function () {
                // remove/update the message as soon as the user corrects the field
                if (el.classList.contains('is-invalid') || errorElFor(el)) validateField(el);
                // keep "confirm password" in sync
                if (form.querySelector('[data-match="#' + el.id + '"]')) {
                    var confirmEl = form.querySelector('[data-match="#' + el.id + '"]');
                    if (confirmEl.value !== '' && (confirmEl.classList.contains('is-invalid') || confirmEl.value.length >= el.value.length)) validateField(confirmEl);
                }
            });
            el.addEventListener('blur', function () {
                if (el.value !== '' || el.classList.contains('is-invalid')) validateField(el);
            });
        });

        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return; // e.g. a confirm() dialog was cancelled
            if (form.dataset.submitting) {
                e.preventDefault();
                return;
            }
            var firstInvalid = null;
            fields(form).forEach(function (el) {
                // skip fields inside hidden sections (e.g. card details when paying by COD)
                if (el.offsetParent === null && el.type !== 'hidden' && !el.closest('[data-validate-hidden]')) {
                    var style = window.getComputedStyle(el);
                    if (style.position !== 'fixed') { clearError(el); return; }
                }
                if (!validateField(el) && !firstInvalid) firstInvalid = el;
            });
            if (firstInvalid) {
                e.preventDefault();
                firstInvalid.focus({ preventScroll: true });
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            if (form.dataset.noLock === undefined) lockForm(form);
        });

        form.addEventListener('reset', function () {
            setTimeout(function () { clearErrors(form); }, 0);
        });
    }

    function clearErrors(form) {
        if (!form) return;
        fields(form).forEach(clearError);
        Array.prototype.forEach.call(form.querySelectorAll('.field-error'), function (box) { box.remove(); });
    }

    function init(root) {
        var scope = root || document;
        Array.prototype.forEach.call(scope.querySelectorAll('form'), function (form) {
            var method = (form.getAttribute('method') || 'get').toLowerCase();
            if (method === 'post' || form.dataset.validate !== undefined) setupForm(form);
        });
        // phone fields outside POST forms
        Array.prototype.forEach.call(scope.querySelectorAll('input[data-phone-input]'), setupPhone);
    }

    // Back/forward cache: re-enable buttons if the user returns to a submitted page
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) Array.prototype.forEach.call(document.querySelectorAll('form[data-submitting]'), unlockForm);
    });

    window.FormValidation = {
        init: init,
        validateField: validateField,
        clearErrors: clearErrors,
        showError: showError,
        clearError: clearError,
        unlock: unlockForm,
        messages: MSG
    };
    // kept for pages that call it when filling a phone field from code
    window.clearPhoneError = function (input) { if (input) clearError(input); };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
})();

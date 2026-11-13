(function () {
    'use strict';

    // Clear even a malformed credential before decoding it. Never put it back
    // into history, DOM attributes, storage, logs, or any request URL.
    var fragment = window.location.hash;
    var hadQuery = window.location.search !== '';
    var onLanding = window.location.pathname === '/reset-password';
    var cleared = false;
    try {
        window.history.replaceState(null, '', '/reset-password');
        cleared = true;
    } catch (error) {
        // A browser that refuses history replacement cannot use this flow.
    }

    var form = document.querySelector('[data-password-reset-fragment]');
    var status = document.getElementById('reset-status');
    if (!form || !status) return;

    var email = form.querySelector('[name="email"]');
    var password = form.querySelector('[name="password"]');
    var confirmation = form.querySelector('[name="password_confirmation"]');
    var csrf = form.querySelector('[name="_token"]');
    var submit = form.querySelector('button[type="submit"]');
    var signIn = document.querySelector('[data-reset-sign-in]');
    var fields = [email, password, confirmation, submit];
    var token = '';
    var busy = false;

    function message(text, error) {
        status.textContent = text;
        status.setAttribute('role', error ? 'alert' : 'status');
    }

    function disable() {
        token = '';
        fields.forEach(function (field) { if (field) field.disabled = true; });
        if (password) password.value = '';
        if (confirmation) confirmation.value = '';
    }

    function unknown() {
        disable();
        message('We could not confirm whether your password changed. Do not resubmit this form. Try signing in, or request a new reset link.', true);
    }

    function canonical(value) {
        return encodeURIComponent(value).replace(/[!'()*]/g, function (character) {
            return '%' + character.charCodeAt(0).toString(16).toUpperCase();
        });
    }

    if (form.hasAttribute('data-reset-authenticated')) {
        disable();
        fragment = '';
        message('You are already signed in. Sign out, then reopen your original reset link.', true);
        return;
    }

    try {
        var match = /^#v=1&token=([0-9a-f]{64})&email=([^&]+)$/.exec(fragment);
        if (!cleared || !onLanding || hadQuery || !match || !email || !password || !confirmation || !csrf || !submit || !signIn
            || typeof fetch !== 'function' || typeof AbortController !== 'function' || typeof TextDecoder !== 'function') throw new Error();
        var address = decodeURIComponent(match[2]);
        if (address.length > 254 || !/^[^\s@]+@[^\s@]+$/.test(address) || canonical(address) !== match[2]) throw new Error();
        var target = new URL(form.action, window.location.origin);
        var login = new URL(signIn.href, window.location.origin);
        if (target.origin !== window.location.origin || target.pathname !== '/reset-password' || target.search || target.hash
            || login.origin !== window.location.origin || login.pathname !== '/login' || login.search || login.hash
            || !csrf.value) throw new Error();
        email.value = address;
        if (!email.checkValidity()) throw new Error();
        token = match[1];
        fields.forEach(function (field) { field.disabled = false; });
        message('Choose your new password below.', false);
    } catch (error) {
        disable();
        message('This reset link is incomplete or invalid. Reopen the original link, or request a new one.', true);
    }
    // Drop the original encoded fragment; the token remains only in this closure.
    fragment = '';

    async function json(response, controller) {
        if ((response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase() !== 'application/json'
            || !response.body || !response.body.getReader) throw new Error();
        var reader = response.body.getReader();
        var chunks = [];
        var length = 0;
        while (true) {
            var part = await reader.read();
            if (part.done) break;
            length += part.value.byteLength;
            if (length > 16384) { controller.abort(); throw new Error(); }
            chunks.push(part.value);
        }
        var bytes = new Uint8Array(length);
        var offset = 0;
        chunks.forEach(function (chunk) { bytes.set(chunk, offset); offset += chunk.byteLength; });
        return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!token || busy || !form.reportValidity()) return;
        busy = true;
        submit.disabled = true;
        message('Setting your password…', false);
        var controller = new AbortController();
        var timer = window.setTimeout(function () { controller.abort(); }, 15000);
        try {
            var response = await fetch(target.href, {
                method: 'POST', mode: 'same-origin', credentials: 'same-origin',
                cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf.value },
                body: JSON.stringify({ token: token, email: email.value, password: password.value, password_confirmation: confirmation.value }),
                signal: controller.signal,
            });
            if (response.status === 419) {
                disable();
                message('This form has expired. Reopen your original reset link before trying again.', true);
                return;
            }
            if (response.redirected || (response.status !== 200 && response.status !== 422)) throw new Error();
            var result = await json(response, controller);
            if (!result || typeof result !== 'object' || Array.isArray(result)) throw new Error();
            if (response.status === 200) {
                if (Object.keys(result).length !== 1 || result.status !== 'password_reset') throw new Error();
                disable();
                signIn.hidden = false;
                message('Your password has been reset. Sign in with it now.', false);
                return;
            }
            var keys = Object.keys(result).sort();
            if (keys.join(',') !== 'errors,message' || typeof result.message !== 'string'
                || !result.errors || typeof result.errors !== 'object' || Array.isArray(result.errors)) throw new Error();
            var errors = Object.keys(result.errors);
            if (!errors.length || errors.some(function (key) {
                return ['token', 'email', 'password', 'password_confirmation'].indexOf(key) === -1
                    || !Array.isArray(result.errors[key]) || !result.errors[key].length
                    || result.errors[key].length > 10 || result.errors[key].some(function (text) { return typeof text !== 'string' || text.length > 512; });
            })) throw new Error();
            // Never reflect server strings: they can contain submitted values.
            password.value = '';
            confirmation.value = '';
            message('Check your email and password requirements, then try again. If the link has expired or was used, request a new one.', true);
            submit.disabled = false;
        } catch (error) {
            unknown();
        } finally {
            window.clearTimeout(timer);
            busy = false;
        }
    });
})();

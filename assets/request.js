/* Shared, bounded transport. Callers own UI state and cancellation generations. */
(() => {
    'use strict';
    async function request(op, data = {}, controller = new AbortController()) {
        const timeout = ['progress','logs','diagnostics','backup_progress'].includes(op) ? 8000 : ['cancel','recover'].includes(op) ? 20000 : 150000;
        let timedOut = false;
        const timer = setTimeout(() => { timedOut = true; controller.abort(); }, timeout);
        let response, text;
        try {
            const body = new URLSearchParams({action:'csm_admin', nonce:CSM.nonce, op, data:JSON.stringify(data)});
            response = await fetch(CSM.ajax, {method:'POST', credentials:'same-origin', body, signal:controller.signal});
            text = await response.text();
        } catch (error) {
            if (timedOut) error = new Error('Request timed out. Check Diagnostics.');
            error.retryable = true;
            throw error;
        } finally { clearTimeout(timer); }
        let result;
        try { result = JSON.parse(text); }
        catch (_) { throw Object.assign(new Error(`Unexpected response (HTTP ${response.status}). Check Diagnostics.`), {retryable:[429,502,503,504].includes(response.status)}); }
        if (!result || typeof result !== 'object' || !result.ok) {
            throw Object.assign(new Error(result?.error || 'Request failed.'), {retryable:!!result?.retryable, busy:!!result?.busy, retryExhausted:!!result?.retry_exhausted, retryAfter:result?.retry_after || 0});
        }
        return result.data;
    }
    globalThis.CSMRequest = request;
})();

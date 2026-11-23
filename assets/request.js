/* Shared, bounded transport. Callers own UI state and cancellation generations. */
(() => {
    'use strict';
    async function request(op, data = {}, controller = new AbortController()) {
        const timeout = ['compare_component','warm_media'].includes(op) ? 45000 : ['progress','logs','diagnostics','backup_progress'].includes(op) ? 8000 : ['cancel','recover'].includes(op) ? 20000 : 150000;
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
        const retryHeader = response.headers?.get('Retry-After');
        const headerWait = retryHeader ? (/^\d+$/.test(retryHeader.trim()) ? Number(retryHeader) : Math.max(0, Math.ceil((Date.parse(retryHeader) - Date.now()) / 1000))) : 0;
        const throttled = [429,430].includes(response.status);
        const retryAfter = Math.max(Number.isFinite(headerWait) ? headerWait : 0, throttled ? 30 : 0);
        let result;
        try { result = JSON.parse(text); }
        catch (_) { throw Object.assign(new Error(`Unexpected response (HTTP ${response.status}). Check Diagnostics.`), {retryable:[429,430,502,503,504].includes(response.status), retryExhausted:throttled, retryAfter}); }
        if (!result || typeof result !== 'object' || !result.ok) {
            throw Object.assign(new Error(result?.error || 'Request failed.'), {retryable:throttled || !!result?.retryable, busy:!!result?.busy, retryExhausted:throttled || !!result?.retry_exhausted, retryAfter:Math.max(retryAfter, Number(result?.retry_after) || 0)});
        }
        return result.data;
    }
    globalThis.CSMRequest = request;
})();

/**
 * CHWEB cw-social — HUELLE des admin2-Feldes «Vorschau» im Reiter «Teilen».
 *
 * admin2 ist eine Single-Page-App: sie holt die Feld-Skripte eines Plugins
 * einmal und registriert die Web-Component mit customElements.define --
 * danach laesst sie sich in dieser Seite nie mehr ersetzen. Deshalb steht
 * hier nur eine kleine Huelle, die sich nie aendern muss. Der KERN
 * (admin-next/kern/cwsocialvorschau.js) wird bei jedem Einbau frisch vom
 * Server geholt (GET /cw-social/kern/cwsocialvorschau, no-cache + ETag) und
 * ausgefuehrt. Ein neuer Kern gilt beim naechsten Oeffnen des Formulars --
 * ohne Neuladen des Admins (Skill cw-plugins-admin-live-vorschau).
 *
 * Vertrag des Kerns: exports.mount(el), exports.unmount(el), optional
 * exports.field(el, v) und exports.value(el, v).
 */
(function () {
    'use strict';
    const TAG = window.__GRAV_FIELD_TAG || 'cwsocialvorschau-field';
    const KERN = 'cwsocialvorschau';

    function apiBase() { return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1'); }
    function authHeaders() { const h = {}; if (window.__GRAV_API_TOKEN) { h['X-API-Token'] = window.__GRAV_API_TOKEN; } return h; }

    /** Kern laden; derselbe Quelltext wird nur einmal ausgewertet. */
    function ladeKern(name) {
        const lager = (window.__CW_KERNE = window.__CW_KERNE || {});
        const schluessel = 'cw-social:' + name;
        return fetch(apiBase() + '/cw-social/kern/' + encodeURIComponent(name), {
            headers: authHeaders(), cache: 'no-cache', credentials: 'same-origin'
        })
            .then((r) => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.text(); })
            .then((code) => {
                if (lager[schluessel] && lager[schluessel].code === code) { return lager[schluessel].K; }
                const K = new Function('exports', code + '\nreturn exports;')({});
                lager[schluessel] = { code: code, K: K };
                return K;
            });
    }

    class Huelle extends HTMLElement {
        constructor() { super(); this._field = null; this._value = null; this._K = null; }
        set field(v) { this._field = v; if (this._K && this._K.field) { this._K.field(this, v); } }
        get field() { return this._field; }
        set value(v) { this._value = v; if (this._K && this._K.value) { this._K.value(this, v); } }
        get value() { return this._value; }

        connectedCallback() {
            const self = this;
            this._verbunden = true;
            ladeKern(KERN)
                .then((K) => {
                    if (!self._verbunden) { return; }
                    self._K = K;
                    K.mount(self);
                })
                .catch((e) => {
                    self.innerHTML = '<p style="font-size:.8125rem;color:var(--color-red-700)">Vorschau konnte nicht geladen werden: ' + String(e.message).replace(/</g, '&lt;') + '</p>';
                });
        }
        disconnectedCallback() {
            this._verbunden = false;
            if (this._K && this._K.unmount) { this._K.unmount(this); }
        }
    }

    if (TAG && !customElements.get(TAG)) { customElements.define(TAG, Huelle); }
})();

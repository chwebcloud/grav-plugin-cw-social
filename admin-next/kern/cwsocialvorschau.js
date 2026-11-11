/**
 * CHWEB cw-social — KERN des admin2-Feldes «Vorschau» im Reiter «Teilen».
 *
 * Zeigt, wie der Beitrag auf Facebook und Instagram aussehen wird -- mit den
 * Werten, die GERADE im Formular stehen, bevor gespeichert ist. Kein Knopf:
 * die Vorschau folgt jeder Aenderung an Titel, Einleitung, Datum, Titelbild,
 * eigenem Text und den Schaltern (Skill cw-plugins-admin-live-vorschau).
 *
 * Wie das Feld an die Nachbarwerte kommt: admin2 reicht einer Web-Component
 * nur ihren eigenen Wert; seine Steuerelemente tragen weder name noch id.
 * Jedes Feld ist eine Zeile `div.grid …grid-cols-[minmax(0,1fr)_2fr]` mit
 * der Beschriftung links (<span class="font-semibold">) und dem
 * Steuerelement rechts. Wir gehen ueber die Zeilen und halten die
 * Beschriftung gegen die Tabelle Feld -> moegliche Beschriftungen (je
 * Theme und Sprache verschieden; `felder:` am Blueprint-Feld kann sie
 * ersetzen). Alle Reiter liegen im DOM, auch die verborgenen.
 *
 * Gerechnet wird auf dem Server (POST /cw-social/vorschau) -- dieselbe
 * Logik wie beim Teilen selbst, damit die Vorschau nicht luegt.
 */

const TAKT = 700;
const RUHE = 350;

const FELDER_VORGABE = {
    title:     ['Titel', 'Title', 'Titre', 'Titolo', 'Seitentitel'],
    summary:   ['Einleitung', 'Lead', 'Teaser', 'Zusammenfassung', 'Anriss', 'Summary', 'Introduction'],
    bild:      ['Titelbild', 'Bild', 'Beitragsbild', 'Cover image', 'Image'],
    datum:     ['Datum', 'Date', 'Data'],
    message:   ['Eigener Text (optional)', 'Custom text (optional)', 'Texte personnalisé (optionnel)', 'Testo personalizzato (opzionale)'],
    share:     ['Auf Facebook teilen', 'Share on Facebook', 'Partager sur Facebook', 'Condividi su Facebook'],
    instagram: ['Auf Instagram teilen', 'Share on Instagram', 'Partager sur Instagram', 'Condividi su Instagram'],
    erneut:    ['Nochmals teilen', 'Share again', 'Partager à nouveau', 'Condividi di nuovo']
};

/**
 * Eigene Texte der Vorschau in vier Sprachen.
 *
 * Die Liste darueber (FELDER_VORGABE) ist etwas anderes: Damit ERKENNT die
 * Vorschau die Felder des Formulars an ihrer sichtbaren Beschriftung, darum
 * stehen dort alle Sprachen nebeneinander. Hier geht es um das, was die
 * Vorschau selbst SCHREIBT.
 *
 * Die Sprache steht am <html>-Element: Admin 2.0 setzt `lang` beim Aufbau aus
 * der Spracheinstellung des Benutzers (siehe app/index.html). Ist nichts
 * gesetzt, gilt Englisch -- so haelt es Grav selbst.
 */
const TEXTE = {
    de: {
        live: 'Live-Vorschau · ', felder: ' Felder', gespeichert: 'Vorschau des gespeicherten Standes',
        erkannt: 'Erkannte Felder: ', keine: 'Im Formular wurden keine Felder erkannt.',
        ersteSpeicherung: 'Nach dem ersten Speichern zeigt die Vorschau, wie der Beitrag aussehen wird.',
        fehlgeschlagen: 'Vorschau fehlgeschlagen: ', httpFehler: 'HTTP-Fehler',
        netzfehler: 'Netzwerkfehler bei der Vorschau.'
    },
    en: {
        live: 'Live preview · ', felder: ' fields', gespeichert: 'Preview of the saved state',
        erkannt: 'Fields recognised: ', keine: 'No fields were recognised in this form.',
        ersteSpeicherung: 'After the first save, the preview shows what the post will look like.',
        fehlgeschlagen: 'Preview failed: ', httpFehler: 'HTTP error',
        netzfehler: 'Network error while loading the preview.'
    },
    fr: {
        live: 'Aperçu en direct · ', felder: ' champs', gespeichert: 'Aperçu de l’état enregistré',
        erkannt: 'Champs reconnus : ', keine: 'Aucun champ n’a été reconnu dans ce formulaire.',
        ersteSpeicherung: 'Après le premier enregistrement, l’aperçu montre à quoi ressemblera la publication.',
        fehlgeschlagen: 'Échec de l’aperçu : ', httpFehler: 'Erreur HTTP',
        netzfehler: 'Erreur réseau lors du chargement de l’aperçu.'
    },
    it: {
        live: 'Anteprima dal vivo · ', felder: ' campi', gespeichert: 'Anteprima dello stato salvato',
        erkannt: 'Campi riconosciuti: ', keine: 'Nessun campo riconosciuto in questo modulo.',
        ersteSpeicherung: 'Dopo il primo salvataggio l’anteprima mostra come apparirà il post.',
        fehlgeschlagen: 'Anteprima non riuscita: ', httpFehler: 'Errore HTTP',
        netzfehler: 'Errore di rete durante il caricamento dell’anteprima.'
    }
};

function t(schluessel) {
    const code = String(document.documentElement.lang || 'en').slice(0, 2).toLowerCase();
    return (TEXTE[code] || TEXTE.en)[schluessel];
}

function apiBase() { return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1'); }
function authHeaders(extra) { const h = extra || {}; if (window.__GRAV_API_TOKEN) { h['X-API-Token'] = window.__GRAV_API_TOKEN; } return h; }
function glatt(text) { return String(text || '').replace(/[*:]/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase(); }
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
function nl(s) { return esc(s).replace(/\n/g, '<br>'); }

/** Adresse der Seite aus der Admin-URL: /admin/pages/edit/<route>. */
function seitenRoute() {
    const p = window.location.pathname || '';
    const i = p.indexOf('/pages/edit');
    if (i < 0) { return ''; }
    const rest = p.slice(i + '/pages/edit'.length).replace(/\/+$/, '');
    return rest && rest !== '/' ? decodeURIComponent(rest) : '';
}

/** Zustand am Element (ueberlebt einen Kern-Wechsel). */
function st(el) {
    if (!el._cwsv) { el._cwsv = { timer: null, takt: null, letzt: '', laeuft: false, nochmal: false, onInput: null }; }
    return el._cwsv;
}

function felder(el) {
    const f = el.field || {};
    const eigene = (f.felder && typeof f.felder === 'object') ? f.felder : {};
    const out = {};
    Object.keys(FELDER_VORGABE).forEach((k) => {
        const v = eigene[k];
        out[k] = Array.isArray(v) ? v : (v ? [v] : FELDER_VORGABE[k]);
    });
    return out;
}

function eigenerText(el) {
    let t = '';
    el.childNodes.forEach((k) => { if (k.nodeType === 3) { t += k.textContent; } });
    return t.trim() || el.textContent.trim();
}

function zeilen(el) {
    const wurzel = el.closest('form') || document;
    return Array.from(wurzel.querySelectorAll('[class*="grid-cols-[minmax(0,1fr)_2fr]"]')).filter((z) => !z.contains(el));
}

function zeilenWert(z) {
    const c = z.querySelector('input:not([type=file]):not([type=hidden]):not([type=button]):not([type=submit]), select, textarea');
    if (c) {
        const typ = (c.type || '').toLowerCase();
        if (typ === 'checkbox' || typ === 'radio') { return c.checked ? c.value : null; }
        return c.value;
    }
    const schalter = z.querySelector('[role="switch"][aria-checked]');
    if (schalter) { return schalter.getAttribute('aria-checked') === 'true' ? '1' : '0'; }
    // Schalter von admin2 (toggle), gemessen am 07.10.2026 auf saloon.localhost:
    // ein Raster mit einem gleitenden Indikator (div.absolute, style left:
    // calc(0% + 2px) fuer den ersten Knopf) und je Option ein <button>; der
    // gewaehlte traegt text-white, die anderen text-muted-foreground. Kein
    // aria-pressed, kein data-state. Wert aus der Beschriftung: Ja/Nein.
    const knoepfe = Array.from(z.querySelectorAll('button')).filter((b) => b.textContent.trim().length > 0 && b.textContent.trim().length <= 24);
    if (knoepfe.length >= 2) {
        let aktiv = knoepfe.findIndex((b) => /(^|\s)text-white(\s|$)/.test(b.className) || b.getAttribute('aria-pressed') === 'true' || b.getAttribute('aria-checked') === 'true' || b.getAttribute('data-state') === 'checked' || b.getAttribute('data-state') === 'on');
        if (aktiv < 0) {
            const ind = z.querySelector('.absolute');
            const m = ind && /left:\s*calc\((\d+(?:\.\d+)?)%/.exec(ind.getAttribute('style') || '');
            if (m) { aktiv = Math.round(parseFloat(m[1]) / (100 / knoepfe.length)); }
        }
        if (aktiv >= 0 && aktiv < knoepfe.length) {
            const t = glatt(knoepfe[aktiv].textContent);
            if (/^(ja|yes|oui|sì|si|aktiviert|enabled|ein|an|on)$/.test(t)) { return '1'; }
            if (/^(nein|no|non|deaktiviert|disabled|aus|off)$/.test(t)) { return '0'; }
            return aktiv === 0 ? '1' : '0';
        }
    }
    const combo = z.querySelector('[role="combobox"]');
    if (combo) { return combo.value != null && combo.value !== '' ? combo.value : combo.textContent.trim(); }
    return null;
}

/** Werte der Nachbarfelder: { werte, anzahl }. */
function werte(el) {
    const tabelle = felder(el);
    const nachLabel = {};
    Object.keys(tabelle).forEach((k) => { tabelle[k].forEach((l) => { nachLabel[glatt(l)] = k; }); });
    const out = {};
    zeilen(el).forEach((z) => {
        const kopf = z.querySelector('.font-semibold');
        if (!kopf) { return; }
        const name = nachLabel[glatt(eigenerText(kopf))];
        if (!name || name in out) { return; }
        const wert = zeilenWert(z);
        if (wert === null) { return; }
        out[name] = wert;
    });
    return { werte: out, anzahl: Object.keys(out).length };
}

function msg(el, text, isErr) {
    const m = el.querySelector('[data-msg]');
    if (m) { m.textContent = text || ''; m.className = 'cwsv-msg' + (isErr ? ' cwsv-err' : ''); }
}

function planen(el, ms) {
    const s = st(el);
    if (s.timer) { clearTimeout(s.timer); }
    s.timer = setTimeout(() => zeichnen(el, false), ms);
}

function initiale(name) { return (String(name || '').trim().charAt(0) || '•').toUpperCase(); }

/** Statuszeile je Kanal: geteilt am … (Beitrag ansehen) oder noch nicht geteilt. */
function statusZeile(name, st, aktiv, moeglich) {
    const s = st || {};
    let text;
    if (moeglich === false) { text = 'nicht eingerichtet'; }
    else if (s.geteilt) { text = 'geteilt' + (s.am ? ' am ' + esc(s.am) : '') + (s.url ? ' · <a href="' + esc(s.url) + '" target="_blank" rel="noopener">Beitrag ansehen</a>' : ''); }
    else { text = aktiv ? 'noch nicht geteilt – geht nach dem Speichern raus' : 'noch nicht geteilt'; }
    return '<span class="cwsv-status' + (s.geteilt ? ' cwsv-status-ok' : '') + '"><strong>' + esc(name) + ':</strong> ' + text + '</span>';
}

function karteFacebook(d) {
    const fb = d.facebook || {};
    const k = fb.karte || {};
    return ''
        + '<div class="cwsv-post cwsv-fb' + (fb.aktiv ? '' : ' cwsv-aus') + '">'
        +   '<div class="cwsv-post-kopf"><span class="cwsv-avatar">' + esc(initiale(d.seite)) + '</span>'
        +     '<span><strong>' + esc(d.seite) + '</strong><br><small>Gerade eben · <span title="Öffentlich">🌐</span></small></span>'
        +     '<span class="cwsv-kanal">Facebook' + (fb.aktiv ? '' : ' · aus') + '</span></div>'
        +   '<div class="cwsv-post-text">' + nl(fb.text) + '</div>'
        +   '<a class="cwsv-fb-karte" href="' + esc(d.link) + '" target="_blank" rel="noopener">'
        +     (k.bild ? '<img src="' + esc(k.bild) + '" alt="">' : '<div class="cwsv-kein-bild">Kein Bild auf der Seite</div>')
        +     '<div class="cwsv-fb-karte-text"><small>' + esc(k.domain) + '</small><strong>' + esc(k.titel) + '</strong>'
        +     (k.beschreibung ? '<span>' + esc(k.beschreibung) + '</span>' : '') + '</div></a>'
        +   '<div class="cwsv-post-fuss"><span>👍 Gefällt mir</span><span>💬 Kommentieren</span><span>↗ Teilen</span></div>'
        + '</div>';
}

function karteInstagram(d) {
    const ig = d.instagram || {};
    const handle = String(d.seite || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
    if (!ig.moeglich) {
        return '<div class="cwsv-post cwsv-ig cwsv-aus"><div class="cwsv-post-kopf"><span class="cwsv-avatar cwsv-avatar-ig">' + esc(initiale(d.seite)) + '</span><span><strong>' + esc(handle) + '</strong></span><span class="cwsv-kanal">Instagram · nicht eingerichtet</span></div><p class="cwsv-hint">Instagram ist in den Plugin-Einstellungen ausgeschaltet.</p></div>';
    }
    return ''
        + '<div class="cwsv-post cwsv-ig' + (ig.aktiv ? '' : ' cwsv-aus') + '">'
        +   '<div class="cwsv-post-kopf"><span class="cwsv-avatar cwsv-avatar-ig">' + esc(initiale(d.seite)) + '</span>'
        +     '<span><strong>' + esc(handle) + '</strong></span>'
        +     '<span class="cwsv-kanal">Instagram' + (ig.aktiv ? '' : ' · aus') + '</span></div>'
        +   (ig.bild ? '<div class="cwsv-ig-bild"><img src="' + esc(ig.bild) + '" alt=""></div>' : '<div class="cwsv-ig-bild cwsv-kein-bild">Kein Bild auf der Seite – ohne Bild kein Instagram-Beitrag</div>')
        +   '<div class="cwsv-post-fuss cwsv-ig-fuss"><span>♡</span><span>💬</span><span>↗</span></div>'
        +   '<div class="cwsv-post-text"><strong>' + esc(handle) + '</strong> ' + nl(ig.text) + '</div>'
        + '</div>';
}

function zeichnen(el, erzwingen) {
    const s = st(el);
    const route = seitenRoute();
    const gesammelt = werte(el);
    const payload = Object.assign({ route: route }, gesammelt.werte);
    const json = JSON.stringify(payload);
    if (!erzwingen && json === s.letzt) { return; }
    if (s.laeuft) { s.nochmal = true; return; }
    s.laeuft = true;
    s.letzt = json;

    const live = el.querySelector('[data-live]');
    if (live) {
        live.textContent = gesammelt.anzahl > 0 ? t('live') + gesammelt.anzahl + t('felder') : t('gespeichert');
        live.title = gesammelt.anzahl > 0 ? t('erkannt') + Object.keys(gesammelt.werte).join(', ') : t('keine');
    }

    if (!route) {
        el.querySelector('[data-posts]').innerHTML = '<p class="cwsv-hint">' + esc(t('ersteSpeicherung')) + '</p>';
        s.laeuft = false;
        return;
    }

    fetch(apiBase() + '/cw-social/vorschau', {
        method: 'POST', headers: authHeaders({ 'Content-Type': 'application/json' }), credentials: 'same-origin', body: json
    })
        .then((r) => r.json().then((j) => ({ ok: r.ok, j })).catch(() => ({ ok: false, j: null })))
        .then(({ ok, j }) => {
            if (!ok) {
                const e = (j && (j.error || j)) || {};
                msg(el, t('fehlgeschlagen') + (e.detail || e.title || e.message || t('httpFehler')), true);
                return;
            }
            const d = (j && j.data) || j || {};
            const st = d.status || {};
            el.querySelector('[data-status]').innerHTML = statusZeile('Facebook', st.facebook, d.facebook && d.facebook.aktiv, true)
                + statusZeile('Instagram', st.instagram, d.instagram && d.instagram.aktiv, !(d.instagram && d.instagram.moeglich === false));
            el.querySelector('[data-posts]').innerHTML = karteFacebook(d) + karteInstagram(d);
            const h = Array.isArray(d.hinweise) ? d.hinweise : [];
            el.querySelector('[data-hinweise]').innerHTML = h.map((t) => '<li>' + esc(t) + '</li>').join('');
            msg(el, '');
        })
        .catch(() => msg(el, t('netzfehler'), true))
        .finally(() => {
            s.laeuft = false;
            if (s.nochmal) { s.nochmal = false; zeichnen(el, false); }
        });
}

function injectStyle() {
    if (document.getElementById('cwsv-style')) { return; }
    const css =
        '.cwsv-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:0 0 .6rem}' +
        '.cwsv-live{font-size:.75rem;font-weight:600;color:var(--muted-foreground);border:1px solid var(--border);border-radius:calc(var(--radius) - 2px);padding:.15rem .5rem}' +
        '.cwsv-msg{font-size:.8125rem;color:var(--muted-foreground)}.cwsv-err{color:var(--color-red-700)}.dark .cwsv-err{color:var(--color-red-300)}' +
        '.cwsv-posts{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(18rem,1fr));align-items:start}' +
        '.cwsv-post{background:#fff;color:#050505;border:1px solid #dadde1;border-radius:10px;overflow:hidden;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:.9rem;line-height:1.35;max-width:32rem}' +
        '.cwsv-aus{opacity:.45;filter:grayscale(1)}' +
        '.cwsv-post-kopf{display:flex;align-items:center;gap:.6rem;padding:.7rem .9rem}.cwsv-post-kopf small{color:#65676b;font-size:.75rem}' +
        '.cwsv-avatar{flex:none;width:2.4rem;height:2.4rem;border-radius:50%;background:#1b74e4;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700}' +
        '.cwsv-avatar-ig{background:linear-gradient(45deg,#f9ce34,#ee2a7b,#6228d7)}' +
        '.cwsv-kanal{margin-left:auto;font-size:.7rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#65676b}' +
        '.cwsv-post-text{padding:0 .9rem .7rem;white-space:normal;word-break:break-word}' +
        '.cwsv-fb-karte{display:block;color:inherit;text-decoration:none;border-top:1px solid #dadde1;border-bottom:1px solid #dadde1;background:#f0f2f5}' +
        '.cwsv-fb-karte img{display:block;width:100%;aspect-ratio:1.91/1;object-fit:cover}' +
        '.cwsv-fb-karte-text{display:grid;gap:.15rem;padding:.6rem .9rem}.cwsv-fb-karte-text small{color:#65676b;font-size:.72rem}.cwsv-fb-karte-text strong{font-size:.95rem}.cwsv-fb-karte-text span{color:#65676b;font-size:.8rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}' +
        '.cwsv-kein-bild{display:flex;align-items:center;justify-content:center;aspect-ratio:1.91/1;background:#e4e6eb;color:#65676b;font-size:.8rem;text-align:center;padding:1rem}' +
        '.cwsv-post-fuss{display:flex;justify-content:space-around;padding:.5rem .9rem;color:#65676b;font-size:.8rem;border-top:1px solid #dadde1}' +
        '.cwsv-ig-bild img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover}.cwsv-ig-bild.cwsv-kein-bild{aspect-ratio:1/1}' +
        '.cwsv-ig-fuss{justify-content:flex-start;gap:1rem;border-top:0;font-size:1.1rem}' +
        '.cwsv-statusleiste{display:flex;flex-wrap:wrap;gap:.4rem 1.4rem;margin:0 0 .7rem;font-size:.8125rem;color:var(--muted-foreground)}' +
        '.cwsv-status strong{color:var(--foreground);font-weight:600}.cwsv-status-ok{color:var(--foreground)}.cwsv-status a{color:var(--primary);text-decoration:underline}' +
        '.cwsv-hinweise{margin:.6rem 0 0;padding-left:1.1rem;font-size:.8125rem;color:var(--muted-foreground)}.cwsv-hinweise li{margin:.15rem 0}' +
        '.cwsv-hint{font-size:.8125rem;color:var(--muted-foreground);margin:.4rem 0 0;padding:0 .9rem .9rem}';
    const s = document.createElement('style'); s.id = 'cwsv-style'; s.textContent = css;
    document.head.appendChild(s);
}

exports.mount = function (el) {
    const s = st(el);
    el.innerHTML = ''
        + '<div class="cwsv-bar"><span class="cwsv-live" data-live>Live-Vorschau</span><span class="cwsv-msg" data-msg></span></div>'
        + '<div class="cwsv-statusleiste" data-status></div>'
        + '<div class="cwsv-posts" data-posts></div>'
        + '<ul class="cwsv-hinweise" data-hinweise></ul>';
    injectStyle();
    s.onInput = () => planen(el, RUHE);
    document.addEventListener('input', s.onInput, true);
    document.addEventListener('change', s.onInput, true);
    s.takt = setInterval(() => zeichnen(el, false), TAKT);
    zeichnen(el, true);
};

exports.unmount = function (el) {
    const s = st(el);
    if (s.onInput) {
        document.removeEventListener('input', s.onInput, true);
        document.removeEventListener('change', s.onInput, true);
    }
    if (s.timer) { clearTimeout(s.timer); }
    if (s.takt) { clearInterval(s.takt); }
    s.timer = null; s.takt = null;
};

// Fuer Pruefungen ausserhalb des Admins (Skill cw-plugins-admin-live-vorschau).
exports.werte = werte;
exports.karteFacebook = karteFacebook;
exports.karteInstagram = karteInstagram;

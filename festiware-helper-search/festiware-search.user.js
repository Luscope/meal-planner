// ==UserScript==
// @name         Festiware Helfer/Ticket-Suche
// @namespace    bfof-tools
// @version      0.1.0
// @description  Overlay-Suche für Helfer/Ticketbesitzer + Ticketcode direkt auf bfof.festiwa.re (nutzt die eingeloggte Session, keine eigene Auth nötig)
// @match        https://bfof.festiwa.re/*
// @grant        none
// @run-at       document-idle
// ==/UserScript==

(function () {
    'use strict';

    const TOGGLE_HOTKEY = { ctrlKey: true, shiftKey: true, key: 'f' };
    const DEBOUNCE_MS = 350;
    const MIN_CHARS = 2;

    let panelOpen = false;
    let debounceTimer = null;
    let latestRequestId = 0;

    const style = document.createElement('style');
    style.textContent = `
        #bfof-search-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 999999;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: none;
            background: #1f2937;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        #bfof-search-toggle:hover { background: #374151; }

        #bfof-search-panel {
            position: fixed;
            bottom: 80px;
            right: 20px;
            z-index: 999999;
            width: 380px;
            max-height: 60vh;
            display: none;
            flex-direction: column;
            background: #fff;
            color: #111827;
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.35);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            overflow: hidden;
        }
        #bfof-search-panel.open { display: flex; }
        #bfof-search-header {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #bfof-search-close {
            cursor: pointer;
            border: none;
            background: none;
            font-size: 16px;
            color: #6b7280;
            line-height: 1;
        }
        #bfof-search-input {
            margin: 10px 12px;
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        #bfof-search-input:focus { outline: 2px solid #2563eb; }
        #bfof-search-results {
            overflow-y: auto;
            padding: 0 6px 10px 6px;
        }
        .bfof-result {
            display: block;
            padding: 8px 10px;
            border-radius: 6px;
            text-decoration: none;
            color: inherit;
        }
        .bfof-result:hover { background: #f3f4f6; }
        .bfof-result-type {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #6b7280;
        }
        .bfof-result-title {
            font-size: 14px;
            font-weight: 500;
        }
        .bfof-empty, .bfof-hint {
            padding: 10px 12px;
            font-size: 13px;
            color: #6b7280;
        }
    `;
    document.head.appendChild(style);

    const toggleBtn = document.createElement('button');
    toggleBtn.id = 'bfof-search-toggle';
    toggleBtn.title = 'Helfer/Ticket-Suche (Strg+Umschalt+F)';
    toggleBtn.textContent = '🔍';
    document.body.appendChild(toggleBtn);

    const panel = document.createElement('div');
    panel.id = 'bfof-search-panel';
    panel.innerHTML = `
        <div id="bfof-search-header">
            <span>Helfer / Ticket-Suche</span>
            <button id="bfof-search-close" title="Schließen">✕</button>
        </div>
        <input id="bfof-search-input" type="text" placeholder="Name oder Ticketcode eingeben..." autocomplete="off" />
        <div id="bfof-search-results"><div class="bfof-hint">Mindestens ${MIN_CHARS} Zeichen eingeben.</div></div>
    `;
    document.body.appendChild(panel);

    const input = panel.querySelector('#bfof-search-input');
    const resultsEl = panel.querySelector('#bfof-search-results');
    const closeBtn = panel.querySelector('#bfof-search-close');

    function openPanel() {
        panelOpen = true;
        panel.classList.add('open');
        input.focus();
    }

    function closePanel() {
        panelOpen = false;
        panel.classList.remove('open');
    }

    function togglePanel() {
        if (panelOpen) closePanel(); else openPanel();
    }

    toggleBtn.addEventListener('click', togglePanel);
    closeBtn.addEventListener('click', closePanel);

    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey === TOGGLE_HOTKEY.ctrlKey && e.shiftKey === TOGGLE_HOTKEY.shiftKey &&
            e.key.toLowerCase() === TOGGLE_HOTKEY.key) {
            e.preventDefault();
            togglePanel();
        } else if (e.key === 'Escape' && panelOpen) {
            closePanel();
        }
    });

    function renderResults(items) {
        resultsEl.innerHTML = '';
        if (!items || items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'bfof-empty';
            empty.textContent = 'Keine Treffer.';
            resultsEl.appendChild(empty);
            return;
        }
        for (const item of items) {
            const a = document.createElement('a');
            a.className = 'bfof-result';
            a.href = item.url || '#';
            a.target = '_blank';
            a.rel = 'noopener noreferrer';

            const typeEl = document.createElement('div');
            typeEl.className = 'bfof-result-type';
            typeEl.textContent = item.resourceTitle || item.resourceName || '';

            const titleEl = document.createElement('div');
            titleEl.className = 'bfof-result-title';
            titleEl.textContent = item.title || '';

            a.appendChild(typeEl);
            a.appendChild(titleEl);
            resultsEl.appendChild(a);
        }
    }

    async function runSearch(query) {
        const requestId = ++latestRequestId;
        try {
            const res = await fetch(`/nova-api/search?search=${encodeURIComponent(query)}`, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (requestId !== latestRequestId) return; // a newer request superseded this one
            if (!res.ok) {
                resultsEl.innerHTML = `<div class="bfof-empty">Fehler bei der Suche (${res.status}). Evtl. Session abgelaufen?</div>`;
                return;
            }
            const data = await res.json();
            if (requestId !== latestRequestId) return;
            renderResults(data);
        } catch (err) {
            if (requestId !== latestRequestId) return;
            resultsEl.innerHTML = '<div class="bfof-empty">Netzwerkfehler bei der Suche.</div>';
        }
    }

    input.addEventListener('input', () => {
        const query = input.value.trim();
        clearTimeout(debounceTimer);
        if (query.length < MIN_CHARS) {
            resultsEl.innerHTML = `<div class="bfof-hint">Mindestens ${MIN_CHARS} Zeichen eingeben.</div>`;
            return;
        }
        debounceTimer = setTimeout(() => runSearch(query), DEBOUNCE_MS);
    });
})();

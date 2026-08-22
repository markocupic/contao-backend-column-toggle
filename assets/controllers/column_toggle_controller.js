import {Controller} from '@hotwired/stimulus';

const NS = 'cbct-column-toggle';
const HIDDEN_CLASS = `${NS}--hidden`;

// lucide "columns-3-cog"
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10.6 21H5a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v5.6"/><path d="m14.305 19.53.923-.382"/><path d="M15 3v7.6"/><path d="m15.229 16.852-.924-.383"/><path d="m16.852 15.228-.383-.923"/><path d="m16.852 20.772-.383.924"/><path d="m19.148 15.228.383-.923"/><path d="m19.53 21.696-.382-.924"/><path d="m20.773 16.852.922-.383"/><path d="m20.773 19.148.922.383"/><path d="M9 3v18"/><circle cx="18" cy="18" r="3"/></svg>';

/*
 * Toggle the columns of a Contao back end list view (list.label.showColumns).
 *
 * The controller is bound to an invisible data element that the
 * ColumnToggleListener injects as a global operation. The whole UI (clickable
 * header cells and the "columns" dropdown) is created at runtime, so no core
 * template has to be overridden.
 *
 * Columns the user has hidden are removed from the DCA on the server side,
 * therefore showing a column again requires a page reload, while hiding one
 * can be done optimistically in the DOM.
 */
export default class extends Controller {
    static values = {
        table: String,
        url: String,
        token: String,
        columns: Array,
        hidden: Array,
        i18n: Object,
    };

    connect() {
        this.abortController = new AbortController();
        this.hidden = new Set(this.hiddenValue);

        // The state the server has rendered. Making one of these columns visible
        // again requires a reload, because it is not part of the markup at all.
        this.renderedHidden = Array.from(this.hidden);

        this.pending = null;
        this.revision = 0;
        this.retries = 0;

        this.boot();
    }

    disconnect() {
        this.teardown();
        this.abortController?.abort();
        this.abortController = null;
    }

    boot() {
        this.table = this.findTable();

        if (!this.table) {
            // The listing may not be in the DOM yet (Turbo)
            if (this.retries++ < 5) {
                window.requestAnimationFrame(() => this.abortController && this.boot());
            }

            return;
        }

        this.columns = this.collectColumns();

        if (this.columns.length < 2) {
            return;
        }

        // Make sure we never inject our UI twice
        this.table.querySelectorAll(`.${NS}`).forEach((el) => el.remove());

        this.decorateHeaders();
        this.renderTrigger();

        // A snapshot restored by Turbo may carry a stale visibility state
        this.applyState();

        // Keep the hidden columns hidden in the Turbo snapshot, otherwise every
        // cached preview flashes the full table before the fresh response arrives
        document.addEventListener('turbo:before-cache', () => this.teardown(false), {signal: this.abortController.signal});
    }

    /**
     * @returns {HTMLTableElement|null}
     */
    findTable() {
        const table = document.querySelector('table.tl_listing.showColumns');

        if (!table || !table.tHead || !table.tHead.rows.length) {
            return null;
        }

        // Bail out if the record label is rendered as one spanning column,
        // because in this case the columns do not match the header cells.
        if (table.querySelector('tbody td[colspan]:not([colspan="1"])')) {
            return null;
        }

        return table;
    }

    /**
     * @returns {{name: string, th: HTMLTableCellElement}[]}
     */
    headerCells() {
        const cells = [];
        const row = this.table?.tHead?.rows[0];

        if (!row) {
            return cells;
        }

        Array.from(row.cells).forEach((th) => {
            // Contao renders the field name as a "col_<name>" class. A bare
            // "col_" belongs to the icon column (tl_member and tl_user define an
            // empty first entry in list.label.fields for it) - that column has no
            // label, cannot be identified and is rejected by the controller
            // anyway, so it must not be toggleable.
            const cssClass = Array.from(th.classList).find((name) => name.startsWith('col_') && name.length > 4);

            if (cssClass) {
                cells.push({name: cssClass.slice(4), th});
            }
        });

        return cells;
    }

    /**
     * Merge the field list of the server with the columns that are actually
     * rendered (Contao may add the current sorting field on its own).
     *
     * @returns {{name: string, label: string}[]}
     */
    collectColumns() {
        const map = new Map();

        this.columnsValue.forEach((column) => {
            if (column && column.name) {
                map.set(column.name, column.label || column.name);
            }
        });

        this.headerCells().forEach(({name, th}) => {
            if (!map.has(name)) {
                map.set(name, th.textContent.trim() || name);
            }
        });

        return Array.from(map, ([name, label]) => ({name, label}));
    }

    decorateHeaders() {
        const signal = this.abortController.signal;

        this.headerCells().forEach(({name, th}) => {
            th.classList.add(`${NS}__header`);
            th.setAttribute('role', 'button');
            th.setAttribute('tabindex', '0');
            th.setAttribute('title', this.trans('hideColumn'));

            // The "x" is anchored to the label, not to the cell, so it stays
            // next to the word even if the column is much wider than its title
            this.wrapLabel(th);

            th.addEventListener('click', (event) => {
                event.preventDefault();
                this.hideColumn(name);
            }, {signal});

            th.addEventListener('keydown', (event) => {
                if ('Enter' === event.key || ' ' === event.key) {
                    event.preventDefault();
                    this.hideColumn(name);
                }
            }, {signal});
        });
    }

    /**
     * Wrap the text of a header cell in a span we can anchor the "x" to.
     */
    wrapLabel(th) {
        if (th.querySelector(`.${NS}__label`)) {
            return;
        }

        const label = document.createElement('span');
        label.className = `${NS}__label`;

        while (th.firstChild) {
            label.appendChild(th.firstChild);
        }

        th.appendChild(label);
    }

    unwrapLabel(th) {
        const label = th.querySelector(`.${NS}__label`);

        if (!label) {
            return;
        }

        while (label.firstChild) {
            th.insertBefore(label.firstChild, label);
        }

        label.remove();
    }

    renderTrigger() {
        const signal = this.abortController.signal;

        // Place the button among the global operations. Contao 5.6+ renders them
        // as a list, up to Contao 5.5 #tl_buttons is a plain div of links.
        const menu = document.querySelector('#tl_buttons > ul');
        const host = menu || document.querySelector('#tl_buttons');

        if (!host) {
            return;
        }

        this.wrapper = document.createElement(menu ? 'li' : 'span');
        this.wrapper.className = NS;

        this.trigger = document.createElement('button');
        this.trigger.type = 'button';
        this.trigger.className = `${NS}__trigger has-icon`;
        this.trigger.title = this.trans('columnsTitle');
        this.trigger.setAttribute('aria-expanded', 'false');
        this.trigger.innerHTML = ICON;
        this.trigger.append(this.trans('columns'));

        this.panel = document.createElement('div');
        this.panel.className = `${NS}__panel`;
        this.panel.hidden = true;

        this.wrapper.append(this.trigger, this.panel);

        // Keep our button in front of the "more operations" toggle
        const more = menu ? menu.querySelector('.operations-menu-container') : null;

        if (more) {
            host.insertBefore(this.wrapper, more);
        } else {
            host.appendChild(this.wrapper);
        }

        this.renderPanel();

        this.trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            this.togglePanel();
        }, {signal});

        document.addEventListener('click', (event) => {
            if (this.wrapper && !this.wrapper.contains(event.target)) {
                this.closePanel();
            }
        }, {signal});

        document.addEventListener('keydown', (event) => {
            if ('Escape' === event.key) {
                this.closePanel();
            }
        }, {signal});
    }

    renderPanel() {
        const signal = this.abortController.signal;

        this.panel.textContent = '';

        const list = document.createElement('div');
        list.className = `${NS}__list`;

        this.checkboxes = new Map();

        this.columns.forEach(({name, label}) => {
            const item = document.createElement('label');
            item.className = `${NS}__item`;

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'tl_checkbox';
            checkbox.value = name;
            checkbox.checked = !this.hidden.has(name);

            checkbox.addEventListener('change', () => {
                if (checkbox.checked) {
                    this.showColumn(name);
                } else {
                    this.hideColumn(name);
                }
            }, {signal});

            const text = document.createElement('span');
            text.textContent = label;

            item.append(checkbox, text);
            list.appendChild(item);

            this.checkboxes.set(name, checkbox);
        });

        const reset = document.createElement('button');
        reset.type = 'button';
        reset.className = `${NS}__reset`;
        reset.textContent = this.trans('showAll');
        reset.addEventListener('click', (event) => {
            event.preventDefault();
            this.showAllColumns();
        }, {signal});

        this.message = document.createElement('p');
        this.message.className = `${NS}__message`;
        this.message.hidden = true;

        this.panel.append(list, reset, this.message);
    }

    togglePanel() {
        this.panel.hidden ? this.openPanel() : this.closePanel();
    }

    openPanel() {
        this.panel.hidden = false;
        this.trigger.setAttribute('aria-expanded', 'true');

        // The panel hangs off the right edge of the button. If the button sits
        // near the left edge of the window, flip it around.
        this.panel.classList.remove(`${NS}__panel--flipped`);

        if (this.panel.getBoundingClientRect().left < 8) {
            this.panel.classList.add(`${NS}__panel--flipped`);
        }
    }

    closePanel() {
        if (!this.panel || this.panel.hidden) {
            return;
        }

        this.panel.hidden = true;
        this.trigger.setAttribute('aria-expanded', 'false');
        this.notify('');

        // Columns that were not rendered can only reappear after a reload. We
        // defer it until the panel is closed, so several columns can be
        // re-enabled in one go.
        if (this.needsReload()) {
            Promise.resolve(this.pending).then(() => this.reload());
        }
    }

    needsReload() {
        return this.renderedHidden.some((name) => !this.hidden.has(name));
    }

    hideColumn(name) {
        if (this.hidden.has(name)) {
            return;
        }

        if (this.columns.length - this.hidden.size <= 1) {
            this.openPanel();
            this.notify(this.trans('lastColumn'), true);
            this.syncCheckboxes();

            return;
        }

        this.hidden.add(name);
        this.applyState();
        this.persist();
    }

    showColumn(name) {
        if (!this.hidden.has(name)) {
            return;
        }

        this.hidden.delete(name);
        this.applyState();
        this.persist();

        if (this.needsReload()) {
            this.notify(this.trans('pendingReload'));
        }
    }

    showAllColumns() {
        if (!this.hidden.size) {
            return;
        }

        this.hidden.clear();
        this.applyState();
        this.persist();
        this.closePanel();
    }

    toggleCells(name, hide) {
        const selector = `.${CSS.escape(`col_${name}`)}`;

        this.table.querySelectorAll(selector).forEach((cell) => {
            cell.classList.toggle(HIDDEN_CLASS, hide);
        });
    }

    syncCheckboxes() {
        this.checkboxes?.forEach((checkbox, name) => {
            checkbox.checked = !this.hidden.has(name);
        });
    }

    /**
     * Bring the DOM in sync with the current state.
     *
     * Writing the state back into the value attribute is essential: Turbo caches
     * a snapshot of the page when you navigate away. Without this, a snapshot
     * taken after a column was hidden would still carry the value of the last
     * server response, and the controller would "restore" the outdated state
     * when Turbo replays that snapshot as a preview.
     */
    applyState() {
        this.hiddenValue = Array.from(this.hidden);
        this.columns.forEach(({name}) => this.toggleCells(name, this.hidden.has(name)));
        this.syncCheckboxes();
    }

    /**
     * Persist the current state. Requests are chained so that responses cannot
     * arrive out of order, and a response is only applied if no newer change
     * has happened in the meantime.
     *
     * @returns {Promise}
     */
    persist() {
        const revision = ++this.revision;

        const body = new URLSearchParams();
        body.append('REQUEST_TOKEN', this.tokenValue);
        body.append('table', this.tableValue);
        this.hidden.forEach((name) => body.append('hidden[]', name));

        this.pending = Promise.resolve(this.pending)
            .catch(() => {})
            .then(() => fetch(this.urlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {Accept: 'application/json'},
                body,
            }))
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Unexpected response status ${response.status}`);
                }

                return response.json();
            })
            .then((data) => {
                if (revision !== this.revision) {
                    return;
                }

                this.hidden = new Set(Array.isArray(data.hidden) ? data.hidden : []);
                this.applyState();
            })
            .catch((error) => {
                console.error(`[${NS}]`, error);
                this.openPanel();
                this.notify(this.trans('error'), true);
            });

        return this.pending;
    }

    reload() {
        if (window.Turbo && 'function' === typeof window.Turbo.visit) {
            window.Turbo.visit(window.location.href, {action: 'replace'});
        } else {
            window.location.reload();
        }
    }

    notify(text, isError = false) {
        if (!this.message) {
            return;
        }

        this.message.textContent = text;
        this.message.hidden = '' === text;
        this.message.classList.toggle(`${NS}__message--error`, isError);
    }

    trans(key) {
        return (this.i18nValue && this.i18nValue[key]) || key;
    }

    /**
     * @param {boolean} restoreColumns Also make the client side hidden columns visible again
     */
    teardown(restoreColumns = true) {
        this.wrapper?.remove();
        this.wrapper = null;
        this.trigger = null;
        this.panel = null;
        this.message = null;
        this.checkboxes = null;

        this.headerCells().forEach(({th}) => {
            th.classList.remove(`${NS}__header`);
            th.removeAttribute('role');
            th.removeAttribute('tabindex');
            th.removeAttribute('title');

            if (restoreColumns) {
                this.unwrapLabel(th);
            }
        });

        if (restoreColumns) {
            this.table?.querySelectorAll(`.${HIDDEN_CLASS}`).forEach((cell) => cell.classList.remove(HIDDEN_CLASS));
        }
    }
}

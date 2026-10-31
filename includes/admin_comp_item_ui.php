<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

require_once __DIR__ . '/admin_ticket_compensation.php';

function sahtout_admin_comp_item_styles(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
    <style>
        .ticket-item-grid {
            display: grid;
            grid-template-columns: 1fr 5rem 2.5rem;
            gap: .35rem .75rem;
            align-items: center;
        }
        .ticket-item-grid .item-grid-head {
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: rgba(156, 163, 175, 0.9);
        }
        .ticket-item-grid .item-grid-head.pick-col,
        .ticket-item-grid .comp-item-pick-btn { justify-self: center; }
        .input-no-spinners::-webkit-outer-spin-button,
        .input-no-spinners::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .input-no-spinners { -moz-appearance: textfield; appearance: textfield; }
        .comp-item-pick-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            padding: 0;
            font-size: .75rem;
            border: 1px solid rgba(201,162,39,.25);
            background: rgba(0,0,0,.35);
            color: #f2cf5b;
            cursor: pointer;
        }
        .comp-item-pick-btn:hover { background: rgba(242,207,82,.08); }
        .comp-item-pick-btn:disabled { opacity: .45; cursor: not-allowed; }
        .modal-backdrop.comp-item-lookup-modal {
            position: fixed;
            inset: 0;
            z-index: 100001;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-backdrop.comp-item-lookup-modal.active { display: flex; }
        .modal-backdrop.comp-item-lookup-modal .panel { max-width: 52rem; max-height: 90vh; overflow-y: auto; width: 100%; }
        .comp-item-lookup-results { max-height: 22rem; overflow: auto; }
        .comp-item-lookup-results .table-dark td { vertical-align: top; }
        .comp-item-lookup-results .item-desc { color: #9ca3af; font-size: .8rem; max-width: 20rem; }
        .btn-comp-add {
            padding: .35rem .65rem;
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid rgba(201,162,39,.35);
            background: rgba(242,207,82,.12);
            color: #f2cf5b;
            cursor: pointer;
        }
        .btn-comp-add:hover { background: rgba(242,207,82,.2); }
    </style>
    <?php
}

function sahtout_admin_comp_item_attachment_grid(bool $enabled): void
{
    ?>
    <div class="flex flex-wrap gap-2 items-end max-w-lg mb-3 comp-item-lookup-bar">
        <div class="flex-1 min-w-[10rem]">
            <label class="block text-[10px] uppercase tracking-wider text-gray-500 mb-1"><?php echo translate('admin_tickets_comp_item_lookup_label', 'Find item'); ?></label>
            <input type="text" class="input-dark comp-item-lookup-input" maxlength="80" placeholder="<?php echo translate('admin_tickets_comp_item_lookup_placeholder', 'Item name or entry ID…'); ?>" autocomplete="off" <?php echo $enabled ? '' : 'disabled'; ?>>
        </div>
        <button type="button" class="btn-iron comp-item-search-btn" <?php echo $enabled ? '' : 'disabled'; ?>>
            <i class="fas fa-search"></i> <?php echo translate('admin_tickets_comp_item_search_btn', 'Search'); ?>
        </button>
    </div>
    <div class="ticket-item-grid max-w-lg">
        <span class="item-grid-head"><?php echo translate('admin_tickets_comp_item_entry', 'Item ID'); ?></span>
        <span class="item-grid-head"><?php echo translate('admin_tickets_comp_item_qty', 'Qty'); ?></span>
        <span class="item-grid-head pick-col" aria-hidden="true"></span>
        <?php for ($itemRow = 0; $itemRow < SAHTOUT_TICKET_COMP_MAX_ITEMS; $itemRow++): ?>
            <input type="text" name="comp_item_entry[]" class="input-dark input-no-spinners comp-item-entry-input" inputmode="numeric" pattern="[0-9]*" placeholder="—" <?php echo $enabled ? '' : 'disabled'; ?>>
            <input type="number" name="comp_item_qty[]" class="input-dark comp-item-qty-input" min="0" max="200" placeholder="—" <?php echo $enabled ? '' : 'disabled'; ?>>
            <button type="button" class="comp-item-pick-btn" title="<?php echo translate('admin_tickets_comp_item_pick_btn', 'Find'); ?>" data-row="<?php echo $itemRow; ?>" <?php echo $enabled ? '' : 'disabled'; ?>><i class="fas fa-search"></i></button>
        <?php endfor; ?>
    </div>
    <?php
}

function sahtout_admin_comp_item_lookup_modal(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
    <div id="compItemLookupModal" class="modal-backdrop comp-item-lookup-modal" aria-hidden="true">
        <div class="panel p-6 md:p-8 relative">
            <button type="button" class="absolute top-4 right-4 text-gray-400 hover:text-white text-2xl close-comp-item-lookup" aria-label="Close">&times;</button>
            <h4 class="wow-title text-lg md:text-xl mb-1"><?php echo translate('admin_tickets_comp_item_lookup_title', 'Item lookup'); ?></h4>
            <p class="text-gray-500 text-xs mb-4 comp-item-lookup-query-label"></p>
            <div class="comp-item-lookup-status text-sm text-gray-400 mb-3"></div>
            <div class="comp-item-lookup-results">
                <table class="table-dark">
                    <thead>
                        <tr>
                            <th><?php echo translate('admin_tickets_comp_item_lookup_col_id', 'ID'); ?></th>
                            <th><?php echo translate('admin_tickets_comp_item_lookup_col_name', 'Name'); ?></th>
                            <th><?php echo translate('admin_tickets_comp_item_lookup_col_desc', 'Description'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="comp-item-lookup-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

function sahtout_admin_comp_item_lookup_script(string $basePath): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
    <script>
    (function () {
        var endpoint = <?php echo json_encode($basePath . 'admin/item_search'); ?>;
        var i18n = <?php echo json_encode([
            'loading' => translate('admin_tickets_comp_item_lookup_loading', 'Searching item database…'),
            'none' => translate('admin_tickets_comp_item_lookup_none', 'No items matched your search.'),
            'error' => translate('admin_tickets_comp_item_lookup_error', 'Could not search items. Try again or enter an ID manually.'),
            'queryShort' => translate('admin_tickets_comp_item_lookup_query_short', 'Enter at least 2 characters, or a numeric item ID.'),
            'add' => translate('admin_tickets_comp_item_lookup_add', 'Add'),
        ], JSON_UNESCAPED_UNICODE); ?>;
        var modal = document.getElementById('compItemLookupModal');
        if (!modal) return;
        var statusEl = modal.querySelector('.comp-item-lookup-status');
        var queryLabelEl = modal.querySelector('.comp-item-lookup-query-label');
        var tbody = modal.querySelector('.comp-item-lookup-tbody');
        var state = { form: null, rowIndex: 0, query: '' };

        function compMailForm(fromEl) {
            if (!fromEl) return null;
            return fromEl.closest('form') || null;
        }

        function entryInputs(form) {
            return form ? form.querySelectorAll('.comp-item-entry-input') : [];
        }

        function resolveRowIndex(form, preferred) {
            var inputs = entryInputs(form);
            if (!inputs.length) return 0;
            if (typeof preferred === 'number' && preferred >= 0 && preferred < inputs.length) {
                return preferred;
            }
            for (var i = 0; i < inputs.length; i++) {
                if (!(inputs[i].value || '').trim()) return i;
            }
            return 0;
        }

        function openLookup(form, rowIndex, query) {
            if (!form) return;
            state.form = form;
            state.rowIndex = resolveRowIndex(form, rowIndex);
            state.query = (query || '').trim();
            if (queryLabelEl) {
                queryLabelEl.textContent = state.query ? ('“' + state.query + '”') : '';
            }
            modal.classList.add('active');
            runSearch();
        }

        function closeLookup() {
            modal.classList.remove('active');
        }

        window.sahtoutCloseCompItemLookup = closeLookup;

        function setStatus(text) {
            if (statusEl) statusEl.textContent = text || '';
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function renderRows(items) {
            if (!tbody) return;
            tbody.innerHTML = '';
            items.forEach(function (item) {
                var tr = document.createElement('tr');
                var desc = item.description || '—';
                tr.innerHTML =
                    '<td>' + String(item.entry) + '</td>' +
                    '<td>' + escapeHtml(item.name || '') + '</td>' +
                    '<td class="item-desc">' + escapeHtml(desc) + '</td>' +
                    '<td><button type="button" class="btn-comp-add" data-entry="' + String(item.entry) + '">' + escapeHtml(i18n.add) + '</button></td>';
                tbody.appendChild(tr);
            });
        }

        function runSearch() {
            if (!tbody) return;
            tbody.innerHTML = '';
            var q = state.query;
            if (!q) {
                setStatus(i18n.queryShort);
                return;
            }
            if (q.length < 2 && !/^\d+$/.test(q)) {
                setStatus(i18n.queryShort);
                return;
            }
            setStatus(i18n.loading);
            fetch(endpoint + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
                .then(function (result) {
                    if (!result.data || !result.data.ok) {
                        setStatus(i18n.error);
                        return;
                    }
                    if (!result.data.items || !result.data.items.length) {
                        setStatus(i18n.none);
                        return;
                    }
                    setStatus('');
                    renderRows(result.data.items);
                })
                .catch(function () {
                    setStatus(i18n.error);
                });
        }

        function applyEntry(entryId) {
            if (!state.form) return;
            var inputs = entryInputs(state.form);
            var qtyInputs = state.form.querySelectorAll('.comp-item-qty-input');
            var row = state.rowIndex;
            if (inputs[row]) {
                inputs[row].value = String(entryId);
                inputs[row].focus();
            }
            if (qtyInputs[row] && !(qtyInputs[row].value || '').trim()) {
                qtyInputs[row].value = '1';
            }
            closeLookup();
        }

        document.addEventListener('click', function (e) {
            var searchBtn = e.target.closest('.comp-item-search-btn');
            if (searchBtn) {
                e.preventDefault();
                openLookup(compMailForm(searchBtn), null, getLookupQuery(searchBtn));
                return;
            }
            var pickBtn = e.target.closest('.comp-item-pick-btn');
            if (pickBtn) {
                e.preventDefault();
                var pickForm = compMailForm(pickBtn);
                var row = parseInt(pickBtn.getAttribute('data-row') || '0', 10);
                openLookup(pickForm, row, getLookupQueryFromForm(pickForm));
                return;
            }
            if (e.target.closest('.close-comp-item-lookup')) {
                e.preventDefault();
                closeLookup();
                return;
            }
            var addBtn = e.target.closest('.btn-comp-add');
            if (addBtn) {
                e.preventDefault();
                applyEntry(parseInt(addBtn.getAttribute('data-entry') || '0', 10));
                return;
            }
            if (e.target === modal) {
                closeLookup();
            }
        });

        function getLookupQueryFromForm(form) {
            if (!form) return '';
            var bar = form.querySelector('.comp-item-lookup-bar');
            var input = bar ? bar.querySelector('.comp-item-lookup-input') : null;
            return input ? input.value : '';
        }

        function getLookupQuery(fromEl) {
            var bar = fromEl.closest('.comp-item-lookup-bar');
            var input = bar ? bar.querySelector('.comp-item-lookup-input') : null;
            if (input) return input.value;
            return getLookupQueryFromForm(compMailForm(fromEl));
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var input = e.target.closest('.comp-item-lookup-input');
            if (!input) return;
            e.preventDefault();
            openLookup(compMailForm(input), null, input.value);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (!modal.classList.contains('active')) return;
            e.stopPropagation();
            closeLookup();
        });
    })();
    </script>
    <?php
}

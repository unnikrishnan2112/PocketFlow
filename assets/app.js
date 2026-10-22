/* PocketFlow - mobile-first PHP/MySQL money manager */
(function ($) {
  'use strict';

  const csrf = $('meta[name="csrf-token"]').attr('content');
  const COLORS = ['#6257ED', '#0C9F88', '#F2A23A', '#EF6A86', '#4689E8', '#9B6BDD'];
  const ICONS = ['wallet2', 'briefcase', 'laptop', 'shop', 'gift', 'piggy-bank', 'bank', 'cash-stack', 'stars', 'three-dots'];
  const CATEGORY_COLORS = ['#6257ed', '#0c9f88', '#f2a23a', '#ef6a86', '#4689e8', '#a377dc', '#78899c'];
  const expenseCategories = ['Food & Drinks', 'Groceries', 'Transport', 'Shopping', 'Bills', 'Rent', 'Health', 'Entertainment', 'Education', 'Family', 'Savings', 'Other'];
  const incomeCategories = ['Salary', 'Freelance', 'Business', 'Bonus', 'Gift', 'Investment', 'Refund', 'Loan', 'Other'];
  const state = { sources: [], totals: {}, recent: [], categories: [], monthly: [], currentView: 'home', currentKind: 'all', page: 1, hasMore: false, busy: false, hideBalance: false, chart: null, lastTrigger: null };
  const fmt = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 0, maximumFractionDigits: 2 });
  const fmtFull = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const today = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
  const money = n => fmt.format(Number(n) || 0);
  const fullMoney = n => fmtFull.format(Number(n) || 0);
  const safe = s => String(s ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  const sourceById = id => state.sources.find(s => String(s.id) === String(id));
  let queryTimer;
  let toastTimer;

  function notify(message, isError = false) {
    const $toast = $('#toast');
    $toast.text(message).toggleClass('error', isError).addClass('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => $toast.removeClass('show'), 3400);
  }
  function apiPost(action, payload = {}) {
    return $.ajax({ url: 'api.php', method: 'POST', dataType: 'json', data: { action, ...payload }, headers: { 'X-CSRF-Token': csrf } });
  }
  function errorText(xhr) {
    if (xhr?.status === 401) { window.location.href = 'login.php'; return 'Please sign in again.'; }
    return xhr?.responseJSON?.message || 'Unable to connect. Check that Apache and MySQL are running.';
  }
  function empty(heading, desc, action = '') {
    return `<div class="empty-state"><div class="empty-glyph"><i class="bi bi-wallet2"></i></div><strong>${safe(heading)}</strong><p>${safe(desc)}</p>${action ? `<button type="button" data-sheet="${action}">+ Get started</button>` : ''}</div>`;
  }
  function refresh() {
    return $.getJSON('api.php', { action: 'state' }).done(data => {
      if (!data.ok) { notify(data.message || 'Could not load your data', true); return; }
      Object.assign(state, { sources: data.sources, totals: data.totals, recent: data.recent, monthly: data.monthly, categories: data.categories });
      renderAll();
      if (state.currentView === 'activity') loadTransactions(false);
    }).fail(xhr => notify(errorText(xhr), true));
  }
  function iconHtml(icon, extra = '') { return `<i class="bi bi-${safe(ICONS.includes(icon) ? icon : 'wallet2')} ${extra}"></i>`; }
  function prettyDate(date) {
    const d = new Date(`${date}T12:00:00`);
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
  function renderAll() {
    $('#home-income').text(money(state.totals.month_income));
    $('#home-expense').text(money(state.totals.month_expense));
    $('#balance-amount').text(state.hideBalance ? '••••••••' : fullMoney(state.totals.balance));
    $('#toggle-balance i').attr('class', state.hideBalance ? 'bi bi-eye-slash' : 'bi bi-eye');
    $('#sources-balance').text(money(state.totals.balance));
    $('#insight-income').text(money(state.totals.all_income));
    $('#insight-expense').text(money(state.totals.all_expense));
    renderSources();
    renderRecent();
    renderCategories();
    renderDistribution();
    renderSourceFilter();
    if (state.currentView === 'insights') renderChart();
  }
  function renderSources() {
    const cards = state.sources.map(s => `
      <button type="button" class="source-card" style="--source-color:${safe(s.color)}" data-open-source="${s.id}">
        <div class="source-card-top"><span class="source-icon">${iconHtml(s.icon)}</span><i class="bi bi-arrow-up-right source-arrow"></i></div>
        <span class="source-name">${safe(s.name)}</span>
        <strong class="source-balance">${money(s.balance)}</strong>
        <div class="source-micro"><span><i class="bi bi-arrow-down-left"></i> ${money(s.income_total)}</span><span><i class="bi bi-arrow-up-right"></i> ${money(s.expense_total)}</span></div>
      </button>`).join('');
    $('#home-sources').html(cards + '<button type="button" class="source-card add-source-card" data-sheet="source"><i class="bi bi-plus-circle"></i><strong>New income source</strong></button>');

    const full = state.sources.map(s => {
      const income = Number(s.income_total) || 0;
      const expense = Number(s.expense_total) || 0;
      const spentPct = income > 0 ? Math.min(100, (expense / income) * 100) : 0;
      return `<article class="full-source" data-full-source="${s.id}" style="--source-color:${safe(s.color)}">
        <div class="full-source-top"><span class="source-icon">${iconHtml(s.icon)}</span><div class="full-source-details"><h3>${safe(s.name)}</h3><small>Available to spend</small></div><i class="bi bi-three-dots" style="color:#b5bbca"></i></div>
        <strong class="source-big-amount">${money(s.balance)}</strong>
        <div class="source-progress" role="progressbar" aria-valuenow="${Math.round(spentPct)}" aria-valuemin="0" aria-valuemax="100"><span style="width:${spentPct}%"></span></div>
        <div class="full-source-footer"><span>Received <b>${money(s.income_total)}</b></span><span>Spent <b>${money(s.expense_total)}</b></span></div>
        <div class="full-source-options"><button type="button" data-sheet="income" data-source="${s.id}"><i class="bi bi-plus-circle"></i>Income</button><button type="button" data-sheet="expense" data-source="${s.id}"><i class="bi bi-dash-circle"></i>Expense</button><button type="button" data-source-history="${s.id}"><i class="bi bi-clock-history"></i>History</button>${Number(s.balance) === 0 && Number(s.transaction_count) === 0 ? `<button class="source-remove" data-remove-source="${s.id}" title="Remove empty source"><i class="bi bi-trash"></i></button>` : ''}</div>
      </article>`;
    }).join('');
    $('#source-full-list').html(full || empty('No income sources yet', 'Create Salary, Freelance, Business or any other pocket.', 'source'));
  }
  function txRow(t, withDelete = false) {
    const transfer = t.kind.startsWith('transfer');
    const plus = t.kind === 'income' || t.kind === 'transfer_in';
    const cls = transfer ? 'transfer' : plus ? 'income' : 'expense';
    const icon = transfer ? 'arrow-left-right' : plus ? 'arrow-down-left' : 'arrow-up-right';
    const sign = plus ? '+' : '−';
    return `<div class="transaction-row">
      <span class="tx-icon ${cls}"><i class="bi bi-${icon}"></i></span>
      <div class="tx-main"><strong title="${safe(t.title)}">${safe(t.title)}</strong><small>${safe(t.source_name)} · ${safe(t.category)}</small></div>
      <div class="tx-money"><strong class="${plus ? 'plus' : 'minus'}">${sign}${money(t.amount)}</strong><small>${prettyDate(t.occurred_on)}</small></div>
      ${withDelete ? `<button type="button" class="delete-tx" data-delete-tx="${t.id}" title="Delete and reverse transaction" aria-label="Delete ${safe(t.title)}"><i class="bi bi-trash3"></i></button>` : ''}
    </div>`;
  }
  function renderRecent() {
    $('#home-recent').html(state.recent.length ? state.recent.slice(0, 5).map(t => txRow(t)).join('') : empty('No activity yet', 'Start by adding income to one of your sources.', state.sources.length ? 'income' : 'source'));
  }
  function donutMarkup(data, larger = false) {
    const sum = data.reduce((n, t) => n + Number(t.amount), 0);
    if (!sum) return empty('Nothing spent this month', 'Expenses will appear here as you record them.');
    let progress = 0;
    const segments = data.map((t, i) => {
      const start = progress;
      progress += Number(t.amount) / sum * 100;
      return `${CATEGORY_COLORS[i % CATEGORY_COLORS.length]} ${start}% ${progress}%`;
    }).join(', ');
    const chart = `<div class="donut" style="background:conic-gradient(${segments})"><div class="donut-center"><strong>${money(sum)}</strong><small>Total spent</small></div></div>`;
    const legend = `<div class="legend-list">${data.slice(0, larger ? 7 : 4).map((t, i) => `<div class="legend-row"><span><b style="background:${CATEGORY_COLORS[i % CATEGORY_COLORS.length]}"></b>${safe(t.category)}</span><strong>${money(t.amount)}</strong></div>`).join('')}</div>`;
    return chart + legend;
  }
  function renderCategories() {
    $('#home-categories').html(donutMarkup(state.categories));
    $('#insights-categories').html(donutMarkup(state.categories, true));
  }
  function renderDistribution() {
    const total = state.sources.reduce((n, s) => n + Number(s.balance), 0);
    if (!state.sources.length) { $('#insights-distribution').html(empty('No income sources', 'Create a source to see your balance distribution.', 'source')); return; }
    const slices = state.sources.map((s, i) => `<span style="width:${total ? Number(s.balance) / total * 100 : 0}%;background:${safe(s.color)}"></span>`).join('');
    const items = state.sources.map(s => `<div class="distribution-entry"><span><b style="background:${safe(s.color)}"></b>${safe(s.name)}</span><strong>${money(s.balance)}</strong></div>`).join('');
    $('#insights-distribution').html(`<div class="distribution-track">${slices}</div>${items}`);
  }
  function renderSourceFilter() {
    const $select = $('#filter-source');
    const selected = $select.val();
    $select.html('<option value="">All sources</option>' + state.sources.map(s => `<option value="${s.id}">${safe(s.name)}</option>`).join(''));
    if (state.sources.some(s => String(s.id) === selected)) $select.val(selected);
  }
  function renderChart() {
    const canvas = document.getElementById('flow-chart');
    if (state.chart) { state.chart.destroy(); state.chart = null; }
    if (!window.Chart) return;
    const currentMonth = new Date();
    const months = Array.from({ length: 6 }, (_, i) => {
      const d = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 5 + i, 1);
      return { key: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`, label: d.toLocaleDateString('en-IN', { month: 'short' }) };
    });
    const lookup = Object.fromEntries(state.monthly.map(x => [x.month, x]));
    state.chart = new Chart(canvas, { type: 'bar', data: { labels: months.map(m => m.label), datasets: [
      { label: 'Income', data: months.map(m => Number(lookup[m.key]?.income || 0)), backgroundColor: '#17b998', borderRadius: 5, maxBarThickness: 18 },
      { label: 'Expenses', data: months.map(m => Number(lookup[m.key]?.expense || 0)), backgroundColor: '#887bf6', borderRadius: 5, maxBarThickness: 18 }
    ] }, options: { responsive: true, maintainAspectRatio: false, animation: { duration: 350 }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => `${context.dataset.label}: ${money(context.parsed.y)}` } } }, scales: {
      x: { grid: { display: false }, border: { display: false }, ticks: { color: '#929aaf', font: { size: 10, family: 'DM Sans' } } },
      y: { beginAtZero: true, border: { display: false }, grid: { color: '#f1f3f8' }, ticks: { color: '#a2a9b7', font: { size: 9 }, callback: value => value >= 1000 ? `${(value / 1000).toFixed(value % 1000 === 0 ? 0 : 1)}k` : value } }
    } } });
  }
  function navigate(view) {
    if (!['home', 'sources', 'activity', 'insights'].includes(view)) return;
    state.currentView = view;
    $('.view').removeClass('active');
    $(`#view-${view}`).addClass('active');
    $('.nav-item-app').toggleClass('active', false).filter(`[data-view="${view}"]`).addClass('active');
    window.scrollTo({ top: 0, behavior: 'instant' });
    if (view === 'activity') loadTransactions(false);
    if (view === 'insights') renderChart();
  }
  function loadTransactions(append = false) {
    const page = append ? state.page + 1 : 1;
    const params = { action: 'transactions', page, source_id: $('#filter-source').val(), kind: state.currentKind, month: $('#filter-month').val(), q: $('#filter-search').val().trim() };
    $('#load-more').prop('disabled', true);
    if (!append) $('#activity-list').html('<div class="loading-state"><i class="bi bi-arrow-repeat"></i> Loading transactions...</div>');
    $.getJSON('api.php', params).done(data => {
      state.page = data.page; state.hasMore = data.has_more;
      const markup = data.items.map(t => txRow(t, true)).join('');
      if (append) $('#activity-list').append(markup);
      else $('#activity-list').html(markup || empty('No matching transactions', 'Try changing your search or filters.'));
      $('#activity-count').text(`${Math.min(data.total, data.page * 18)} of ${data.total} transactions`);
      $('#load-more').prop('hidden', !data.has_more);
    }).fail(xhr => { if (!append) $('#activity-list').html(empty('Unable to load transactions', errorText(xhr))); notify(errorText(xhr), true); })
      .always(() => $('#load-more').prop('disabled', false));
  }
  function optionsHtml(selected = '') {
    return state.sources.map(s => `<option value="${s.id}" ${String(s.id) === String(selected) ? 'selected' : ''}>${safe(s.name)} (${money(s.balance)})</option>`).join('');
  }
  function categoryHtml(kind) {
    return (kind === 'income' ? incomeCategories : expenseCategories).map(x => `<option value="${safe(x)}">${safe(x)}</option>`).join('');
  }
  function transactionForm(kind, selected = '') {
    const income = kind === 'income';
    return `<form id="sheet-form" data-action="${kind}">
      <div class="sheet-field"><label for="amount-input">Amount (₹)</label><input id="amount-input" inputmode="decimal" type="number" step="0.01" min="0.01" max="99999999999" name="amount" placeholder="0.00" required autofocus></div>
      <div class="sheet-field"><label for="source-input">${income ? 'Add money to' : 'Pay from this income source'}</label><select name="source_id" id="source-input" required>${optionsHtml(selected)}</select><div class="source-balance-note" id="selected-balance"></div></div>
      <div class="sheet-field"><label for="title-input">What is it for?</label><input id="title-input" name="title" maxlength="120" placeholder="${income ? 'e.g. October salary' : 'e.g. Grocery shopping'}" required></div>
      <div class="sheet-two"><div class="sheet-field"><label for="category-input">Category</label><select id="category-input" name="category" required>${categoryHtml(kind)}</select></div><div class="sheet-field"><label for="entry-date">Date</label><input id="entry-date" type="date" name="date" value="${today()}" required></div></div>
      <div class="sheet-field"><label for="entry-note">Note <span style="color:#a8afbe;font-weight:500">(optional)</span></label><textarea name="note" id="entry-note" maxlength="500" rows="2" placeholder="Anything you want to remember..."></textarea></div>
      <button class="sheet-submit" type="submit">${income ? 'Save income' : 'Save expense'} <i class="bi bi-arrow-right"></i></button>
      ${income ? '' : '<p class="sheet-help">Your expense will be deducted only from the selected source.</p>'}
    </form>`;
  }
  function sourceForm() {
    return `<form id="sheet-form" data-action="source">
      <div class="sheet-field"><label for="source-name">Source name</label><input id="source-name" name="name" maxlength="80" placeholder="e.g. Salary, Freelance, Business" required autofocus></div>
      <div class="sheet-field"><label for="opening-balance">Opening balance (₹)</label><input id="opening-balance" name="opening" inputmode="decimal" type="number" min="0" step="0.01" value="0" required></div>
      <div class="sheet-field"><label>Choose an icon</label><div class="icon-choices">${ICONS.map((x, i) => `<label class="icon-choice"><input type="radio" name="icon" value="${x}" ${i === 0 ? 'checked' : ''}><span>${iconHtml(x)}</span></label>`).join('')}</div></div>
      <div class="sheet-field"><label>Pick a color</label><div class="color-choices">${COLORS.map((x, i) => `<label class="color-choice" style="--choice-color:${x}"><input type="radio" name="color" value="${x}" ${i === 0 ? 'checked' : ''}><span></span></label>`).join('')}</div></div>
      <button class="sheet-submit" type="submit">Create income source <i class="bi bi-arrow-right"></i></button>
      <p class="sheet-help">You can add more income or record expenses after creating this source.</p>
    </form>`;
  }
  function transferForm() {
    return `<form id="sheet-form" data-action="transfer">
      <div class="sheet-field"><label for="amount-input">Amount to transfer (₹)</label><input id="amount-input" name="amount" inputmode="decimal" type="number" step="0.01" min="0.01" placeholder="0.00" required autofocus></div>
      <div class="sheet-field"><label for="transfer-from">From income source</label><select id="transfer-from" name="from_id" required>${optionsHtml()}</select><div class="source-balance-note" id="selected-balance"></div></div>
      <div class="sheet-field"><label for="transfer-to">To income source</label><select id="transfer-to" name="to_id" required>${optionsHtml(state.sources[1]?.id)}</select></div>
      <div class="sheet-two"><div class="sheet-field"><label for="transfer-date">Date</label><input id="transfer-date" type="date" name="date" value="${today()}" required></div><div class="sheet-field"><label for="transfer-note">Note (optional)</label><input id="transfer-note" name="note" maxlength="500" placeholder="e.g. Cash moved"></div></div>
      <button class="sheet-submit" type="submit">Transfer money <i class="bi bi-arrow-left-right"></i></button>
      <p class="sheet-help">Transfers move money between sources without counting as new income or spending.</p>
    </form>`;
  }
  function actionMenu() {
    const items = [
      ['income', 'arrow-down-left', 'Add income', 'Add money to a selected source', 'quick-income'],
      ['expense', 'arrow-up-right', 'Add expense', 'Deduct from one income source', 'quick-expense'],
      ['transfer', 'arrow-left-right', 'Transfer money', 'Move between your sources', 'quick-transfer'],
      ['source', 'plus-lg', 'New income source', 'Create another separate pocket', 'quick-transfer']
    ];
    return `<div class="action-options">${items.map(i => `<button class="action-option" type="button" data-sheet="${i[0]}"><span class="quick-icon ${i[4]}"><i class="bi bi-${i[1]}"></i></span><span><strong>${i[2]}</strong><small>${i[3]}</small></span><i class="bi bi-chevron-right"></i></button>`).join('')}</div>`;
  }
  function updateBalanceHint() {
    const $select = $('#sheet-form [name="source_id"],#sheet-form [name="from_id"]');
    const source = sourceById($select.val());
    $('#selected-balance').html(source ? `Available: <strong>${money(source.balance)}</strong>` : '');
  }
  function openSheet(which, selectedSource = '') {
    if ((which === 'income' || which === 'expense') && !state.sources.length) {
      notify('First, create an income source such as Salary.');
      which = 'source';
    }
    if (which === 'transfer' && state.sources.length < 2) {
      notify('Create at least two income sources to transfer money.');
      which = 'source';
    }
    const meta = {
      action: ['WHAT WOULD YOU LIKE TO DO?', 'Add something new'],
      source: ['CREATE A NEW POCKET', 'New income source'],
      income: ['MONEY COMING IN', 'Add income'],
      expense: ['MONEY GOING OUT', 'Add expense'],
      transfer: ['BETWEEN YOUR POCKETS', 'Transfer money']
    };
    if (!meta[which]) return;
    state.lastTrigger = document.activeElement;
    $('#sheet-subtitle').text(meta[which][0]);
    $('#sheet-title').text(meta[which][1]);
    $('#sheet-content').html(which === 'source' ? sourceForm() : which === 'transfer' ? transferForm() : which === 'action' ? actionMenu() : transactionForm(which, selectedSource));
    $('#sheet-overlay').addClass('open').attr('aria-hidden', 'false');
    $('body').css('overflow', 'hidden');
    updateBalanceHint();
    setTimeout(() => $('#sheet-form [autofocus],.action-option').first().trigger('focus'), 90);
  }
  function closeSheet() {
    $('#sheet-overlay').removeClass('open').attr('aria-hidden', 'true');
    $('body').css('overflow', '');
    if (state.lastTrigger && state.lastTrigger.isConnected) state.lastTrigger.focus();
  }
  function submitForm(e) {
    e.preventDefault();
    const $form = $(e.currentTarget);
    if (state.busy) return;
    const values = Object.fromEntries(new FormData($form[0]).entries());
    const action = $form.data('action');
    if (action === 'expense' || action === 'transfer') {
      const from = sourceById(action === 'expense' ? values.source_id : values.from_id);
      if (from && Number(values.amount) > Number(from.balance)) {
        notify(`Only ${money(from.balance)} is available in ${from.name}.`, true);
        return;
      }
    }
    if (action === 'transfer' && values.from_id === values.to_id) { notify('Choose different sources for a transfer.', true); return; }
    const $button = $form.find('[type="submit"]');
    state.busy = true;
    $button.prop('disabled', true).text('Saving...');
    apiPost(action, values).done(response => {
      closeSheet();
      notify(response.message || 'Saved successfully!');
      refresh();
    }).fail(xhr => notify(errorText(xhr), true))
      .always(() => { state.busy = false; $button.prop('disabled', false); });
  }
  function deleteTx(id) {
    if (!window.confirm('Delete this transaction and reverse its effect on the source balance? Transfers will delete both linked entries.')) return;
    apiPost('delete', { id }).done(response => { notify(response.message); refresh(); }).fail(xhr => notify(errorText(xhr), true));
  }

  // Navigation and actions
  $(document).on('click', '[data-view]', function () { navigate($(this).data('view')); });
  $(document).on('click', '[data-sheet]', function () { openSheet($(this).data('sheet'), $(this).attr('data-source') || ''); });
  $(document).on('click', '[data-open-source]', function () { const id = $(this).attr('data-open-source'); navigate('sources'); document.querySelector(`[data-full-source="${id}"]`)?.scrollIntoView({ block: 'center' }); });
  $(document).on('click', '[data-source-history]', function () { navigate('activity'); $('#filter-source').val($(this).attr('data-source-history')); loadTransactions(false); });
  $(document).on('click', '[data-close-sheet]', closeSheet);
  $(document).on('keydown', function (e) {
    if (!$('#sheet-overlay').hasClass('open')) return;
    if (e.key === 'Escape') closeSheet();
    if (e.key === 'Tab') {
      const nodes = $('#sheet-overlay .action-sheet').find('button:not(:disabled),input:not([type=hidden]),select,textarea').filter(':visible').toArray();
      if (!nodes.length) return;
      if (e.shiftKey && document.activeElement === nodes[0]) { nodes[nodes.length - 1].focus(); e.preventDefault(); }
      else if (!e.shiftKey && document.activeElement === nodes[nodes.length - 1]) { nodes[0].focus(); e.preventDefault(); }
    }
  });
  $(document).on('submit', '#sheet-form', submitForm);
  $(document).on('change', '#sheet-form [name="source_id"],#sheet-form [name="from_id"]', updateBalanceHint);
  $(document).on('click', '[data-delete-tx]', function () { deleteTx($(this).data('delete-tx')); });
  $(document).on('click', '[data-remove-source]', function () {
    if (!window.confirm('Remove this empty income source?')) return;
    apiPost('delete_source', { id: $(this).data('remove-source') }).done(data => { notify(data.message); refresh(); }).fail(xhr => notify(errorText(xhr), true));
  });
  $('#filter-kinds').on('click', 'button', function () { $('#filter-kinds button').removeClass('active'); $(this).addClass('active'); state.currentKind = $(this).data('kind'); loadTransactions(false); });
  $('#filter-source,#filter-month').on('change', () => loadTransactions(false));
  $('#filter-search').on('input', function () { clearTimeout(queryTimer); queryTimer = setTimeout(() => loadTransactions(false), 280); });
  $('#load-more').on('click', () => loadTransactions(true));
  $('#toggle-balance').on('click', function () { state.hideBalance = !state.hideBalance; $('#balance-amount').text(state.hideBalance ? '••••••••' : fullMoney(state.totals.balance)); $('#toggle-balance i').attr('class', state.hideBalance ? 'bi bi-eye-slash' : 'bi bi-eye'); });
  $('#top-add-source').on('click', () => openSheet('source'));

  const date = new Date();
  $('#today-pill').text(date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }));
  const h = date.getHours();
  $('#greeting-time').text(h < 12 ? 'morning' : h < 17 ? 'afternoon' : 'evening');
  refresh();
})(jQuery);

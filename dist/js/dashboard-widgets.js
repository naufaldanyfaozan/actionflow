/* ============================================================
   ACTIONFLOW Dashboard — shared widget behavior
   Used by dist/pages/{role}/index.php for all role levels.
   Requires Chart.js + chartjs-plugin-datalabels to already be loaded
   (CDN, defer, in that order - lihat <head> tiap index.php).
   ============================================================ */

/* Didaftarkan sekali secara global supaya angka tampil langsung di atas/pinggir
   tiap bar di semua chart - guard di sini karena beberapa dashboard lama belum
   memuat plugin datalabels-nya. */
if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
  Chart.register(ChartDataLabels);
  // Mati secara default supaya tidak muncul di chart lain yang belum diatur -
  // tiap fungsi render di bawah menyalakannya sendiri lewat opsi plugins.datalabels.
  Chart.defaults.set('plugins.datalabels', { display: false });
}

/* Event delegation di document (bukan attach listener per tombol) supaya tetap
   jalan walau tombolnya dirender belakangan / berulang kali oleh PHP di halaman
   berbeda-beda, dan cuma perlu didaftarkan sekali. */
document.addEventListener('click', function (e) {
  var tabBtn = e.target.closest('.dash-tab-btn');
  if (tabBtn) {
    var target = tabBtn.getAttribute('data-tab-target');
    var targetEl = target ? document.querySelector(target) : null;
    if (!targetEl) return;

    document.querySelectorAll('.dash-tab-btn').forEach(function (b) { b.classList.remove('active'); });
    document.querySelectorAll('.dash-tab-panel').forEach(function (p) { p.classList.remove('active'); });
    tabBtn.classList.add('active');
    targetEl.classList.add('active');
    return;
  }

  var expandBtn = e.target.closest('[data-expand-toggle]');
  if (expandBtn) {
    var table = document.getElementById(expandBtn.getAttribute('data-expand-toggle'));
    if (!table) return;
    var expanded = table.classList.toggle('expanded');
    table.querySelectorAll('.table-extra-row').forEach(function (row) {
      row.style.display = expanded ? '' : 'none';
    });
    expandBtn.textContent = expanded
      ? expandBtn.getAttribute('data-label-collapse')
      : expandBtn.getAttribute('data-label-expand');
    return;
  }

  var prevBtn = e.target.closest('[data-paginate-prev]');
  if (prevBtn) {
    var prevId = prevBtn.getAttribute('data-paginate-prev');
    if (paginationState[prevId]) {
      paginationState[prevId].page -= 1;
      renderPagination(prevId);
    }
    return;
  }

  var nextBtn = e.target.closest('[data-paginate-next]');
  if (nextBtn) {
    var nextId = nextBtn.getAttribute('data-paginate-next');
    if (paginationState[nextId]) {
      paginationState[nextId].page += 1;
      renderPagination(nextId);
    }
  }
});

/* ================= PAGINASI LIST (dipakai buat "Item Kritis" & list sejenis) =================
   Semua item tetap dirender server-side di dalam container-nya - JS ini cuma
   sembunyi/tampilkan sebagian lewat style.display sesuai halaman & ukuran halaman
   yang aktif, jadi tidak perlu request ulang ke server tiap ganti halaman. */
var paginationState = {};

/* id boleh ditaruh di <table> (baris yang dipaginasi ada di <tbody>-nya) atau di
   container biasa seperti <div> (baris yang dipaginasi adalah anak langsungnya). */
function getPaginationItems(list) {
  if (list.tagName === 'TABLE') {
    var tbody = list.querySelector('tbody');
    return tbody ? Array.prototype.slice.call(tbody.children) : [];
  }
  return Array.prototype.slice.call(list.children);
}

function initPagination(listId, defaultSize) {
  var list = document.getElementById(listId);
  if (!list) return;
  paginationState[listId] = { page: 1, pageSize: defaultSize || 10 };
  renderPagination(listId);
}

function renderPagination(listId) {
  var list = document.getElementById(listId);
  var state = paginationState[listId];
  if (!list || !state) return;

  var items = getPaginationItems(list);
  var total = items.length;
  var totalPages = Math.max(1, Math.ceil(total / state.pageSize));

  if (state.page > totalPages) state.page = totalPages;
  if (state.page < 1) state.page = 1;

  var start = (state.page - 1) * state.pageSize;
  var end = start + state.pageSize;
  items.forEach(function (el, idx) {
    el.style.display = (idx >= start && idx < end) ? '' : 'none';
  });

  var info = document.querySelector('[data-paginate-info="' + listId + '"]');
  if (info) {
    info.textContent = total === 0
      ? 'Tidak ada data'
      : 'Halaman ' + state.page + ' dari ' + totalPages + ' (' + total + ' item)';
  }

  var prevBtn = document.querySelector('[data-paginate-prev="' + listId + '"]');
  var nextBtn = document.querySelector('[data-paginate-next="' + listId + '"]');
  if (prevBtn) prevBtn.disabled = state.page <= 1;
  if (nextBtn) nextBtn.disabled = state.page >= totalPages;
}

document.addEventListener('change', function (e) {
  var sizeSelect = e.target.closest('[data-paginate-size-for]');
  if (!sizeSelect) return;

  var listId = sizeSelect.getAttribute('data-paginate-size-for');
  if (!paginationState[listId]) return;

  paginationState[listId].pageSize = parseInt(sizeSelect.value, 10) || 10;
  paginationState[listId].page = 1;
  renderPagination(listId);
});

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-paginate]').forEach(function (list) {
    initPagination(list.id, parseInt(list.getAttribute('data-paginate'), 10) || 10);
  });
});

/* Gabungan % Countermeasure per Departemen (FPM vs Asakai) - grouped bar */
function renderDeptCombinedChart(canvasId, fpmActionDept, asakaiDept) {
  var el = document.getElementById(canvasId);
  if (!el) return;

  var deptSet = {};
  fpmActionDept.forEach(function (d) { deptSet[d.dept] = true; });
  asakaiDept.forEach(function (d) { deptSet[d.dept] = true; });
  var labels = Object.keys(deptSet).sort();

  var fpmMap = {};
  fpmActionDept.forEach(function (d) { fpmMap[d.dept] = d.percent; });
  var asakaiMap = {};
  asakaiDept.forEach(function (d) { asakaiMap[d.dept] = d.percent; });

  new Chart(el, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: '% Closed FPM', data: labels.map(function (l) { return fpmMap[l] ?? 0; }), backgroundColor: '#2563eb', borderRadius: 6 },
        { label: '% Closed Asakai', data: labels.map(function (l) { return asakaiMap[l] ?? 0; }), backgroundColor: '#10b981', borderRadius: 6 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      // Bar yang nilainya dekat 100% butuh ruang kosong di atas chart area supaya
      // label "align: top"-nya tidak kepotong di tepi atas canvas (di luar chart area,
      // label tidak digambar sama sekali - bukan cuma keliatan mepet).
      layout: { padding: { top: 24 } },
      scales: {
        y: { beginAtZero: true, max: 100, ticks: { callback: function (v) { return v + '%'; } } },
        x: { grid: { display: false } }
      },
      plugins: {
        legend: { position: 'bottom' },
        datalabels: {
          display: true,
          anchor: 'end',
          align: 'top',
          offset: 4,
          clamp: true,
          color: '#1e293b',
          font: { weight: 'bold', size: 13 },
          formatter: function (v) { return v + '%'; }
        }
      }
    }
  });
}

/* Distribusi FPM per Departemen - stacked bar (Sudah / Belum / Overdue) */
function renderDeptDistribusiChart(canvasId, labels, sudah, belum, overdue) {
  var el = document.getElementById(canvasId);
  if (!el) return;

  new Chart(el, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Sudah Dijawab', data: sudah, backgroundColor: '#10b981' },
        { label: 'Belum Dijawab', data: belum, backgroundColor: '#f59e0b' },
        { label: 'Over Due Date', data: overdue, backgroundColor: '#ef4444' }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { stacked: true, grid: { display: false } },
        y: { stacked: true, beginAtZero: true }
      },
      plugins: {
        legend: { position: 'bottom' },
        datalabels: {
          display: function (context) { return context.dataset.data[context.dataIndex] > 0; },
          color: '#fff',
          font: { weight: 'bold', size: 13 },
          textStrokeColor: 'rgba(0,0,0,0.35)',
          textStrokeWidth: 2
        }
      }
    }
  });
}

/* Card tab "Terbanyak" (Jenis Masalah / Part / Mesin) - satu horizontal bar chart, ganti data via tombol */
function renderTopMasalahChart(canvasId, switchSelector, datasets) {
  var el = document.getElementById(canvasId);
  if (!el) return;

  var keys = Object.keys(datasets);
  var current = keys[0];

  var chart = new Chart(el, {
    type: 'bar',
    data: {
      labels: datasets[current].labels,
      datasets: [{ data: datasets[current].values, backgroundColor: '#6366f1', borderRadius: 6 }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        datalabels: {
          display: true,
          anchor: 'end',
          align: 'right',
          offset: 4,
          color: '#1e293b',
          font: { weight: 'bold', size: 13 }
        }
      },
      scales: { x: { beginAtZero: true } }
    }
  });

  document.querySelectorAll(switchSelector + ' button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.getAttribute('data-key');
      document.querySelectorAll(switchSelector + ' button').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      chart.data.labels = datasets[key].labels;
      chart.data.datasets[0].data = datasets[key].values;
      chart.update();
    });
  });
}

/* Tren bulanan: total FPM dibuat vs closed, trailing N bulan - grouped bar */
function renderTrenBulananChart(canvasId, labels, total, closed) {
  var el = document.getElementById(canvasId);
  if (!el) return;

  new Chart(el, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Masalah Dibuat', data: total, backgroundColor: '#94a3b8', borderRadius: 6 },
        { label: 'Sudah Selesai', data: closed, backgroundColor: '#10b981', borderRadius: 6 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: { beginAtZero: true },
        x: { grid: { display: false } }
      },
      plugins: {
        legend: { position: 'bottom' },
        datalabels: {
          display: true,
          anchor: 'end',
          align: 'top',
          offset: 4,
          color: '#1e293b',
          font: { weight: 'bold', size: 13 }
        }
      }
    }
  });
}

/* Total Asakai per Machine - horizontal bar, klik bar untuk buka detail mesin */
function renderMachineChart(canvasId, labels, values, links) {
  var el = document.getElementById(canvasId);
  if (!el) return;

  new Chart(el, {
    type: 'bar',
    data: { labels: labels, datasets: [{ data: values, backgroundColor: '#10b981', borderRadius: 6 }] },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        datalabels: {
          display: true,
          anchor: 'end',
          align: 'right',
          offset: 4,
          color: '#1e293b',
          font: { weight: 'bold', size: 13 }
        }
      },
      scales: { x: { beginAtZero: true } },
      onClick: function (evt, elements) {
        if (elements.length && links[elements[0].index]) {
          window.location = links[elements[0].index];
        }
      }
    }
  });
  el.style.cursor = 'pointer';
}

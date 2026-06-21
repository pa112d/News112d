// Admin script for PNE yearly stats
// Expects global `pne_stats` object with { endpoint, nonce, refresh_interval }

(function () {
  if (typeof pne_stats === 'undefined') return;

  const container = document.getElementById('pne-yearly-stats');
  const refreshBtn = document.getElementById('pne-refresh');
  const endpoint = pne_stats.endpoint;
  const nonce = pne_stats.nonce;
  const interval = parseInt(pne_stats.refresh_interval, 10) || 30000;
  let charts = [];

  function clearContainer() {
    charts.forEach(c => {
      try { c.destroy(); } catch (e) {}
    });
    charts = [];
    container.innerHTML = '';
  }

  function createCard(monthObj, idx) {
    const card = document.createElement('div');
    card.className = 'pne-card';
    const title = document.createElement('div');
    title.style.fontWeight = '600';
    title.style.marginBottom = '6px';
    title.textContent = monthObj.label + ' — ' + (monthObj.sent + monthObj.pending + monthObj.error) + ' total';
    const canvas = document.createElement('canvas');
    canvas.id = 'pne-chart-' + idx;
    card.appendChild(title);
    card.appendChild(canvas);
    return { card, canvasId: canvas.id };
  }

  function render(data) {
    clearContainer();
    if (!data || !Array.isArray(data)) {
      container.innerHTML = '<p>Pas de données.</p>';
      return;
    }

    data.forEach((m, i) => {
      const { card, canvasId } = createCard(m, i);
      container.appendChild(card);

      const ctx = document.getElementById(canvasId).getContext('2d');
      const chart = new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: ['Sent', 'Pending', 'Error'],
          datasets: [{
            data: [m.sent || 0, m.pending || 0, m.error || 0],
            backgroundColor: ['#4caf50', '#ffb74d', '#e57373'],
            hoverOffset: 6
          }]
        },
        options: {
          plugins: {
            legend: { display: true, position: 'bottom' },
            tooltip: { enabled: true }
          },
          maintainAspectRatio: false,
        }
      });
      charts.push(chart);
    });
  }

  async function fetchAndRender() {
    try {
      const res = await fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' }
      });
      if (!res.ok) throw new Error('Network response not ok: ' + res.status);
      const payload = await res.json();
      const data = payload && payload.data ? payload.data : null;
      render(data);
    } catch (err) {
      console.error('PNE stats fetch error', err);
      container.innerHTML = '<p>Erreur lors de la récupération des statistiques.</p>';
    }
  }

  // Initial load
  fetchAndRender();

  // Refresh button
  if (refreshBtn) {
    refreshBtn.addEventListener('click', fetchAndRender);
  }

  // Polling
  setInterval(fetchAndRender, interval);
})();

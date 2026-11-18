// Gráficos de la página de medidor (Chart.js 4, sin adaptador de fechas: eje X lineal en ms).
(function () {
  const M = window.MEDIDOR;
  if (!M || !window.Chart) return;
  const css = getComputedStyle(document.documentElement);
  const color = css.getPropertyValue('--' + M.tipo).trim() || '#1f78d1';
  const texto = css.getPropertyValue('--suave').trim();
  const rejilla = css.getPropertyValue('--borde').trim();
  Chart.defaults.color = texto;
  Chart.defaults.borderColor = rejilla;

  const nf = new Intl.NumberFormat('es', { maximumFractionDigits: M.dec });
  const fecha = (ms, conHora) => new Date(ms).toLocaleString('es', conHora
    ? { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }
    : { day: '2-digit', month: 'short' });

  async function datos(qs) {
    const r = await fetch(`api/lecturas.php?id=${M.id}&${qs}`, { credentials: 'same-origin' });
    if (!r.ok) throw new Error(r.status);
    return r.json();
  }

  // ---- consumo diario (barras) ----
  datos('diario=30').then(({ diario }) => {
    new Chart(document.getElementById('g-diario'), {
      type: 'bar',
      data: {
        labels: diario.map((d) => d.dia.slice(8, 10) + '/' + d.dia.slice(5, 7)),
        datasets: [{ data: diario.map((d) => d.consumo), backgroundColor: color, borderRadius: 3 }],
      },
      options: {
        maintainAspectRatio: false,
        plugins: { legend: { display: false },
          tooltip: { callbacks: { label: (c) => `${nf.format(c.parsed.y)} ${M.unidad}` } } },
        scales: { y: { beginAtZero: true } },
      },
    });
  }).catch(() => {});

  // ---- acumulado (línea) con selector de rango ----
  let graf = null;
  async function cargar(rango) {
    document.querySelectorAll('#rangos button').forEach((b) => b.classList.toggle('sec', b.dataset.rango !== rango));
    const { serie } = await datos('rango=' + rango);
    const conHora = rango === '24h' || rango === '7d';
    const cfg = {
      type: 'line',
      data: { datasets: [{ data: serie.map(([x, y]) => ({ x, y })), borderColor: color, backgroundColor: color,
                           pointRadius: serie.length > 60 ? 0 : 2, borderWidth: 2, stepped: false }] },
      options: {
        maintainAspectRatio: false, animation: false, parsing: false,
        interaction: { mode: 'nearest', intersect: false, axis: 'x' },
        plugins: { legend: { display: false },
          tooltip: { callbacks: { title: (it) => fecha(it[0].parsed.x, true),
                                  label: (c) => `${nf.format(c.parsed.y)} ${M.unidad}` } } },
        scales: {
          x: { type: 'linear', min: serie[0]?.[0], max: serie[serie.length - 1]?.[0],
               ticks: { maxTicksLimit: 8, callback: (v) => fecha(v, conHora) } },
          y: { ticks: { callback: (v) => nf.format(v) } },
        },
      },
    };
    if (graf) graf.destroy();
    graf = new Chart(document.getElementById('g-acum'), cfg);
  }
  document.querySelectorAll('#rangos button').forEach((b) => b.addEventListener('click', () => cargar(b.dataset.rango)));
  cargar('24h').catch(() => {});
})();

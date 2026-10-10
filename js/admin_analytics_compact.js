// Minimalist Chart.js config and toggles
// Requires Chart.js already included in the page
document.addEventListener('DOMContentLoaded', function () {
  // UTIL: compact options for line charts
  const compactLineOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false }, // remove large legend
      tooltip: { mode: 'index', intersect: false }
    },
    elements: {
      point: { radius: 0 } // remove point clutter
    },
    scales: {
      x: { 
        grid: { display: false },
        ticks: { maxRotation: 0, maxTicksLimit: 8, autoSkip: true }
      },
      y: {
        grid: { color: '#f5f5f5' },
        ticks: { maxTicksLimit: 4 }
      }
    }
  };

  // Create main timeseries chart with reduced height
  const salesCtx = document.getElementById('totalSalesChart').getContext('2d');
  // Use existing server-side data variables; replace below with your PHP-passed JSON arrays
  const totalSalesData = window.totalSalesData || { labels: [], datasets: [{ data: [] }] };

  const totalSalesChart = new Chart(salesCtx, {
    type: 'line',
    data: {
      labels: totalSalesData.labels,
      datasets: [{
        label: 'Total Sales (PHP)',
        data: totalSalesData.datasets[0].data,
        borderColor: '#7c4dff',
        backgroundColor: 'rgba(124,77,255,0.06)',
        borderWidth: 2,
        fill: true,
      }]
    },
    options: Object.assign({}, compactLineOptions)
  });

  // Category chart (bar or pie) - keep compact legend
  const catCtx = document.getElementById('categoryChart').getContext('2d');
  const categoryData = window.categoryData || { labels: [], values: [] };

  const categoryChart = new Chart(catCtx, {
    type: 'doughnut',
    data: {
      labels: categoryData.labels,
      datasets: [{
        data: categoryData.values,
        backgroundColor: ['#7c4dff','#ff6b6b','#22c55e','#f59e0b','#60a5fa'],
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 8, usePointStyle: true, font: { size: 12 } } } }
    }
  });

  // Sparklines for KPI cards - tiny line charts
  function makeSpark(canvasId, data, color) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: { labels: data.labels || [], datasets: [{ data: data.values || [], borderColor: color, backgroundColor: 'rgba(0,0,0,0)', borderWidth: 2 }] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        elements: { point: { radius: 0 } },
        scales: { x: { display: false }, y: { display: false } }
      }
    });
  }

  // Example spark data; replace with server data
  makeSpark('sparkTotalSales', window.sparkTotalSales || { labels: [], values: [] }, '#7c4dff');
  makeSpark('sparkOrders', window.sparkOrders || { labels: [], values: [] }, '#60a5fa');
  makeSpark('sparkAvgOrder', window.sparkAvgOrder || { labels: [], values: [] }, '#22c55e');
  makeSpark('sparkConversion', window.sparkConversion || { labels: [], values: [] }, '#ff6b6b');

  // Collapse toggles
  function attachToggle(btnId, cardId) {
    const btn = document.getElementById(btnId);
    const card = document.getElementById(cardId);
    btn.addEventListener('click', function () {
      const collapsed = card.classList.toggle('chart-collapsed');
      btn.textContent = collapsed ? 'Expand' : 'Collapse';
    });
  }
  attachToggle('toggleTimeseries', 'timeseriesCard');
  attachToggle('toggleCategory', 'categoryCard');

  // Refresh button - just re-run charts data update (you likely have AJAX)
  document.getElementById('refreshBtn').addEventListener('click', function () {
    // Keep your existing refresh logic; this example simply triggers chart.update()
    totalSalesChart.update();
    categoryChart.update();
  });
});
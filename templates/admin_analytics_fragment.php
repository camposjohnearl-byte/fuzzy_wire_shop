<?php
/* Minimalist layout snippet for admin_analytics.php
   Replace the wide stacked layout with compact KPI row + two-column grid.
   Keep existing chart data assignments and chart rendering logic intact.
*/
?>
<div class="analytics-container">
  <div class="analytics-header">
    <h1 class="analytics-title">Analytics & Sales</h1>
    <div class="analytics-toolbar">
      <select id="rangeSelect" class="btn">
        <option value="30">Last 30 days</option>
        <option value="7">Last 7 days</option>
        <option value="365">Last 365 days</option>
      </select>
      <button class="btn" id="refreshBtn">Refresh</button>
      <button class="btn" id="exportCsvBtn">Export CSV</button>
      <button class="btn" id="exportPdfBtn">Export PDF / Print</button>
    </div>
  </div>

  <!-- KPI small row -->
  <div class="kpi-row">
    <div class="kpi-card">
      <div>
        <div class="kpi-label">Total Sales</div>
        <div class="kpi-value" id="kpiTotalSales">₱0.00</div>
      </div>
      <div class="kpi-spark">
        <canvas id="sparkTotalSales"></canvas>
      </div>
    </div>

    <div class="kpi-card">
      <div>
        <div class="kpi-label">Orders</div>
        <div class="kpi-value" id="kpiOrders">0</div>
      </div>
      <div class="kpi-spark">
        <canvas id="sparkOrders"></canvas>
      </div>
    </div>

    <div class="kpi-card">
      <div>
        <div class="kpi-label">Avg Order</div>
        <div class="kpi-value" id="kpiAvgOrder">₱0.00</div>
      </div>
      <div class="kpi-spark">
        <canvas id="sparkAvgOrder"></canvas>
      </div>
    </div>

    <div class="kpi-card">
      <div>
        <div class="kpi-label">Conversion</div>
        <div class="kpi-value" id="kpiConversion">0%</div>
      </div>
      <div class="kpi-spark">
        <canvas id="sparkConversion"></canvas>
      </div>
    </div>
  </div>

  <!-- Main grid -->
  <div class="analytics-grid">
    <div class="chart-card" id="timeseriesCard">
      <div class="card-title">
        <span>Total Sales (time series)</span>
        <div class="chart-controls">
          <button class="btn" id="toggleTimeseries">Collapse</button>
        </div>
      </div>
      <div class="card-body">
        <canvas id="totalSalesChart"></canvas>
      </div>
    </div>

    <div class="chart-card" id="categoryCard">
      <div class="card-title">
        <span>Sales by Category</span>
        <div class="chart-controls">
          <button class="btn" id="toggleCategory">Collapse</button>
        </div>
      </div>
      <div class="card-body">
        <canvas id="categoryChart"></canvas>
      </div>
    </div>
  </div>
</div>
<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Delivery Pivot Report</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <script src="api_config.php"></script>
  <style>
    body { background: transparent; color: #fdb04c; font-family: sans-serif; padding: 0; margin: 0; }
    h2 { margin-top: 0; margin-bottom: 20px; color: #fdb04c; }
    table { width: 100%; border-collapse: collapse; margin-top: 1.5em; }
    th, td { text-align: center; padding: 10px; border: 1px solid #444; }
    th { background: #232323; color: #fdb04c; }
    td { background: #1a1a1a; color: #fff; }
    .daily-total { font-weight: bold; color: #fdb04c; }
    .no-data-row { color: #e77; text-align: center; }
    label { color: #fdb04c; font-weight: bold; }
    .controls { margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    input[type="date"] { padding: 8px 12px; border-radius: 5px; border: 1px solid #444; background: #292929; color: #fdb04c; font-weight: bold; min-width: 150px; }
    button { padding: 8px 14px; border-radius: 5px; border: none; background: #ff9800; color: white; cursor: pointer; font-weight: bold; transition: background 0.2s; }
    button:hover { background: #f57c00; }
    .report-wrapper { padding: 0; margin: 0; }
  </style>
</head>
<body>
  <div class="report-wrapper">
    <h2>Delivery Pivot Report</h2>
    <div class="controls mb-4">
      <label for="dateFilter" class="me-1">Show only this date:</label>
      <input type="date" id="dateFilter" onchange="renderPivotTable()">
      <button onclick="clearFilter()">Clear Filter</button>
    </div>
    <div id="pivotTable"></div>
  </div>

  <script>
    let pivotData = {};
    let deliveries = [];
    let rawDates = [];

    function initReport() {
      fetch('get_amount_delivery.php')
        .then(res => res.json())
        .then(data => {
          pivotData = data.pivot;
          deliveries = data.deliveries;
          rawDates = Object.keys(pivotData).sort((a, b) => new Date(b) - new Date(a));
          renderPivotTable();
        })
        .catch(err => {
          document.getElementById('pivotTable').innerHTML = "<div class='no-data-row'>Failed to load report</div>";
          console.error('Error:', err);
        });
    }

    function renderPivotTable() {
      let filterDate = document.getElementById('dateFilter')?.value;
      let datesToShow = filterDate ? rawDates.filter(d => d === filterDate) : rawDates;

      let html = "<table class='table table-bordered'>";
      html += "<thead><tr><th>Date</th>";
      deliveries.forEach(name => html += `<th>${name}</th>`);
      html += "<th>Total</th></tr></thead><tbody>";

      if (datesToShow.length === 0) {
        html += `<tr><td colspan="${deliveries.length + 2}" class="no-data-row">No report for this date</td></tr>`;
      } else {
        datesToShow.forEach(date => {
          let dailyTotal = 0;
          html += `<tr><td>${date}</td>`;
          deliveries.forEach(name => {
            const count = pivotData[date][name] || "";
            html += `<td>${count}</td>`;
            dailyTotal += count ? parseInt(count) : 0;
          });
          html += `<td class="daily-total">${dailyTotal}</td></tr>`;
        });
      }
      html += "</tbody></table>";
      document.getElementById('pivotTable').innerHTML = html;
    }

    function clearFilter() {
      document.getElementById('dateFilter').value = '';
      renderPivotTable();
    }

    // Auto-initialize when loaded
    initReport();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

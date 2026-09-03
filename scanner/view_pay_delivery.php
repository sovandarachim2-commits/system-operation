<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Payment & Delivery Pivot Report</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body { background: #222; color: #fdb04c; font-family: sans-serif; }
    h2 { margin-top: 32px; }
    table { width: 100%; border-collapse: collapse; margin-top: 1.5em; }
    th, td { text-align: center; padding: 10px; border: 1px solid #444; }
    th { background: #232323; color: #fdb04c; }
    td { background: #292929; color: #fff; }
    .daily-total { font-weight: bold; color: #fdb04c; }
    .no-data-row { color: #e77; text-align: center; }
  </style>
</head>
<body>
  <div class="container my-4">
    <h2 class="text-center">Payment & Delivery Pivot Report</h2>
    <div class="mb-4">
      <label for="dateFilter" class="me-1">Show only this date:</label>
      <input type="date" id="dateFilter" class="form-control" style="display:inline-block; width:auto; min-width:150px;" onchange="renderPivotTable()">
    </div>
    <div id="pivotTable"></div>
  </div>

  <script>
    let pivotData = {};
    let deliveries = [];
    let rawDates = [];

    fetch('get_pay_delivery.php')
      .then(res => res.json())
      .then(data => {
        pivotData = data.pivot;
        deliveries = data.deliveries;
        rawDates = Object.keys(pivotData).sort((a, b) => new Date(b) - new Date(a));
        renderPivotTable();
      })
      .catch(err => {
        document.getElementById('pivotTable').innerHTML = "<div class='no-data-row'>Failed to load report</div>";
      });

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
            html += `<td>${count ? ('$' + count) : ""}</td>`;
            dailyTotal += count ? parseFloat(count) : 0;
          });
          html += `<td class="daily-total">${dailyTotal ? ('$' + dailyTotal) : ""}</td></tr>`;
        });
      }
      html += "</tbody></table>";
      document.getElementById('pivotTable').innerHTML = html;
    }

  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

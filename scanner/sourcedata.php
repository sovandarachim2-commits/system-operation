<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Dashboard (INV Join)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script src="api_config.php"></script>
  <style>
    body {background:#222; color:#fdb04c; font-family:sans-serif;}
    th, td {text-align:center;}
    th {background:#232323;}
    td {background:#292929;}
    .modal-content {background:#292929; color:#fdb04c;}
    .modal-header {border-bottom: none;}
  </style>
</head>
<body>
  <h2 class="text-center mt-3">របាយការណ៍ចេញឥវ៉ាន់</h2>
  <div class="container">
    <div class="row mb-3 justify-content-start">
      <div class="col-auto">
        <button class="btn btn-warning" onclick="showExportModal()">
          Export to Excel
        </button>
      </div>
      <div class="col-auto">
        <button class="btn btn-success" onclick="printTable()">
          Print របាយការណ៍ចេញឥវ៉ាន់
        </button>
      </div>
      <div class="col-auto">
        <input type="date" class="form-control" id="filterDate" onchange="renderFilteredTable()">
      </div>
      <div class="col-auto">
        <select class="form-select" id="filterDelivery" onchange="renderFilteredTable()">
          <option value="">All Delivery</option>
          <!-- Dynamically filled -->
        </select>
      </div>
      <div class="col-auto">
        <select class="form-select" id="topRowsSelect" style="width:auto; min-width:115px;">
          <option value="100" selected>Show Top 100</option>
          <option value="300">Show Top 300</option>
          <option value="all">Show All</option>
        </select>
      </div>
    </div>
    <table class="table table-dark table-borderless align-middle" id="dashboardTable">
      <thead>
        <tr>
          <th>No</th>
          <th>Date</th>
          <th>INV ID</th>
          <th>Phone Number</th>
          <th>Amount</th>
          <th>Delivery By</th>
          <th>Status</th>
          <th>Remark</th>
        </tr>
      </thead>
      <tbody>
        <!-- Data loads here -->
      </tbody>
    </table>
  </div>

  <!-- Styled Bootstrap modal -->
  <div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="exportModalLabel"><span style="color:#ffe181;">Confirm Export</span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-center">
          <p>Do you want to export the filtered table to Excel?</p>
        </div>
        <div class="modal-footer justify-content-center">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-warning" onclick="confirmExport()">Confirm</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    let allData = [];
    function fetchAndRender() {
      fetch(API_GET_SOURCE_DATA)
        .then(res => res.json())
        .then(data => {
          allData = data; // Save to variable for filtering
          fillDeliveryOptions();
          renderFilteredTable();
        })
        .catch(()=> {
          const tbody = document.getElementById('dashboardTable').querySelector('tbody');
          tbody.innerHTML = `<tr><td colspan="8" style="color:#f77;">Failed to load data</td></tr>`;
        });
    }

    function fillDeliveryOptions() {
      const select = document.getElementById('filterDelivery');
      const uniqueDelivery = [...new Set(allData.map(row => row.delivery_by).filter(x => x))];
      select.innerHTML = `<option value="">All Delivery</option>`;
      uniqueDelivery.forEach(name => {
        select.innerHTML += `<option value="${name}">${name}</option>`;
      });
    }

    function renderFilteredTable() {
      const dateFilter = document.getElementById('filterDate').value;
      const deliveryFilter = document.getElementById('filterDelivery').value;
      const topRowsValue = document.getElementById('topRowsSelect').value;
      const tbody = document.getElementById('dashboardTable').querySelector('tbody');
      let filtered = allData;

      if (dateFilter) {
        filtered = filtered.filter(row => row.date.split(' ')[0] === dateFilter);
      }
      if (deliveryFilter) {
        filtered = filtered.filter(row => row.delivery_by === deliveryFilter);
      }

      // Oldest first
      filtered.sort((a, b) => new Date(a.date) - new Date(b.date));

      // Top row limiting!
      let displayed = filtered;
      if (topRowsValue !== "all") {
        displayed = filtered.slice(0, parseInt(topRowsValue, 10));
      }

      tbody.innerHTML = "";
      displayed.forEach((row, idx) => {
        const onlyDate = row.date.split(' ')[0];
        tbody.innerHTML += `
          <tr>
            <td>${idx + 1}</td>
            <td>${onlyDate}</td>
            <td>${row.inv_id}</td>
            <td>${row.phone_number || ''}</td>
            <td>${row.amount || ''}</td>
            <td>${row.delivery_by || ''}</td>
            <td>${row.paid_unpaid || ''}</td>
            <td>                        </td>
          </tr>
        `;
      });
      if(displayed.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" style="color:#f77;">No data found</td></tr>`;
      }
    }

    document.getElementById('topRowsSelect').addEventListener('change', renderFilteredTable);

    // --- Confirm dialog logic using Bootstrap modal ---
    function showExportModal() {
      const modal = new bootstrap.Modal(document.getElementById('exportModal'));
      modal.show();
    }
    function confirmExport() {
      exportTableToExcel('dashboardTable', 'dashboard_data');
      bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
    }

    function exportTableToExcel(tableID, filename = ''){
      var table = document.getElementById(tableID);
      var wb = XLSX.utils.table_to_book(table, {sheet:"Sheet 1"});
      return XLSX.writeFile(wb, filename ? filename + '.xlsx' : 'export.xlsx');
    }

    // --- Print Table Button Function ---
    function printTable() {
      var table = document.getElementById('dashboardTable');
      // Calculate totals
      let totalPaid = 0, totalUnpaid = 0, total = 0;
      let rows = table.querySelectorAll("tbody tr");
      rows.forEach(tr => {
        const amountText = tr.children[4]?.textContent || "0";
        const amount = parseFloat(amountText.replace(/,/g,"")) || 0;
        const status = (tr.children[6]?.textContent || "").trim();
        total += amount;
        if (status === "Paid") totalPaid += amount;
        if (status === "Unpaid") totalUnpaid += amount;
      });
    
      // Add summary row HTML
      const summary = `
        <tr>
          <td colspan="8" style="text-align:right;background:#feebbc;color:#914c00;">
            <b>Total Paid:</b> ${totalPaid.toLocaleString()}&nbsp;&nbsp;
            <b>Total Unpaid:</b> ${totalUnpaid.toLocaleString()}&nbsp;&nbsp;
            <b>Total:</b> ${total.toLocaleString()}
          </td>
        </tr>
      `;
    
      // Clone the table for safe manipulation
      var printTable = table.cloneNode(true);
      printTable.querySelector('tbody').innerHTML += summary;
    
      // Now print
      var win = window.open('', '', 'height=1000,width=1400');
      win.document.write('<html><head><title>Dashboard Print Data</title>');
      win.document.write(`
        <style>
          @media print {
            body { margin:8px !important; font-size:13px; background:#fff; color:#111; }
            h2 { margin:0 0 12px 0; font-size:18px;}
            table { width:100% !important; border-collapse:collapse; margin:0; font-size:13px;}
            th, td {
              padding:4px 8px;
              border:1px solid #222; text-align:center;
              font-family: Arial, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            th { background:#f2f2f2; color:#222; font-weight:bold; }
            td { background:#fff; color:#222; }
          }
          body { margin:8px; font-size:13px; background:#fff; color:#111;}
          h2 { margin:0 0 12px 0; font-size:18px; text-align:center; }
          table { width:100% !important; border-collapse:collapse; margin:0; font-size:13px;}
          th, td {
            padding:4px 8px;
            border:1px solid #222; text-align:center;
            font-family: Arial, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
          }
          th { background:#f2f2f2; color:#222; font-weight:bold; }
          td { background:#fff; color:#222; }
        </style>
      `);
      win.document.write('</head><body>');
      win.document.write('<h2>របាយការណ៍ចេញឥវ៉ាន់</h2>');
      win.document.write(printTable.outerHTML);
      win.document.write('</body></html>');
      win.document.close();
      win.focus();
      setTimeout(function(){ win.print(); win.close(); }, 400);
    }

    fetchAndRender();
    // Optionally, refresh every N minutes: setInterval(fetchAndRender, 60000);
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'seller', 'cashier', 'scanner'], 'items_view.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>View All Items</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="api_config.php"></script>
  <script src="html5-qrcode.min.js@2.3.7/html5-qrcode.min.js"></script>
  <style>
    body {background:#222; color:#fdb04c; font-family:sans-serif;}
    th, td {text-align:center; vertical-align:middle;}
    th {background:#232323;}
    td {background:#292929;}
    img {max-width:90px; border-radius:6px; cursor:pointer;}
    .modal-content {background:#292929; color:#fdb04c;}
    .enlarged-img {max-width:100%; max-height:80vh; border-radius:12px;}
    @media print {
    body {
      background: #fff !important;
      color: #111 !important;
    }
    .container-fluid, .container {
      background: #fff !important;
      color: #333 !important;
    }
    h2 { color: #333 !important; }
    table {
      font-size: 12px !important;
      background: #fff !important;
      color: #111;
      border-collapse: collapse !important;
      width: 100% !important;
    }
    th, td {
      border: 1px solid #888 !important;
      background: #fafafa !important;
      color: #111 !important;
      padding: 5px 7px !important;
      text-align: center;
      vertical-align: middle !important;
      font-size: 11.5px !important;
      word-break: break-word;
    }
    th {
      background: #e8eefd !important;
      color: #202a70 !important;
    }
    img {
      max-width: 70px !important;
      max-height: 60px !important;
      border-radius: 6px !important;
      display: inline-block;
    }
    .btn, button, input, select, .modal, .modal-backdrop, .action-select { display: none !important; }
    #qrModal, #editModal, #detailModal, #imgModal, .modal-backdrop { display: none !important; }
    .table { page-break-inside: auto; }
    tr { page-break-inside: avoid !important; page-break-after: auto; }
  }

  </style>
</head>
<body>
  <h2 class="text-center mt-3" style="font-family: Khmer OS;">ទិន្នន័យឥវ៉ាន់ទាំងអស់</h2>
  <button onclick="window.print()" class="btn btn-success">Print or Save PDF</button>
  <div class="container mt-4">
    <div class="row mb-3 justify-content-start">
      <div class="col-auto d-flex align-items-center">
        <label for="fromDate" class="me-1 mb-0">From:</label>
        <input type="date" class="form-control me-3" style="width:auto;min-width:140px;" id="fromDate" onchange="filterAndRender()">
        <label for="toDate" class="me-1 mb-0">To:</label>
        <input type="date" class="form-control" style="width:auto;min-width:140px;" id="toDate" onchange="filterAndRender()">
      </div>
      <div class="col-auto">
        <select class="form-select" id="filterDelivery" onchange="filterAndRender()">
          <option value="">All Delivery</option>
        </select>
      </div>
      <!-- <div class="col-auto d-flex align-items-center">
        <span style="font-size:1.2rem;">Total Money:&nbsp;<span id="totalMoney">0</span></span>
      </div> -->
      <div class="col-auto">
        <input type="text" class="form-control" placeholder="Search item..." id="searchInput" oninput="onSearchInput()">
      </div>
      <div class="col-auto">
        <button class="btn btn-success" onclick="showQrModal()">Scan QR Code</button>
      </div>
    </div>
    <!-- select row top -->
    <div class="col-auto">
      <select class="form-select" id="topRowsSelect" onchange="filterAndRender()" style="width:auto; min-width:90px;">
        <option value="100" selected>Top 100</option>
        <option value="1000">Top 1000</option>
        <option value="all">All</option>
      </select>
    </div>

    <table class="table table-dark table-borderless align-middle" id="allItemsTable">
      <thead>
        <tr>
          <th>No</th>
          <th>Barcode</th>
          <th>Phone</th>
          <th>Status</th>
          <th>Amount</th>
          <th>INV Photo(P)</th>
          <th>INV Photo(O)</th>
          <th>Photo Full(P)</th>
          <th>Photo Full(O)</th>
          <th>Delivery By</th>
          <th style="font-family: Khmer OS;">ថ្ងៃដាក់ចេញឥវ៉ាន់</th>
          <th style="font-family: Khmer OS;">ថ្ងៃខ្ចប់ឥវ៉ាន់</th>
        </tr>
      </thead>
      <tbody>
        <!-- Data loads here -->
      </tbody>
    </table>
  </div>
  <!-- Modal sections unchanged, just as you had -->
  <div class="modal fade" id="qrModal" tabindex="-1" aria-labelledby="qrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="qrModalLabel">Scan QR Code</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="endQrScan()"></button>
        </div>
        <div class="modal-body text-center">
          <div id="qr-reader" style="width:100%;"></div>
          <div id="qr-status" style="color:#fdb04c;margin-top:0.5em;"></div>
        </div>
      </div>
    </div>
  </div>
  <div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="detailModalLabel">Item Details</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="detailBody"></div>
      </div>
    </div>
  </div>
  <div class="modal fade" id="imgModal" tabindex="-1" aria-labelledby="imgModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content text-center">
        <div class="modal-header">
          <h5 class="modal-title" id="imgModalLabel">Full Image</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <img src="" id="modalImg" class="enlarged-img" alt="Enlarged image">
        </div>
      </div>
    </div>
  </div>
  <script>
    let allData = [];
    let qrScanner = null;
    let topInvNumber = null;

    function fetchAllItems() {
      fetch(API_GET_ALL_ITEMS)
        .then(res => res.json())
        .then(data => {
          allData = data;
          fillDeliveryOptions();
          filterAndRender();
        }).catch(()=>{
          document.querySelector('#allItemsTable tbody').innerHTML =
            `<tr><td colspan="12" style="color:#f77;">Failed to load data</td></tr>`;
        });
    }

    function fillDeliveryOptions() {
      const select = document.getElementById('filterDelivery');
      const unique = [...new Set(allData.map(x => x.delivery_by || 'NO OUT'))];
      select.innerHTML = `<option value="">All Delivery</option>`;
      unique.forEach(name => {
        select.innerHTML += `<option value="${name}">${name}</option>`;
      });
    }

    function onSearchInput() {
      const val = document.getElementById('searchInput').value.trim();
      if (val && allData.some(x => x.inv_number == val)) {
        topInvNumber = val;
      } else {
        topInvNumber = null;
      }
      filterAndRender();
    }

    function filterAndRender() {
      const fromDateVal = document.getElementById('fromDate').value;
      const toDateVal = document.getElementById('toDate').value;
      const deliveryFilter = document.getElementById('filterDelivery').value;
      const searchVal = document.getElementById('searchInput').value.trim().toLowerCase();
      const topRowsValue = document.getElementById('topRowsSelect').value;

      let filtered = allData;

      // Filter by date range (inclusive)
      if (fromDateVal && toDateVal) {
        const fromDate = new Date(fromDateVal);
        const toDate = new Date(toDateVal);
        filtered = filtered.filter(x => {
          const d = new Date((x.date_prepare || '').split(' ')[0]);
          return d >= fromDate && d <= toDate;
        });
      } else if (fromDateVal && !toDateVal) {
        const fromDate = new Date(fromDateVal);
        filtered = filtered.filter(x => {
          const d = new Date((x.date_prepare || '').split(' ')[0]);
          return d >= fromDate;
        });
      } else if (!fromDateVal && toDateVal) {
        const toDate = new Date(toDateVal);
        filtered = filtered.filter(x => {
          const d = new Date((x.date_prepare || '').split(' ')[0]);
          return d <= toDate;
        });
      }

      if (deliveryFilter)
        filtered = filtered.filter(x => (x.delivery_by || 'NO OUT') === deliveryFilter);

      if (searchVal) {
        filtered = filtered.filter(x =>
          (x.inv_number || '').toString().toLowerCase().includes(searchVal) ||
          (x.phone_number || '').toString().toLowerCase().includes(searchVal) ||
          (x.delivery_by || '').toString().toLowerCase().includes(searchVal)
        );
      }

      // Sort by oldest date_prepare first (ascending)
      filtered.sort((a, b) => new Date(b.date_prepare) - new Date(a.date_prepare));

      if (topInvNumber) {
        const idx = filtered.findIndex(x => x.inv_number == topInvNumber);
        if (idx > 0) {
          const [item] = filtered.splice(idx, 1);
          filtered.unshift(item);
        }
      }

      // Limit rows displayed based on topRowsSelect value
      let displayed = filtered;
      if (topRowsValue !== 'all') {
        const limit = parseInt(topRowsValue, 10);
        displayed = filtered.slice(0, limit);
      }

      const tbody = document.querySelector('#allItemsTable tbody');
      tbody.innerHTML = "";

      displayed.forEach((item, idx) => {
        let amount = parseFloat(item.amount) || 0;
        tbody.innerHTML += `
          <tr>
            <td>${idx + 1}</td>
            <td>${item.inv_number || ''}</td>
            <td>${item.phone_number || ''}</td>
            <td>${item.paid_unpaid || ''}</td>
            <td>${item.amount || ''}</td>
            <td>${item.inv_photo_prepare ? `<img src="${item.inv_photo_prepare}" alt="INV Photo Prepare" onclick="showImageModal('${item.inv_photo_prepare}')" />` : ''}</td>
            <td>${item.inv_photo_out ? `<img src="${item.inv_photo_out}" alt="INV Photo Out" onclick="showImageModal('${item.inv_photo_out}')" />` : ''}</td>
            <td>${item.photo_full_prepare ? `<img src="${item.photo_full_prepare}" alt="Photo Full Prepare" onclick="showImageModal('${item.photo_full_prepare}')" />` : ''}</td>
            <td>${item.photo_full_out ? `<img src="${item.photo_full_out}" alt="Photo Full Out" onclick="showImageModal('${item.photo_full_out}')" />` : ''}</td>
            <td>${item.delivery_by || 'NO OUT'}</td>
            <td>${item.date_delivery_out || 'NO OUT'}</td>
            <td>${item.date_prepare || ''}</td>
          </tr>
        `;
      });

      if (displayed.length === 0)
        tbody.innerHTML = `<tr><td colspan="12" style="color:#f77;">No data found</td></tr>`;
    }


    function showQrModal() {
      const qrModal = new bootstrap.Modal(document.getElementById('qrModal'));
      qrModal.show();
      startQrScan();
    }
    function startQrScan() {
      document.getElementById('qr-status').textContent = '';
      if (!qrScanner) {
        qrScanner = new Html5Qrcode("qr-reader");
      }
      qrScanner.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: 250 },
        qrCodeMessage => {
          qrScanner.stop();
          document.getElementById('qr-status').textContent = "QR Found: " + qrCodeMessage;
          showDetailByInv(qrCodeMessage.trim());
          bootstrap.Modal.getInstance(document.getElementById('qrModal')).hide();
        },
        errorMessage => {
          // Optional: show scan errors if needed
        }
      );
    }
    function endQrScan() {
      if (qrScanner) qrScanner.stop();
      document.getElementById('qr-status').textContent = '';
      document.getElementById('qr-reader').innerHTML = "";
    }
    function showDetailByInv(invNumber) {
      topInvNumber = invNumber;
      const match = allData.find(x => x.inv_number == invNumber);
      if (!match) {
        alert('No item found for INV ' + invNumber);
        return;
      }
      let html =
        `<table class="table table-dark">
          <tr><th>Barcode</th><td>${match.inv_number || ''}</td></tr>
          <tr><th>Phone Number</th><td>${match.phone_number || ''}</td></tr>
          <tr><th>Status</th><td>${match.paid_unpaid || ''}</td></tr>
          <tr><th>Amount</th><td>${match.amount || ''}</td></tr>
          <tr><th>INV Photo(P)</th><td>${match.inv_photo_prepare ? `<img src="${match.inv_photo_prepare}" alt="" onclick="showImageModal('${match.inv_photo_prepare}')" />` : ''}</td></tr>
          <tr><th>INV Photo(O)</th><td>${match.inv_photo_out ? `<img src="${match.inv_photo_out}" alt="" onclick="showImageModal('${match.inv_photo_out}')" />` : ''}</td></tr>
          <tr><th>Photo Full(P)</th><td>${match.photo_full_prepare ? `<img src="${match.photo_full_prepare}" alt="" onclick="showImageModal('${match.photo_full_prepare}')" />` : ''}</td></tr>
          <tr><th>Photo Full(O))</th><td>${match.photo_full_out ? `<img src="${match.photo_full_out}" alt="" onclick="showImageModal('${match.photo_full_out}')" />` : ''}</td></tr>
          <tr><th>Delivery By</th><td>${match.delivery_by || 'NO OUT'}</td></tr>
          <tr><th style="font-family: Khmer OS;">ថ្ងៃដាក់ចេញឥវ៉ាន់</th><td>${match.date_delivery_out || ''}</td></tr>
          <tr><th style="font-family: Khmer OS;">ថ្ងៃខ្ចប់ឥវ៉ាន់</th><td>${match.date_prepare || ''}</td></tr>
        </table>`;
      document.getElementById('detailBody').innerHTML = html;
      const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
      detailModal.show();
      filterAndRender();
    }
    function showImageModal(url) {
      const modalImg = document.getElementById('modalImg');
      modalImg.src = url;
      const imgModal = new bootstrap.Modal(document.getElementById('imgModal'));
      imgModal.show();
    }
    fetchAllItems();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

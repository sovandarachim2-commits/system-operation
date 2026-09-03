<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Confirm Items</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="html5-qrcode.min.js@2.3.7/html5-qrcode.min.js"></script>
  <script src="api_config.php"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
  <style>
    body {background:#222; color:#fff; font-family:sans-serif;}
    th, td {text-align:center; vertical-align:middle; padding:8px!important;}
    th {background:#232323;}
    td {background:#292929;}
    img {max-width:40px; border-radius:6px; cursor:pointer;}
    .modal-content {background:#292929; color:#fff;}
    .enlarged-img {max-width:100%; max-height:80vh; border-radius:12px;}
    .form-select {
      background:#292929; color:#fff; border:1px solid #444;
      padding:5px 8px; font-size:.9rem; width:100%; min-height:35px;
    }
    .form-select option {background:#292929; color:#fff;}
    .form-control {
      background:#575757; color:#fff; border:1px solid #00ff22;
      padding:5px 8px; font-size:.9rem; width:auto; min-height:5px;
    }
    .form-control::placeholder {color:#888;}
    .btn-success {background:#198754; border-color:#198754;}
    .btn-success:hover {background:#157347;}
    .btn-delete {background:#dc3545; border-color:#dc3545;}
    .btn-delete:hover {background:#c82333;}
  </style>
</head>
<body>
  <h2 class="text-center mt-3">Confirm Items</h2>
  <div class="container-fluid mt-4">
    <div class="row mb-3 justify-content-start">
      <div class="col-auto d-flex align-items-center">
        <label for="fromDate" class="me-1 mb-0">From:</label>
        <input type="date" class="form-control me-3" id="fromDate" onchange="filterAndRender()">
        <label for="toDate" class="me-1 mb-0">To:</label>
        <input type="date" class="form-control" id="toDate" onchange="filterAndRender()">
      </div>
      <div class="col-auto">
        <select class="form-select" id="filterDelivery" onchange="filterAndRender()">
          <option value="">All Delivery</option>
        </select>
      </div>
      <div class="col-auto d-flex align-items-center">
        <span style="font-size:1.2rem;">Total Money:&nbsp;<span id="totalMoney">0</span></span>
      </div>
      <div class="row mt-2 mb-3">
        <div class="col-auto">
          <span style="font-size:1rem; color:#00ff22">Items: <span id="countItems">0</span></span>
        </div>
        <div class="col-auto">
          <span style="font-size:1rem; color:#28a745;">Complete: <span id="countComplete">0</span></span>
        </div>
        <div class="col-auto">
          <span style="font-size:1rem; color:#ffc107;">Return: <span id="countReturn">0</span></span>
        </div>
        <div class="col-auto">
          <span style="font-size:1rem; color:#17a2b8;">Change: <span id="countChange">0</span></span>
        </div>
      </div>
      <div class="col-auto">
        <input type="text" class="form-control" placeholder="Search item..." id="searchInput" oninput="onSearchInput()">
      </div>
      <div class="col-auto">
        <button class="btn btn-success" onclick="showQrModal()">Scan QR Code</button>
      </div>
    </div>
    <div class="col-auto">
      <select class="form-select" id="topRowsSelect" onchange="filterAndRender()" style="width:auto;min-width:90px;">
        <option value="100" selected>បង្ហាញទិន្នន័យ100ជួរ</option>
        <option value="1000">បង្ហាញទិន្នន័យ1000ជួរ</option>
        <option value="all">បង្ហាញទិន្នន័យAll</option>
      </select>
    </div>
    <div style="overflow-x:auto;">
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
            <th>Action</th>
            <th>Input</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        </tbody>
      </table>
    </div>
  </div>

  <!-- QR Modal -->
  <div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Scan QR Code</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="endQrScan()"></button>
        </div>
        <div class="modal-body text-center">
          <div id="qr-reader" style="width:100%;"></div>
          <div id="qr-status" style="color:#fdb04c;margin-top:0.5em;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Detail Modal -->
  <div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Item Details</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="detailBody"></div>
      </div>
    </div>
  </div>

  <!-- Image Modal -->
  <div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content text-center">
        <div class="modal-header">
          <h5 class="modal-title">Full Image</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <img src="" id="modalImg" class="enlarged-img" alt="Enlarged image">
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Modal -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit Confirm Item</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">INV Number</label>
            <input type="text" class="form-control" id="editInvNumber" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Action</label>
            <select class="form-select" id="editAction" onchange="updateEditInputField()">
              <option value="">Select Action</option>
              <option value="complete">Complete</option>
              <option value="return">Return</option>
              <option value="change">Change Items</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Input Value</label>
            <div id="editInputContainer"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-warning" onclick="saveEditModal()">✔ Update</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    let allData = [];
    let confirmDataMap = {};
    let qrScanner = null;
    let topInvNumber = null;
    let currentEditRowIdx = null;
    let displayed = [];

    function fetchAllItems() {
      loadSavedConfirmDataFirst()
        .then(() => {
          return fetch(API_GET_ALL_ITEMS)
            .then(res => res.json())
            .then(data => {
              allData = data;
              mapConfirmDataToItems();
              fillDeliveryOptions();
              filterAndRender();
            });
        })
        .catch(err => {
          console.error('Error:', err);
          document.querySelector('#allItemsTable tbody').innerHTML =
            `<tr><td colspan="15" style="color:#f77;">Failed to load data</td></tr>`;
        });
    }

    function loadSavedConfirmDataFirst() {
      return fetch(API_GET_CONFIRM_DATA)
        .then(res => res.json())
        .then(confirmData => {
          confirmDataMap = {};
          confirmData.forEach(item => {
            confirmDataMap[item.inv_number] = {
              action: item.action,
              input_value: item.input_value,
              created_at: item.created_at
            };
          });
          console.log('✓ Confirm data loaded');
        })
        .catch(err => {
          console.log('No saved confirm data');
          confirmDataMap = {};
        });
    }

    function mapConfirmDataToItems() {
      allData.forEach(item => {
        if (confirmDataMap[item.inv_number]) {
          item.confirm_action = confirmDataMap[item.inv_number].action;
          item.confirm_input = confirmDataMap[item.inv_number].input_value;
          item.confirm_date = confirmDataMap[item.inv_number].created_at;
        }
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
      topInvNumber = (val && allData.some(x => x.inv_number == val)) ? val : null;
      filterAndRender();
    }

    function filterAndRender() {
      const fromDateVal = document.getElementById('fromDate').value;
      const toDateVal = document.getElementById('toDate').value;
      const deliveryFilter = document.getElementById('filterDelivery').value;
      const searchVal = document.getElementById('searchInput').value.trim().toLowerCase();
      const topRowsValue = document.getElementById('topRowsSelect').value;

      displayed = allData;

      // Filter by date range (inclusive)
      if (fromDateVal && toDateVal) {
        const fromDate = new Date(fromDateVal);
        const toDate = new Date(toDateVal);
        displayed = displayed.filter(x => {
          const d = new Date((x.date_prepare || '').split(' ')[0]);
          return d >= fromDate && d <= toDate;
        });
      } else if (fromDateVal) {
        const fromDate = new Date(fromDateVal);
        displayed = displayed.filter(x => {
          const d = new Date((x.date_prepare || '').split(' ')[0]);
          return d >= fromDate;
        });
      } else if (toDateVal) {
        const toDate = new Date(toDateVal);
        displayed = displayed.filter(x => {
          const d = new Date((x.date_prepare || '').split(' ')[0]);
          return d <= toDate;
        });
      }

      if (deliveryFilter)
        displayed = displayed.filter(x => (x.delivery_by || 'NO OUT') === deliveryFilter);

      if (searchVal)
        displayed = displayed.filter(x =>
          (x.inv_number || '').toString().toLowerCase().includes(searchVal) ||
          (x.phone_number || '').toString().toLowerCase().includes(searchVal) ||
          (x.delivery_by || '').toString().toLowerCase().includes(searchVal)
        );

      displayed.sort((a, b) => new Date(b.date_prepare) - new Date(a.date_prepare));

      if (topInvNumber) {
        const idx = displayed.findIndex(x => x.inv_number == topInvNumber);
        if (idx > 0) {
          const [item] = displayed.splice(idx, 1);
          displayed.unshift(item);
        }
      }

      if (topRowsValue !== 'all') {
        const limit = parseInt(topRowsValue, 10);
        displayed = displayed.slice(0, limit);
      }

      // Counting logic
      let countItems = displayed.length;
      let countComplete = displayed.filter(x => x.confirm_action === 'complete').length;
      let countReturn = displayed.filter(x => x.confirm_action === 'return').length;
      let countChange = displayed.filter(x => x.confirm_action === 'change').length;

      document.getElementById('countItems').textContent = countItems;
      document.getElementById('countComplete').textContent = countComplete;
      document.getElementById('countReturn').textContent = countReturn;
      document.getElementById('countChange').textContent = countChange;

      // Render table
      const tbody = document.querySelector('#allItemsTable tbody');
      tbody.innerHTML = "";
      let total = 0;

      displayed.forEach((item, idx) => {
        let amount = parseFloat(item.amount) || 0;
        total += amount;

        const isSaved = item.confirm_action ? true : false;
        const savedAction = item.confirm_action || '';
        const savedInput = item.confirm_input || '';

        tbody.innerHTML += `
          <tr ${isSaved ? 'style="background-color: #1a3a1a;"' : ''}>
            <td>${idx + 1}</td>
            <td>${item.inv_number || ''}</td>
            <td>${item.phone_number || ''}</td>
            <td>${item.paid_unpaid || ''}</td>
            <td>${item.amount || ''}</td>
            <td>${item.inv_photo_prepare ? `<img src="${item.inv_photo_prepare}" alt="INV" onclick="showImageModal('${item.inv_photo_prepare}')" />` : ''}</td>
            <td>${item.inv_photo_out ? `<img src="${item.inv_photo_out}" alt="INV Out" onclick="showImageModal('${item.inv_photo_out}')" />` : ''}</td>
            <td>${item.photo_full_prepare ? `<img src="${item.photo_full_prepare}" alt="Full Prepare" onclick="showImageModal('${item.photo_full_prepare}')" />` : ''}</td>
            <td>${item.photo_full_out ? `<img src="${item.photo_full_out}" alt="Full Out" onclick="showImageModal('${item.photo_full_out}')" />` : ''}</td>
            <td>${item.delivery_by || 'NO OUT'}</td>
            <td>${item.date_delivery_out || 'NO OUT'}</td>
            <td>${item.date_prepare || ''}</td>
            <td>
              <select class="form-select action-select" data-row="${idx}">
                <option value="">Action...</option>
                <option value="complete" ${savedAction === 'complete' ? 'selected' : ''}>Complete</option>
                <option value="return" ${savedAction === 'return' ? 'selected' : ''}>Return</option>
                <option value="change" ${savedAction === 'change' ? 'selected' : ''}>Change Items</option>
              </select>
            </td>
            <td>
              <div id="input-section-${idx}">
                ${isSaved ? `<span id="saved-text-${idx}" style="color:#28a745;">✓ ${savedInput}</span>` : ''}
              </div>
            </td>
            <td>
              ${isSaved ? `
                <button class="btn btn-sm btn-warning" onclick="openEditModal(${idx})">✏ Edit</button>
                <button class="btn btn-sm btn-delete" onclick="deleteConfirmItem(${idx})">🗑 Delete</button>
              ` : `
                <button class="btn btn-sm btn-success" onclick="saveRow(${idx})">Save</button>
              `}
            </td>
          </tr>
        `;
      });

      if (displayed.length === 0)
        tbody.innerHTML = `<tr><td colspan="15" style="color:#f77;">No data found</td></tr>`;

      document.getElementById('totalMoney').textContent = total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});

      // Action listeners
      document.querySelectorAll(".action-select").forEach(select => {
        select.addEventListener("change", function() {
          const rowIdx = this.dataset.row;
          const action = this.value;
          const isSaved = displayed[rowIdx].confirm_action ? true : false;
          if (!isSaved && action) {
            renderInputSection(rowIdx, action);
          }
        });
      });
    }

    function renderInputSection(rowIdx, action) {
      const inputDiv = document.getElementById(`input-section-${rowIdx}`);
      const savedSpan = document.getElementById(`saved-text-${rowIdx}`);
      if (savedSpan) savedSpan.style.display = 'none';
      inputDiv.innerHTML = "";

      if (action === "complete") {
        inputDiv.innerHTML = `
          <select class="form-select" id="payment-${rowIdx}">
            <option value="">Payment</option>
            <option value="ABABy S.CHIM">ABABy S.CHIM</option>
            <option value="ABABy P.RUOS">ABABy P.RUOS</option>
            <option value="AC">AC</option>
            <option value="Cash">Cash</option>
          </select>
        `;
      } else if (action === "change") {
        inputDiv.innerHTML = `
          <input type="text" class="form-control" id="change-text-${rowIdx}" placeholder="Change reason">
        `;
      }
    }

    function saveRow(rowIdx) {
  const action = document.querySelector(`.action-select[data-row="${rowIdx}"]`).value;
  let inputValue = null;
  if (!action) {
    alert('Please select an action!');
    return;
  }
  if (action === "complete") {
    inputValue = document.getElementById(`payment-${rowIdx}`)?.value;
    if (!inputValue) {
      alert('Please select a payment method!');
      return;
    }
  } else if (action === "change") {
    inputValue = document.getElementById(`change-text-${rowIdx}`)?.value;
    if (!inputValue) {
      alert('Please enter change reason!');
      return;
    }
  }
  const invNumber = displayed[rowIdx].inv_number;
  const itemIdxInAll = allData.findIndex(x => x.inv_number === invNumber);
  const username = localStorage.getItem("username") || "unknown";
  
  fetch(API_SAVE_CONFIRM, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      inv_number: invNumber,
      phone_number: displayed[rowIdx].phone_number,
      action: action,
      input_value: inputValue,
      user: username
    })
  })
  .then(res => res.json())
  .then(result => {
    if (result.success) {
      confirmDataMap[invNumber] = {action, input_value: inputValue, created_at: new Date().toISOString()};
      allData[itemIdxInAll].confirm_action = action;
      allData[itemIdxInAll].confirm_input = inputValue;
      allData[itemIdxInAll].confirm_date = new Date().toISOString();

      // Fix: if complete, set status to "paid" in backend and UI
      if (action === "complete") {
        const item = allData[itemIdxInAll];
        fetch("edit_prepare_items.php", {
          method: "POST",
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            inv: item.inv_number, // Use only inv if id not available
            phone: item.phone_number,
            status: "paid",
            amount: item.amount,
            user: username,
            datetime: new Date().toISOString()
          }),
        })

        .then(res => res.json())
        .then(data => {
          if (data.result === 'success') {
            allData[itemIdxInAll].paid_unpaid = "paid";
          } else {
            alert("Failed to update payment status: " + (data.message || "Unknown error"));
          }
          filterAndRender();
        })
        .catch(err => {
          alert("Error contacting backend: " + err);
          filterAndRender();
        });
      } else {
        filterAndRender();
      }
      alert('✓ Saved!');
    } else {
      alert('❌ ' + result.message);
    }
  })
  .catch(err => alert('❌ Error: ' + err));
}



    function openEditModal(rowIdx) {
      currentEditRowIdx = rowIdx;
      const item = displayed[rowIdx];
      document.getElementById('editInvNumber').value = item.inv_number;
      document.getElementById('editAction').value = item.confirm_action || '';
      updateEditInputField();
      new bootstrap.Modal(document.getElementById('editModal')).show();
    }

    function updateEditInputField() {
      const action = document.getElementById('editAction').value;
      const container = document.getElementById('editInputContainer');
      const item = displayed[currentEditRowIdx];
      container.innerHTML = "";
      if (action === "complete") {
        container.innerHTML = `
          <select class="form-select" id="editInputValue">
            <option value="">Select Payment</option>
            <option value="ABABy P.RUOS" ${item.confirm_input === 'ABABy P.RUOS' ? 'selected' : ''}>ABABy P.RUOS</option>
            <option value="ABABy S.CHIM" ${item.confirm_input === 'ABABy S.CHIM' ? 'selected' : ''}>ABABy S.CHIM</option>
            <option value="AC" ${item.confirm_input === 'AC' ? 'selected' : ''}>AC</option>
            <option value="Cash" ${item.confirm_input === 'Cash' ? 'selected' : ''}>Cash</option>
          </select>
        `;
      } else if (action === "change") {
        container.innerHTML = `
          <input type="text" class="form-control" id="editInputValue" placeholder="Change reason" value="${item.confirm_input || ''}">
        `;
      } else if (action === "return") {
        container.innerHTML = '<p style="color:#888;">No input required</p>';
      }
    }

    function saveEditModal() {
      const action = document.getElementById('editAction').value;
      let inputValue = null;
      if (!action) {
        alert('Select an action!');
        return;
      }
      if (action !== "return") {
        inputValue = document.getElementById('editInputValue')?.value;
        if (!inputValue) {
          alert('Fill in the input value!');
          return;
        }
      }
      const item = displayed[currentEditRowIdx];
      const invNumber = item.inv_number;
      const itemIdxInAll = allData.findIndex(x => x.inv_number === invNumber);
      const username = localStorage.getItem("username") || "unknown";
      fetch(API_SAVE_CONFIRM, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          inv_number: invNumber,
          phone_number: item.phone_number,
          action: action,
          input_value: inputValue,
          user: username
        })
      })
        .then(res => res.json())
        .then(result => {
          if (result.success) {
            confirmDataMap[invNumber] = {action, input_value: inputValue, created_at: item.confirm_date || new Date().toISOString()};
            allData[itemIdxInAll].confirm_action = action;
            allData[itemIdxInAll].confirm_input = inputValue;
            allData[itemIdxInAll].confirm_date = new Date().toISOString();
            alert('✓ Updated!');
            bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
            filterAndRender();
          } else {
            alert('❌ ' + result.message);
          }
        })
        .catch(err => alert('❌ Error: ' + err));
    }

    function deleteConfirmItem(rowIdx) {
      if (!confirm('Delete this confirmation?')) return;
      const invNumber = displayed[rowIdx].inv_number;
      const itemIdxInAll = allData.findIndex(x => x.inv_number === invNumber);

      fetch(API_DELETE_CONFIRM, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({inv_number: invNumber})
      })
        .then(res => res.json())
        .then(result => {
          if (result.success) {
            allData[itemIdxInAll].confirm_action = null;
            allData[itemIdxInAll].confirm_input = null;
            allData[itemIdxInAll].confirm_date = null;
            delete confirmDataMap[invNumber];
            alert('✓ Deleted!');
            filterAndRender();
          } else {
            alert('❌ ' + result.message);
          }
        })
        .catch(err => alert('❌ Error: ' + err));
    }

    function showQrModal() {
      new bootstrap.Modal(document.getElementById('qrModal')).show();
      startQrScan();
    }

    function startQrScan() {
      document.getElementById('qr-status').textContent = '';
      if (!qrScanner) {
        qrScanner = new Html5Qrcode("qr-reader");
      }
      qrScanner.start(
        {facingMode: "environment"},
        {fps: 10, qrbox: 250},
        qrCodeMessage => {
          qrScanner.stop();
          document.getElementById('qr-status').textContent = "QR: " + qrCodeMessage;
          showDetailByInv(qrCodeMessage.trim());
          bootstrap.Modal.getInstance(document.getElementById('qrModal')).hide();
        },
        () => {}
      );
    }

    function endQrScan() {
      if (qrScanner) qrScanner.stop();
    }

    function showDetailByInv(invNumber) {
      topInvNumber = invNumber;
      const match = allData.find(x => x.inv_number == invNumber);
      if (!match) {
        alert('INV not found');
        return;
      }
      let html = `<table class="table table-dark">
        <tr><th>INV</th><td>${match.inv_number}</td></tr>
        <tr><th>Phone</th><td>${match.phone_number || ''}</td></tr>
        <tr><th>Status</th><td>${match.paid_unpaid || ''}</td></tr>
        <tr><th>Amount</th><td>${match.amount || ''}</td></tr>
        <tr><th>Delivery</th><td>${match.delivery_by || 'NO OUT'}</td></tr>
        <tr><th>Date Delivery (out_items)</th><td>${match.date_delivery_out || 'NO OUT'}</td></tr>
        <tr><th>Date Prepare</th><td>${match.date_prepare || ''}</td></tr>
        <tr>
          <th>INV Photo (prepare_items)</th>
          <td>
            ${match.inv_photo_prepare ? `<img src="${match.inv_photo_prepare}" alt="INV" style="max-width:140px; border-radius:8px;" onclick="showImageModal('${match.inv_photo_prepare}')" />` : '—'}
          </td>
        </tr>
        <tr>
          <th>INV Photo (out_items)</th>
          <td>
            ${match.inv_photo_out ? `<img src="${match.inv_photo_out}" alt="INV Out" style="max-width:140px; border-radius:8px;" onclick="showImageModal('${match.inv_photo_out}')" />` : '—'}
          </td>
        </tr>
        <tr>
          <th>Photo Full (prepare_items)</th>
          <td>
            ${match.photo_full_prepare ? `<img src="${match.photo_full_prepare}" alt="Full Prepare" style="max-width:140px; border-radius:8px;" onclick="showImageModal('${match.photo_full_prepare}')" />` : '—'}
          </td>
        </tr>
        <tr>
          <th>Photo Full (out_items)</th>
          <td>
            ${match.photo_full_out ? `<img src="${match.photo_full_out}" alt="Full Out" style="max-width:140px; border-radius:8px;" onclick="showImageModal('${match.photo_full_out}')" />` : '—'}
          </td>
        </tr>
        <tr>
          <th>Confirmation Status</th>
          <td>
            ${match.confirm_action ? `<span style="color:#28a745;">${match.confirm_action}</span>` : '<span style="color:#dc3545;">Not confirmed</span>'}
            ${match.confirm_input ? `<br><b>Input:</b> ${match.confirm_input}` : ''}
            ${match.confirm_date ? `<br><small style="color:#bbb;">Confirmed at: ${match.confirm_date}</small>` : ''}
          </td>
        </tr>
      </table>`;
      document.getElementById('detailBody').innerHTML = html;
      new bootstrap.Modal(document.getElementById('detailModal')).show();
      filterAndRender();
    }

    function showImageModal(url) {
      document.getElementById('modalImg').src = url;
      new bootstrap.Modal(document.getElementById('imgModal')).show();
    }

    fetchAllItems();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


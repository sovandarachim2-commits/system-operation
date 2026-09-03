<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Prepare Items History</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Bootstrap v5 CSS (modern UI) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="api_config.php"></script>
  <style>
    body {background:#222; color:#fdb04c; font-family:sans-serif; margin:0;}
    table {width:100%; border-collapse:collapse; margin:2em 0;}
    th, td {padding:10px; border:1px solid #444; text-align:center;}
    th {background:#232323;}
    td {background:#292929;}
    img {max-width:100px; max-height:80px; border-radius:7px;}
    .action-btn {margin: 0 2px; font-size:1.1em;}
    #photoModal {
      display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
      background: rgba(24,24,24,0.98); z-index: 99999; justify-content: center; align-items: center;
    }
    #modalImg { max-width: 90vw; max-height: 90vh; border-radius: 15px; box-shadow: 0 2px 24px #000;
      display: block; margin: auto;}
    .set-name-badge {
      display: inline-block;
      margin: 4px 6px 0 0;
      padding: 4px 10px;
      border-radius: 12px;
      background: #2f7f4f;
      color: #fff;
      font-weight: 700;
      font-size: 0.92em;
    }
  </style>
  <script src="api_config.php"></script>
</head>
<body>
  <h2 class="text-center mt-3">Prepare Items History</h2>
  <div class="container">

    <!-- Filter/search/count row -->
    <div class="row mb-3">
      <div class="col-auto d-flex align-items-center">
        <label for="fromDate" class="me-1 mb-0">From:</label>
        <input type="date" class="form-control me-2" style="width:auto;min-width:120px;" id="fromDate" onchange="renderTable()">
        <label for="toDate" class="me-1 mb-0">To:</label>
        <input type="date" class="form-control me-2" style="width:auto;min-width:120px;" id="toDate" onchange="renderTable()">
      </div>
      <div class="col-auto d-flex align-items-center">
        <input type="text" class="form-control" id="searchInput" placeholder="Search barcode, phone, user, status..." oninput="renderTable()" style="min-width:200px;">
      </div>
      <div class="col-auto d-flex align-items-center">
        <select class="form-select" id="topRowsSelect" onchange="renderTable()" style="width:auto;min-width:115px;">
          <option value="100" selected>Show Top 100</option>
          <option value="1000">Show Top 1000</option>
          <option value="all">Show All</option>
        </select>
      </div>
      <div class="col-auto d-flex align-items-center" style="color:#ffb700;font-weight:bold;">
        Total Items: <span id="countItems" class="ms-1">0</span>
      </div>
    </div>
    <div id="setNameTotals" class="mb-3"></div>
    
    <table id="entriesTable" class="table table-dark table-borderless align-middle">
      <thead>
        <tr>
          <th>No</th>
          <th>Barcode</th>
          <th>Phone</th>
          <th>Status</th>
          <th>Amount</th>
          <th>INV Photo</th>
          <th>Full Photo</th>
          <th>User</th>
          <th>Date/Time</th>
          <th>Set QR</th>
          <th>Sub Sets</th>
          <th>Set Name</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <!-- Data loads here -->
      </tbody>
    </table>
  </div>

  <!-- Photo Modal -->
  <div id="photoModal">
    <img id="modalImg" src="">
  </div>

  <!-- Edit Modal (Bootstrap) -->
  <div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="background:#232323;color:#fdb04c;">
        <div class="modal-header">
          <h5 class="modal-title">Edit Entry</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="editForm">
            <input type="hidden" id="edit-id">
            <div class="mb-3">
              <label class="form-label">Barcode</label>
              <input type="text" class="form-control" id="edit-inv" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Phone</label>
              <input type="text" class="form-control" id="edit-phone" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Status</label>
              <select class="form-select" id="edit-status" required>
                <option value="">- Please select -</option>
                <option value="Paid">Paid</option>
                <option value="Unpaid">Unpaid</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Amount</label>
              <input type="number" class="form-control" id="edit-amount" required>
            </div>
            <div class="mb-3">
              <label class="form-label">User</label>
              <input type="text" class="form-control" id="edit-user" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Date/Time</label>
              <input type="text" class="form-control" id="edit-datetime" required>
            </div>
            <!-- You can add file/image editing here if needed -->
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-success" id="saveEditBtn">Save Changes</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Modal for photo
    function showPhoto(src) {
      document.getElementById('modalImg').src = src;
      document.getElementById('photoModal').style.display = 'flex';
    }
    document.getElementById('photoModal').onclick = function() {
      this.style.display = 'none';
    };

    let entriesData = [];
    const tbody = document.querySelector("#entriesTable tbody");

    function fetchAndRender() {
      fetch(API_GET_PREPARE_ITEMS)
        .then(res => res.json())
        .then(data => {
          entriesData = data;
          renderTable();
        })
        .catch(err => {
          tbody.innerHTML = `<tr><td colspan="13" style="color:#f77;">Failed to load data</td></tr>`;
        });
    }

    function formatDateTime(dateStr) {
      if (!dateStr) return '';
      const date = new Date(dateStr);
      const pad = x => x.toString().padStart(2, '0');
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    }

    function updateSetNameTotals(data) {
      const totalsEl = document.getElementById('setNameTotals');
      if (!totalsEl) return;

      const setNameCount = {};
      let totalAllSet = 0;
      data.forEach(item => {
        const key = (item.set_name || '').trim();
        const subSetNameArr = (item.sub_set_names && Array.isArray(item.sub_set_names))
          ? [...new Set(item.sub_set_names.filter(name => (name || '').trim() !== ''))]
          : [];
        const perRowSetNames = [];

        if (key) {
          perRowSetNames.push(key);
        }
        perRowSetNames.push(...subSetNameArr);

        perRowSetNames.forEach((setName) => {
          setNameCount[setName] = (setNameCount[setName] || 0) + 1;
        });

        totalAllSet += perRowSetNames.length;
      });

      const entries = Object.entries(setNameCount).sort((a, b) => b[1] - a[1]);
      if (entries.length === 0) {
        totalsEl.innerHTML = '<span style="color:#aaa;">No set names in current filter.</span>';
        return;
      }

      const totalBadge = `<span class="set-name-badge" style="background:#1f4db8;">Total All Set: ${totalAllSet}</span>`;
      const setBadges = entries
        .map(([name, count]) => `<span class="set-name-badge">${name}: ${count}</span>`)
        .join('');

      totalsEl.innerHTML = totalBadge + setBadges;
    }

    function renderTable() {
      tbody.innerHTML = "";
      let filtered = entriesData;

      // --- Date filter ---
      const fromDate = document.getElementById("fromDate").value;
      const toDate = document.getElementById("toDate").value;

      if (fromDate) {
        filtered = filtered.filter(item => {
          if (!item.datetime) return false;
          return new Date(item.datetime) >= new Date(fromDate);
        });
      }
      if (toDate) {
        filtered = filtered.filter(item => {
          if (!item.datetime) return false;
          // +1 day to include the toDate itself
          const dt = new Date(item.datetime);
          const toDt = new Date(toDate);
          toDt.setDate(toDt.getDate() + 1);
          return dt < toDt;
        });
      }

      // --- Search filter ---
      const search = document.getElementById("searchInput").value.trim().toLowerCase();
      if (search) {
        filtered = filtered.filter(item =>
          (item.inv || "").toLowerCase().includes(search) ||
          (item.phone || "").toLowerCase().includes(search) ||
          (item.status || "").toLowerCase().includes(search) ||
          (item.user || "").toLowerCase().includes(search)
        );
      }

      // --- Sort newest first ---
      filtered.sort((a, b) => new Date(a.datetime) - new Date(b.datetime));

      // --- Top Row limiter ---
      const topRowsValue = document.getElementById("topRowsSelect").value;
      let displayed = filtered;
      if (topRowsValue !== "all") {
        displayed = filtered.slice(0, parseInt(topRowsValue, 10));
      }

      // --- Count display ---
      document.getElementById('countItems').textContent = filtered.length;
      updateSetNameTotals(filtered);

      displayed.forEach((item, idx) => {
        // Sub Sets array
        let subNamesArr = [];
        if (item.sub_names && Array.isArray(item.sub_names)) {
          subNamesArr = item.sub_names;
        } else if (item.sub_name_json && item.sub_name_json !== 'null' && item.sub_name_json !== '') {
          try {
            subNamesArr = JSON.parse(item.sub_name_json);
          } catch (e) { subNamesArr = []; }
        }

        const setNameHtml = item.set_name
          ? `<div>${item.set_name}</div>`
          : `<div><span style="color:#aaa;">-</span></div>`;

        const subSetNameArr = (item.sub_set_names && Array.isArray(item.sub_set_names))
          ? item.sub_set_names
          : [];

        const setNameWithSubsetHtml = subSetNameArr.length
          ? `${setNameHtml}<div style="margin-top:6px; font-size:0.92em; color:#f9c27a;">${subSetNameArr.map((name, i) => `<div>${i + 1}. ${name}</div>`).join("")}</div>`
          : setNameHtml;

        tbody.innerHTML += `
          <tr data-id="${item.id}">
            <td>${idx + 1}</td>
            <td>${item.inv}</td>
            <td>${item.phone}</td>
            <td>${item.status}</td>
            <td>${item.amount}</td>
            <td>${item.inv_photo ? `<img src="${item.inv_photo}" onclick="showPhoto(this.src)" style="cursor:pointer;">` : ''}</td>
            <td>${item.full_photo ? `<img src="${item.full_photo}" onclick="showPhoto(this.src)" style="cursor:pointer;">` : ''}</td>
            <td>${item.user}</td>
            <td>${item.datetime ? formatDateTime(item.datetime) : ''}</td>
            <td>${item.set_qr ? `<span style="color:#85ffa3;">${item.set_qr}</span>` : `<span style="color:#aaa;">-</span>`}</td>
            <td>
              ${
                subNamesArr.length
                  ? `<span class="subset-label">Sub Set:</span> <div class="subset-list">${subNamesArr.map((n, i) => `<div>${i+1}. ${n}</div>`).join("")}</div>`
                  : `<span style="color:#aaa;">No sub set</span>`
              }
            </td>
            <td>${setNameWithSubsetHtml}</td>
            <td>
              <button class="btn btn-warning btn-sm action-btn" onclick="openEditModal('${item.id}')">Edit</button>
              <button class="btn btn-danger btn-sm action-btn" onclick="deleteItem('${item.id}')">Delete</button>
            </td>
          </tr>`;
      });

      if (displayed.length === 0) {
        tbody.innerHTML = `<tr><td colspan="13" style="color:#f77;">No data found</td></tr>`;
      }
    }

    // ----- Edit -----
    window.openEditModal = function(id) {
      const item = entriesData.find(i => i.id == id);
      document.getElementById('edit-id').value = item.id;
      document.getElementById('edit-inv').value = item.inv;
      document.getElementById('edit-phone').value = item.phone;
      document.getElementById('edit-status').value = item.status;
      document.getElementById('edit-amount').value = item.amount;
      document.getElementById('edit-user').value = item.user;
      document.getElementById('edit-datetime').value = item.datetime;

      const editModal = new bootstrap.Modal(document.getElementById('editModal'));
      editModal.show();
    };

    // Save Edit
    document.getElementById('saveEditBtn').onclick = function() {
      const id = document.getElementById('edit-id').value;
      const payload = {
        id: id,
        inv: document.getElementById('edit-inv').value,
        phone: document.getElementById('edit-phone').value,
        status: document.getElementById('edit-status').value,
        amount: document.getElementById('edit-amount').value,
        user: document.getElementById('edit-user').value,
        datetime: document.getElementById('edit-datetime').value,
      };
      fetch(`${API_EDIT_PREPARE_ITEMS}?id=${id}`, {   // <-- backticks for string interpolation
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(res => {
        if(res.result === 'success'){
          const idx = entriesData.findIndex(i => i.id == id);
          entriesData[idx] = {...entriesData[idx], ...payload};
          renderTable();
          bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
        } else {
          alert('Edit failed: ' + (res.message||'Unknown error'));
        }
      })
      .catch(e => alert('Network/API error: '+e));
    };

    // ----- Delete -----
    window.deleteItem = function(id) {
      if (!confirm('Are you sure you want to delete this entry?')) return;
      fetch(`${API_DELETE_PREPARE_ITEMS}?id=${id}`, {   // <-- backticks for string interpolation
        method: 'POST',
      })
      .then(res => res.json())
      .then(res => {
        if(res.result === 'success'){
          entriesData = entriesData.filter(i => i.id != id);
          renderTable();
        } else {
          alert('Delete failed: ' + (res.message||'Unknown error'));
        }
      })
      .catch(e => alert('Network/API error: '+e));
    };

    fetchAndRender();
  </script>
</body>
</html>

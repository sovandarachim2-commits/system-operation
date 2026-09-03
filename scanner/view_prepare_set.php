<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Prepare Set History</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {background:#222; color:#fdb04c; font-family:sans-serif; margin:0;}
    table {width:100%; border-collapse:collapse; margin:2em 0;}
    th, td {padding:10px; border:1px solid #444; text-align:center;}
    th {background:#232323;}
    td {background:#292929;}
    img {max-width:100px; max-height:80px; border-radius:7px;}
    .action-btn { margin: 0 2px; font-size:1.1em;}
    #photoModal {
      display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
      background: rgba(24,24,24,0.98); z-index: 99999; justify-content: center; align-items: center;
    }
    #modalImg { max-width: 90vw; max-height: 90vh; border-radius: 15px; box-shadow: 0 2px 24px #000;
      display: block; margin: auto;}
    .set-badge {background: #282; color: #fff; border-radius: 8px; padding: 2px 8px; margin-right:4px; font-weight:bold;}
  </style>
  <script src="api_config.php"></script>
</head>
<body>
  <h2 class="text-center mt-3">Prepare Set History</h2>
  <div class="container my-3">
    <div class="row g-2 align-items-center">
      <div class="col-auto">
        <label for="filterDate" class="col-form-label">Date:</label>
      </div>
      <div class="col-auto">
        <input type="date" id="filterDate" class="form-control" />
      </div>
      <div class="col-auto">
        <label for="filterSet" class="col-form-label">Set:</label>
      </div>
      <div class="col-auto">
        <select id="filterSet" class="form-select">
          <option value="">All Sets</option>
        </select>
      </div>
      <div class="col-auto">
        <select class="form-select" id="topRowsSelect" onchange="renderTable()" style="width:auto;min-width:115px;">
          <option value="100" selected>Show Top 100</option>
          <option value="500">Show Top 500</option>
          <option value="all">Show All</option>
        </select>
      </div>
      <div class="col-auto">
        <input type="text" id="searchInput" class="form-control" placeholder="Search..." />
      </div>
      <div class="col-auto">
        <strong>Total Items:</strong> <span id="totalItems">0</span>
      </div>
    </div>
    <div id="setTotals" class="mt-2"></div>
  </div>
  <div class="container">
    <table id="prepareSetTable" class="table table-dark table-borderless align-middle">
      <thead>
        <tr>
          <th>No</th>
          <th>INV</th>
          <th>Set</th>
          <th>Photo</th>
          <th>User</th>
          <th>Date/Time</th>
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
          <h5 class="modal-title">Edit Prepare Set</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="editForm">
            <input type="hidden" id="edit-id">
            <div class="mb-3">
              <label class="form-label">INV</label>
              <input type="text" class="form-control" id="edit-inv" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Set</label>
              <input type="text" class="form-control" id="edit-set" required>
            </div>
            <div class="mb-3">
              <label class="form-label">User</label>
              <input type="text" class="form-control" id="edit-user" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Date/Time</label>
              <input type="text" class="form-control" id="edit-date_time" required>
            </div>
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
    let prepareSetData = [];
    const tbody = document.querySelector("#prepareSetTable tbody");

    // Fill SET options for filter
    function fillSetOptions() {
      const select = document.getElementById('filterSet');
      const uniqueSets = [...new Set(prepareSetData.map(x => x.set).filter(Boolean))];
      select.innerHTML = `<option value="">All Sets</option>`;
      uniqueSets.forEach(name => { select.innerHTML += `<option value="${name}">${name}</option>`; });
    }

    // Calculate and show totals for each SET type
    function showSetTotals(filteredData) {
      const totals = {};
      filteredData.forEach(item => {
        if (item.set) {
          totals[item.set] = (totals[item.set] || 0) + 1;
        }
      });
      let html = '';
      Object.keys(totals).forEach(setType => {
        html += `<span class="set-badge">${setType}: ${totals[setType]}</span>`;
      });
      document.getElementById('setTotals').innerHTML = html;
    }

    // Fetch and render table
    function fetchAndRender() {
      fetch(API_GET_PREPARE_SET)
        .then(res => res.json())
        .then(data => {
          prepareSetData = data;
          fillSetOptions();
          renderTable();
        })
        .catch(err => {
          tbody.innerHTML =
            `<tr><td colspan="7" style="color:#f77;">Failed to load data</td></tr>`;
        });
    }

    // Filtering logic
    function filterData() {
      const dateValue = document.getElementById('filterDate').value;
      const setValue = document.getElementById('filterSet').value;
      const searchValue = document.getElementById('searchInput') ? document.getElementById('searchInput').value.trim().toLowerCase() : "";

      return prepareSetData.filter(item => {
        if (dateValue) {
          const dateStr = item.date_time ? new Date(item.date_time).toISOString().slice(0,10) : '';
          if (dateStr !== dateValue) return false;
        }
        if (setValue && item.set !== setValue) return false;
        if (searchValue) {
          const fields = [
            item.inv, item.set, item.user, item.date_time, item.id
          ].map(v => (v + "").toLowerCase());
          if (!fields.some(f => f.includes(searchValue))) return false;
        }
        return true;
      });
    }

    // Render table
    function renderTable() {
      const filteredData = filterData();
      document.getElementById('totalItems').textContent = filteredData.length;
      showSetTotals(filteredData);

      tbody.innerHTML = "";
      // Oldest first
      filteredData.sort((a, b) => new Date(a.date_time) - new Date(b.date_time));

      // Row limiting
      const topRowsValue = document.getElementById("topRowsSelect").value;
      let displayed = filteredData;
      if (topRowsValue !== "all") {
        displayed = filteredData.slice(0, parseInt(topRowsValue, 10));
      }

      displayed.forEach((item, idx) => {
        tbody.innerHTML += `
          <tr data-id="${item.id}">
            <td>${idx + 1}</td>
            <td>${item.inv}</td>
            <td>${item.set}</td>
            <td>${item.photo ? `<img src="${item.photo}" onclick="showPhoto(this.src)" style="cursor:pointer;">` : ''}</td>
            <td>${item.user}</td>
            <td>${item.date_time}</td>
            <td>
              <button class="btn btn-warning btn-sm action-btn" onclick="openEditModal('${item.id}')">Edit</button>
              <button class="btn btn-danger btn-sm action-btn" onclick="deleteItem('${item.id}')">Delete</button>
            </td>
          </tr>`;
      });
    }

    document.getElementById('filterDate').addEventListener('change', renderTable);
    document.getElementById('filterSet').addEventListener('change', renderTable);
    document.getElementById('searchInput').addEventListener('input', renderTable);

    window.openEditModal = function(id) {
      const item = prepareSetData.find(i => i.id == id);
      document.getElementById('edit-id').value = item.id;
      document.getElementById('edit-inv').value = item.inv;
      document.getElementById('edit-set').value = item.set;
      document.getElementById('edit-user').value = item.user;
      document.getElementById('edit-date_time').value = item.date_time;
      const editModal = new bootstrap.Modal(document.getElementById('editModal'));
      editModal.show();
    };

    document.getElementById('saveEditBtn').onclick = function() {
      const id = document.getElementById('edit-id').value;
      const payload = {
        id: id,
        inv: document.getElementById('edit-inv').value,
        set: document.getElementById('edit-set').value,
        user: document.getElementById('edit-user').value,
        date_time: document.getElementById('edit-date_time').value
      };
      fetch(`${API_EDIT_PREPARE_SET}?id=${id}`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(res => {
        if(res.result === 'success'){
          const idx = prepareSetData.findIndex(i => i.id == id);
          prepareSetData[idx] = {...prepareSetData[idx], ...payload};
          renderTable();
          bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
        } else {
          alert('Edit failed: ' + (res.message||'Unknown error'));
        }
      })
      .catch(e => alert('Network/API error: '+e));
    };

    window.deleteItem = function(id) {
      if (!confirm('Are you sure you want to delete this item?')) return;
      fetch(`${API_DELETE_PREPARE_SET}?id=${id}`, {
        method: 'POST',
      })
      .then(res => res.json())
      .then(res => {
        if(res.result === 'success'){
          prepareSetData = prepareSetData.filter(i => i.id != id);
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

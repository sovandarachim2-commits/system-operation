<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Return Items History</title>
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
  <h2 class="text-center mt-3">Return Items History</h2>
  <div class="container my-3">
    <div class="row g-2 align-items-center">
      <div class="col-auto">
        <label for="filterFromDate" class="col-form-label">From Date:</label>
      </div>
      <div class="col-auto">
        <input type="date" id="filterFromDate" class="form-control"/>
      </div>
      <div class="col-auto">
        <label for="filterToDate" class="col-form-label">To Date:</label>
      </div>
      <div class="col-auto">
        <input type="date" id="filterToDate" class="form-control"/>
      </div>
      <div class="col-auto">
        <label for="filterDeliveryBy" class="col-form-label">Delivery By:</label>
      </div>
      <div class="col-auto">
        <select id="filterDeliveryBy" class="form-select">
          <option value="">All Delivery</option>
        </select>
      </div>
      <div class="col-auto">
        <select class="form-select" id="topRowsSelect" style="width:auto; min-width:115px;">
          <option value="100" selected>Show Top 100</option>
          <option value="500">Show Top 500</option>
          <option value="all">Show All</option>
        </select>
      </div>
      <div class="col-auto">
        <input type="text" id="searchInput" class="form-control" placeholder="Search..." />
      </div>
      <div class="col-auto">
        <strong>Total Deliveries:</strong> <span id="totalDeliveries">0</span>
      </div>
      <div class="col-auto">
        <strong>Total Sets:</strong> <span id="totalSets">0</span>
      </div>
    </div>
    <div id="setNameTotals" class="mt-2"></div>
  </div>
  <div class="container">
    <table id="returnItemsTable" class="table table-dark table-borderless align-middle">
      <thead>
        <tr>
          <th>No</th>
          <th>INV</th>
          <th>Set Name</th>
          <th>Set Count</th>
          <th>Delivery By</th>
          <th>Reason</th>
          <th>INV Photo</th>
          <th>Full Photo</th>
          <th>Username</th>
          <th>Date/Time</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody><!-- Data loads here --></tbody>
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
          <h5 class="modal-title">Edit Return Item</h5>
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
              <label class="form-label">Delivery By</label>
              <select class="form-select" id="edit-delivery_by" required>
                <option value="VET">VET</option>
                <option value="ក្រុមហ៊ុន">ក្រុមហ៊ុន</option>
                <option value="J&T">J&T</option>
                <option value="hou Express">hou Express</option>
                <option value="Jalat">Jalat</option>
                <option value="Banhjol">Banhjol</option>
                <option value="Nith Delivery">Nith Delivery</option>
                <option value="Yum Delivery">Yum Delivery</option>
                <option value="អត់ទាន់ចេញ">អត់ទាន់ចេញ</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Reason</label>
              <input type="text" class="form-control" id="edit-reason" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Username</label>
              <input type="text" class="form-control" id="edit-username" required readonly>
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
    function showPhoto(src) {
      document.getElementById('modalImg').src = src;
      document.getElementById('photoModal').style.display = 'flex';
    }
    document.getElementById('photoModal').onclick = function() {
      this.style.display = 'none';
    };

    let returnItemsData = [];
    const tbody = document.querySelector("#returnItemsTable tbody");

    function fillDeliveryOptions() {
      const select = document.getElementById('filterDeliveryBy');
      const unique = [...new Set(returnItemsData.map(x => x.delivery_by).filter(Boolean))];
      select.innerHTML = `<option value="">All Delivery</option>`;
      unique.forEach(name => { select.innerHTML += `<option value="${name}">${name}</option>`; });
    }

    function fetchAndRender() {
      fetch(API_GET_RETURN_ITEMS)
        .then(res => res.json())
        .then(data => {
          returnItemsData = data;
          fillDeliveryOptions();
          renderTable();
        })
        .catch(err => {
          tbody.innerHTML =
            `<tr><td colspan="11" style="color:#f77;">Failed to load data</td></tr>`;
        });
    }

    function filterData() {
      const fromDateValue = document.getElementById('filterFromDate').value;
      const toDateValue = document.getElementById('filterToDate').value;
      const deliveryByValue = document.getElementById('filterDeliveryBy').value;
      const searchValue = document.getElementById('searchInput') ? document.getElementById('searchInput').value.trim().toLowerCase() : "";

      return returnItemsData.filter(item => {
        // Date range filtering
        if (fromDateValue || toDateValue) {
          const itemDate = item.date_time ? new Date(item.date_time) : null;
          if (!itemDate) return false;
          
          const itemDateStr = itemDate.toISOString().slice(0,10);
          
          if (fromDateValue && itemDateStr < fromDateValue) return false;
          if (toDateValue && itemDateStr > toDateValue) return false;
        }
        
        if (deliveryByValue && item.delivery_by !== deliveryByValue) return false;
        if (searchValue) {
          const fields = [
            item.inv, item.delivery_by, item.reason, item.username, item.date_time, item.id
          ].map(v => (v + "").toLowerCase());
          if (!fields.some(f => f.includes(searchValue))) return false;
        }
        return true;
      });
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
      const filteredData = filterData();
      document.getElementById('totalDeliveries').textContent = filteredData.length;
      document.getElementById('totalSets').textContent = filteredData.reduce((sum, item) => {
        const mainSetCount = (item.set_name || '').trim() !== '' ? 1 : 0;
        const subSetNameArr = (item.sub_set_names && Array.isArray(item.sub_set_names))
          ? [...new Set(item.sub_set_names.filter(name => (name || '').trim() !== ''))]
          : [];
        return sum + mainSetCount + subSetNameArr.length;
      }, 0);
      updateSetNameTotals(filteredData);
      tbody.innerHTML = "";
      // Sort oldest first
      filteredData.sort((a, b) => new Date(a.date_time) - new Date(b.date_time));
      const topRowsValue = document.getElementById("topRowsSelect").value;
      let displayed = filteredData;
      if (topRowsValue !== "all") {
        displayed = filteredData.slice(0, parseInt(topRowsValue, 10));
      }
      displayed.forEach((item, idx) => {
        const subSetNameArr = (item.sub_set_names && Array.isArray(item.sub_set_names))
          ? [...new Set(item.sub_set_names.filter(name => (name || '').trim() !== ''))]
          : [];
        const setNameHtml = item.set_name ? `<div>${item.set_name}</div>` : `<div>-</div>`;
        const setNameWithSubsetHtml = subSetNameArr.length
          ? `${setNameHtml}<div style="margin-top:6px; font-size:0.92em; color:#f9c27a;">${subSetNameArr.map((name, i) => `<div>${i + 1}. ${name}</div>`).join("")}</div>`
          : setNameHtml;
        const rowSetCount = ((item.set_name || '').trim() !== '' ? 1 : 0) + subSetNameArr.length;

        tbody.innerHTML += `
          <tr data-id="${item.id}">
            <td>${idx + 1}</td>
            <td>${item.inv}</td>
            <td>${setNameWithSubsetHtml}</td>
            <td><span class="badge bg-success">${rowSetCount}</span></td>
            <td>${item.delivery_by}</td>
            <td>${item.reason}</td>
            <td>${item.inv_photo ? `<img src="${item.inv_photo}" onclick="showPhoto(this.src)" style="cursor:pointer;">` : ''}</td>
            <td>${item.full_photo ? `<img src="${item.full_photo}" onclick="showPhoto(this.src)" style="cursor:pointer;">` : ''}</td>
            <td>${item.username}</td>
            <td>${item.date_time}</td>
            <td>
              <button class="btn btn-warning btn-sm action-btn" onclick="openEditModal('${item.id}')">Edit</button>
              <button class="btn btn-danger btn-sm action-btn" onclick="deleteItem('${item.id}')">Delete</button>
            </td>
          </tr>`;
      });
    }

    document.getElementById('filterFromDate').addEventListener('change', renderTable);
    document.getElementById('filterToDate').addEventListener('change', renderTable);
    document.getElementById('filterDeliveryBy').addEventListener('change', renderTable);
    document.getElementById('searchInput').addEventListener('input', renderTable);
    document.getElementById('topRowsSelect').addEventListener('change', renderTable);

    window.openEditModal = function(id) {
      const item = returnItemsData.find(i => i.id == id);
      if (!item) { alert('Item not found'); return; }
      document.getElementById('edit-id').value = item.id;
      document.getElementById('edit-inv').value = item.inv;
      document.getElementById('edit-delivery_by').value = item.delivery_by;
      document.getElementById('edit-reason').value = item.reason;
      document.getElementById('edit-username').value = item.username;
      document.getElementById('edit-date_time').value = item.date_time;
      const editModal = new bootstrap.Modal(document.getElementById('editModal'));
      editModal.show();
    };

    document.getElementById('saveEditBtn').onclick = function() {
      const id = document.getElementById('edit-id').value;
      const payload = {
        id: id,
        inv: document.getElementById('edit-inv').value,
        delivery_by: document.getElementById('edit-delivery_by').value,
        reason: document.getElementById('edit-reason').value,
        username: document.getElementById('edit-username').value,
        date_time: document.getElementById('edit-date_time').value,
      };
      fetch(API_EDIT_RETURN_ITEMS, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(res => {
        if(res.result === 'success'){
          const idx = returnItemsData.findIndex(i => i.id == id);
          returnItemsData[idx] = {...returnItemsData[idx], ...payload};
          renderTable();
          bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
          alert('Item updated successfully!');
        } else {
          alert('Edit failed: ' + (res.message||'Unknown error'));
        }
      })
      .catch(e => alert('Network/API error: '+e));
    };

    window.deleteItem = function(id) {
      if (!confirm('Are you sure you want to delete this item?')) return;
      fetch(API_DELETE_RETURN_ITEMS + '?id=' + id, {
        method: 'POST',
      })
      .then(res => res.json())
      .then(res => {
        if(res.result === 'success'){
          returnItemsData = returnItemsData.filter(i => i.id != id);
          renderTable();
          alert('Item deleted successfully!');
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

<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Out Items History</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <!-- Bootstrap v5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <script src="api_config.php"></script>
  <style>
    body {background:#222; color:#fdb04c; font-family:sans-serif; margin:0;}
    table {width:100%; border-collapse:collapse; margin:2em 0;}
    th,td {padding:10px; border:1px solid #444; text-align:center;}
    th {background:#232323;} td {background:#292929;}
    img {max-width:100px; max-height:80px; border-radius:7px;}
    .action-btn {margin:0 2px; font-size:1.1em;}
    #photoModal {
      display:none; position:fixed; top:0; left:0; width:100vw; height:100vh;
      background:rgba(24,24,24,0.98); z-index:99999; justify-content:center; align-items:center;
    }
    #modalImg {max-width:90vw; max-height:90vh; border-radius:15px; box-shadow:0 2px 24px #000; display:block; margin:auto;}
  </style>
</head>
<body>
  <h2 class="text-center mt-3">Out Items History</h2>
  <div class="container my-3">
    <div class="row g-2 align-items-center">
      <div class="col-auto">
        <label for="filterDate" class="col-form-label">Date</label>
      </div>
      <div class="col-auto">
        <input type="date" id="filterDate" class="form-control" />
      </div>
      <div class="col-auto">
        <label for="filterDeliveryBy" class="col-form-label">Delivery By</label>
      </div>
      <div class="col-auto">
        <select id="filterDeliveryBy" class="form-select">
          <option value="">All Delivery</option>
        </select>
      </div>
      <div class="col-auto">
        <!-- Top Rows Dropdown -->
        <select class="form-select" id="topRowsSelect" onchange="renderTable()" style="width:auto;min-width:115px;">
          <option value="100" selected>Show Top 100</option>
          <option value="1000">Show Top 1000</option>
          <option value="all">Show All</option>
        </select>
      </div>
      <div class="col-auto">
        <input type="text" id="searchInput" class="form-control" placeholder="Search..." />
      </div>
      <div class="col-auto">
        <strong>Total Deliveries: </strong><span id="totalDeliveries">0</span>
      </div>
    </div>
  </div>
  <div class="container">
    <table id="outItemsTable" class="table table-dark table-borderless align-middle">
      <thead>
        <tr>
          <th>No</th>
          <th>Barcode</th>
          <th>Delivery By</th>
          <th>INV Photo</th>
          <th>Full Photo</th>
          <th>User Phone</th>
          <th>Date/Time</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody><!-- Data loads here --></tbody>
    </table>
  </div>
  <!-- Photo Modal -->
  <div id="photoModal"><img id="modalImg" src=""></div>
  <!-- Edit Modal (Bootstrap) -->
  <div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="background:#232323;color:#fdb04c;">
        <div class="modal-header">
          <h5 class="modal-title">Edit Item</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="editForm">
            <input type="hidden" id="edit-id" />
            <div class="mb-3">
              <label class="form-label">Barcode</label>
              <input type="text" class="form-control" id="edit-inv" required />
            </div>
            <div class="mb-3">
              <label class="form-label">Delivery By</label>
              <select class="form-select" id="edit-delivery_by" required>
                  <option>VET</option>
                  <option>ក្រុមហ៊ុន</option>
                  <option>J&T</option>
                  <option>hou Express</option>
                  <option>Jalat</option>
                  <option>Banhjol</option>
                  <option>Nith Delivery</option>
                  <!--<option>Yum Delivery</option>-->
                  <option>ភ្លៀវមកយកដល់ហាង</option>
                  <option>Kjill Express</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">User Phone</label>
              <input type="text" class="form-control" id="edit-user_phone" required />
            </div>
            <div class="mb-3">
              <label class="form-label">Date/Time</label>
              <input type="text" class="form-control" id="edit-date_time" required />
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
      document.getElementById("modalImg").src = src;
      document.getElementById("photoModal").style.display = "flex";
    }
    document.getElementById("photoModal").onclick = function() { this.style.display = "none"; };
    let outItemsData = [];
    const tbody = document.querySelector("#outItemsTable tbody");

    function fillDeliveryOptions() {
      const select = document.getElementById('filterDeliveryBy');
      const unique = [...new Set(outItemsData.map(x => x.delivery_by).filter(Boolean))];
      select.innerHTML = `<option value="">All Delivery</option>`;
      unique.forEach(name => { select.innerHTML += `<option value="${name}">${name}</option>`; });
    }

    function fetchAndRender() {
      fetch(API_GET_OUT_ITEMS)
        .then(res => res.json())
        .then(data => {
          outItemsData = data;
          fillDeliveryOptions();
          renderTable();
        })
        .catch(err => {
          tbody.innerHTML = `<tr><td colspan="9" style="color:#f77;">Failed to load data</td></tr>`;
        });
    }

    function formatDateTime(dt) {
      const date = new Date(dt);
      const pad = x => x.toString().padStart(2, "0");
      return `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    }

    function filterData() {
      const dateValue = document.getElementById('filterDate').value;
      const deliveryByValue = document.getElementById('filterDeliveryBy').value;
      const searchValue = document.getElementById('searchInput') ? document.getElementById('searchInput').value.trim().toLowerCase() : "";

      return outItemsData.filter(item => {
        if (dateValue) {
          const itemDateStr = item.date_time ? new Date(item.date_time).toISOString().slice(0,10) : '';
          if (itemDateStr !== dateValue) return false;
        }
        if (deliveryByValue && item.delivery_by !== deliveryByValue) return false;
        if (searchValue) {
          // Search all relevant fields
          const fields = [
            item.inv, item.delivery_by, item.user_phone, item.date_time,
            item.id, item.full_photo, item.inv_photo
          ].map(v => (v + "").toLowerCase());
          if (!fields.some(f => f.includes(searchValue))) return false;
        }
        return true;
      });
    }

    function renderTable() {
      const filteredData = filterData();
      tbody.innerHTML = "";

      // Sort newest first
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
            <td>${item.delivery_by}</td>
            <td>${item.inv_photo ? `<img src="${item.inv_photo}" onclick="showPhoto(this.src)" style="cursor:pointer;">` : ""}</td>
            <td>${item.full_photo ? `<img src="${item.full_photo}" onclick="showPhoto(this.src)" style="cursor:pointer;">` : ""}</td>
            <td>${item.user_phone}</td>
            <td>${item.date_time ? formatDateTime(item.date_time) : ""}</td>
            <td>
              <button class="btn btn-warning btn-sm action-btn" onclick="openEditModal('${item.id}')">Edit</button>
              <button class="btn btn-danger btn-sm action-btn" onclick="deleteItem('${item.id}')">Delete</button>
            </td>
          </tr>`;
      });
      document.getElementById('totalDeliveries').textContent = filteredData.length;
    }

    document.getElementById('filterDate').addEventListener('change', renderTable);
    document.getElementById('filterDeliveryBy').addEventListener('change', renderTable);
    document.getElementById('searchInput').addEventListener('input', renderTable);

    window.openEditModal = function(id) {
      const item = outItemsData.find(i => i.id == id);
      document.getElementById('edit-id').value = item.id;
      document.getElementById('edit-inv').value = item.inv;
      document.getElementById('edit-delivery_by').value = item.delivery_by;
      document.getElementById('edit-user_phone').value = item.user_phone;
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
        user_phone: document.getElementById('edit-user_phone').value,
        date_time: document.getElementById('edit-date_time').value,
      };
      fetch(`${API_EDIT_OUT_ITEMS}?id=${id}`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(res => {
        if(res.result === 'success'){
          const idx = outItemsData.findIndex(i => i.id == id);
          outItemsData[idx] = {...outItemsData[idx], ...payload};
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
      fetch(`${API_DELETE_OUT_ITEMS}?id=${id}`, { method: 'POST' })
        .then(res => res.json())
        .then(res => {
          if(res.result === 'success'){
            outItemsData = outItemsData.filter(i => i.id != id);
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

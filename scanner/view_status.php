<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Status & Delivery Summary</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <style>
    body { background: #232323; color: #fdb04c; font-family: sans-serif; margin: 0; }
    h2 { margin-top: 22px; color: #fdb04c; }
    .controls { margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    table { width: 100%; border-collapse: collapse; margin-top: 1.5em; }
    th, td { text-align: center; padding: 10px; border: 1px solid #444; }
    th { background: #232323; color: #fdb04c; }
    td { background: #1a1a1a; color: #fff; }
    .no-data-row { color: #e77; text-align: center; }
    label { color: #fdb04c; font-weight: bold; }
    input[type="date"], input[type="text"], select { padding: 7px 9px; border-radius: 5px; border: 1px solid #444; background: #292929; color: #fdb04c; min-width: 140px; }
    .comment-input { min-width: 190px; }
    button { padding: 7px 14px; border-radius: 5px; border: none; background: #ff9800; color: white; cursor: pointer; font-weight: bold; }
    button:hover { background: #f57c00; }
    .amount-cell { font-weight: bold; color: #ffb04c; }
    #previewMsg { margin-top: 10px; background: #181818; color: #60eaff; padding: 10px; border-radius: 6px; white-space: pre; }
    #autoStatus { margin-top:10px; color:#ffbe47; }
  </style>
</head>
<body>
  <div class="container">
    <h2 class="text-center">Status & Delivery Summary</h2>
    <div class="controls mb-4">
      <label for="dateFilter">Show only this date:</label>
      <input type="date" id="dateFilter" onchange="renderStatusTable(); updatePreview();">
      <button onclick="clearFilter()">Clear Filter</button>
      <input type="text" id="customComment" class="comment-input" placeholder="Add a comment..." oninput="updatePreview()"/>
      <button id="sendTelegramBtn" onclick="sendToTelegram()">Send Status to Telegram</button>
    </div>
    <div id="statusTable"></div>
    <div id="previewMsg"></div>
    <div id="autoStatus"></div>
  </div>
  
  <script>
    // --- Telegram credentials ---
    const TELEGRAM_BOT_TOKEN = "8321782817:AAHCd0WzYaOGVmXd8qWAuuBLtemAJa73eHk";
    
    // --- List all target groups/channels/topics here ---
    const TELEGRAM_TARGETS = [
      { chat_id: "-5055882974" }, // Main Group
      // { chat_id: "-10014953322" }, // Add other group/channel IDs as desired
       { chat_id: "-1002751887489", message_thread_id: 3 }, // Example: group with topic/thread
    ];

    let statusData = [];

    function fetchStatusReport() {
      fetch('get_status.php')
        .then(res => res.json())
        .then(data => {
          statusData = data || [];
          renderStatusTable();
          updatePreview();
        })
        .catch(err => {
          document.getElementById('statusTable').innerHTML = "<div class='no-data-row'>Failed to load report</div>";
        });
    }

    function renderStatusTable() {
      let filterDate = document.getElementById('dateFilter').value;
      let dataToShow = filterDate
        ? statusData.filter(row => row.date === filterDate)
        : statusData;

      let html = "<table class='table table-bordered' id='pivotTable'><thead><tr>";
      html += "<th>Date</th><th>Amount of Delivery</th><th>Paid</th><th>Unpaid</th><th>Total</th>";
      html += "</tr></thead><tbody>";

      if (!dataToShow || dataToShow.length === 0) {
        html += "<tr><td colspan='5' class='no-data-row'>No report for this date</td></tr>";
      } else {
        dataToShow.forEach(row => {
          html += `<tr>
            <td>${row.date}</td>
            <td class="amount-cell">${row.delivery_count}</td>
            <td>${row.paid}</td>
            <td>${row.unpaid}</td>
            <td class="amount-cell">${row.total}</td>
          </tr>`;
        });
      }
      html += "</tbody></table>";
      document.getElementById('statusTable').innerHTML = html;
    }

    function createTelegramMessage(sendDate) {
      let targetDate = sendDate ||
        (document.getElementById('dateFilter').value ? document.getElementById('dateFilter').value : new Date().toISOString().slice(0, 10));
      let row = statusData.find(r => r.date === targetDate);
      if (!row) {
        return "Date: " + targetDate + "\nNo report Today";
      }
      let customComment = document.getElementById('customComment').value.trim();
      let msg = "សួស្ដី​ @everyone នេះជារបាយការណ៍ LAKAMO \n";
      msg += "ថ្ងៃ៖ " + row.date + "\n";
      msg += "ចំនួនដឹកជញ្ជូន៖ " + row.delivery_count + "កញ្ចប់\n";
      msg += "Paid : " + row.paid + "$\n";
      msg += "Unpaid : " + row.unpaid + "$\n";
      msg += "Total Payment : " + row.total +"$\n";
      if (customComment) msg += "\n\nមតិ៖ " + customComment;
      return msg;
    }
    
    function updatePreview() {
      document.getElementById('previewMsg').textContent = createTelegramMessage();
    }

    // --- Sends to ALL Telegram groups/channels/topics in TELEGRAM_TARGETS ---
    function sendToTelegram(sendDate = null) {
      const message = createTelegramMessage(sendDate);
      const url = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`;
      let results = [];
      let completed = 0;
      let statusDiv = document.getElementById('autoStatus');
      statusDiv.textContent = "Sending to all groups...";
      TELEGRAM_TARGETS.forEach(target => {
        const body = {
          chat_id: target.chat_id,
          text: message
        };
        if (target.message_thread_id) {
          body.message_thread_id = target.message_thread_id;
        }
        fetch(url, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body)
        })
          .then(res => res.json())
          .then(data => {
            results.push({
              group: target.chat_id + (target.message_thread_id ? ' (topic ' + target.message_thread_id + ')' : ''),
              ok: data.ok,
              desc: data.description || 'OK'
            });
            completed++;
            if (completed === TELEGRAM_TARGETS.length) {
              const summary = results.map(r =>
                `${r.group}: ${r.ok ? '✅' : '❌'} (${r.desc})`
              ).join('\n');
              statusDiv.textContent = "Results:\n" + summary;
            }
          })
          .catch(err => {
            results.push({ group: target.chat_id, ok: false, desc: err });
            completed++;
            if (completed === TELEGRAM_TARGETS.length) {
              const summary = results.map(r =>
                `${r.group}: ${r.ok ? '✅' : '❌'} (${r.desc})`
              ).join('\n');
              statusDiv.textContent = "Results:\n" + summary;
            }
          });
      });
    }

    function clearFilter() {
      document.getElementById('dateFilter').value = '';
      renderStatusTable();
      updatePreview();
    }

    fetchStatusReport();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

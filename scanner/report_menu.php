<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Report Menu</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body {
      background: #000;
      color: #fdb04c;
      font-family: sans-serif;
      margin: 0;
    }
    .report-menu {
      display: flex;
      gap: 20px;
      align-items: center;
      padding: 12px 30px 10px 30px;
      background: #181818;
      border-bottom: 1px solid #222;
    }
    .back-btn {
      color: #fff;
      background: #ff9800;
      border: none;
      font-weight: bold;
      font-size: 1em;
      padding: 7px 18px;
      border-radius: 6px;
      margin-right: 18px;
      cursor: pointer;
      transition: background 0.2s, color 0.2s;
      text-decoration: none;
      display: flex;
      align-items: center;
    }
    .back-btn:hover {
      background: #fdb04c;
      color: #000;
      text-decoration: none;
    }
    .menu-item {
      color: #ffbe47;
      text-decoration: none;
      font-weight: bold;
      font-size: 1.05em;
      padding: 7px 18px;
      border-radius: 6px;
      border: none;
      background: transparent;
      cursor: pointer;
      transition: background 0.2s, color 0.2s;
    }
    .menu-item.active,
    .menu-item:hover {
      background: #ff7990;
      color: #000;
      text-decoration: none;
    }
    #sendToTelegramMenu {
      background: #0088cc;
      color: #fff;
      font-weight: bold;
      border: none;
      padding: 8px 18px;
      border-radius: 5px;
      cursor: pointer;
      margin-left: 20px;
      margin-right: 0;
      transition: background 0.2s;
    }
    #sendToTelegramMenu:hover { background: #005d8c; }
    #sendAllToTelegramMenu {
      background:#2196f3;
      color:#fff;
      font-weight:bold;
      border:none;
      padding:8px 18px;
      border-radius:5px;
      cursor:pointer;
      margin-left:12px;
      transition:background 0.2s;
    }
    #sendAllToTelegramMenu:hover { background:#1565c0; }
    #dateSelector {
      margin-left:32px;
      margin-top:10px;
      padding:5px;
      border-radius:4px;
      border:1px solid #ffbe47;
      font-size:1em;
      background:#222;
      color:#fdb04c;
      width:180px;
    }
    .content-frame {
      width: 100%;
      height: calc(100vh - 60px);
      border: none;
      background: #000;
    }
  </style>
</head>
<body>
  <div style="padding:8px 0;">
    <label for="dateSelector" style="color:#ffbe47; font-weight:bold; margin-left:32px;">Select date to send:</label>
    <input type="date" id="dateSelector">
  </div>
  <div class="report-menu">
    <a href="home.php" class="back-btn">← Back to Home</a>
    <button class="menu-item active" onclick="loadPage('view_pay_delivery.php', this)">Pay by delivery</button>
    <button class="menu-item" onclick="loadPage('view_amount_delivery.php', this)">Amount of delivery</button>
    <button class="menu-item" onclick="loadPage('view_status.php', this)">Status report</button>
    <button id="sendToTelegramMenu">Send to Telegram</button>
    <button id="sendAllToTelegramMenu">Send All to Telegram</button>
  </div>
  <iframe id="reportFrame" class="content-frame" src="view_pay_delivery.php"></iframe>
  <script>
    function loadPage(page, btn) {
      document.getElementById('reportFrame').src = page;
      document.querySelectorAll('.menu-item').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    }

    function getActiveMenu() {
      let btns = document.querySelectorAll('.menu-item');
      for (let b of btns) {
        if (b.classList.contains('active')) return b.textContent.trim();
      }
      return '';
    }

    document.getElementById('sendToTelegramMenu').onclick = function() {
      var iframe = document.getElementById('reportFrame');
      try {
        var table = iframe.contentWindow.document.getElementById('pivotTable');
        if (!table) {
          alert("No report data found.");
          return;
        }
        let menuType = getActiveMenu();
        let msg = '';
        const rows = table.querySelectorAll('tbody tr');
        
        if(menuType === "Status report") {
          rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length < 4 || cells[0].textContent.toLowerCase().includes('no report')) return;
            let date = cells[0].innerText.trim();
            let paid = cells[1].innerText.trim();
            let unpaid = cells[2].innerText.trim();
            let total = cells[3].innerText.trim();
            msg += `Date: ${date}\nPaid: ${paid}\nUnpaid: ${unpaid}\nTotal: ${total}\n\n`;
          });
        } else {
          let headers = [];
          table.querySelectorAll('thead th').forEach((th, i) => {
            if (i > 0 && i < table.querySelectorAll('thead th').length - 1) {
              headers.push(th.innerText.trim());
            }
          });
          let totalHeader = table.querySelectorAll('thead th')[table.querySelectorAll('thead th').length - 1].innerText.trim();
          rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length < 2) return;
            let date = cells[0].innerText.trim();
            let deliveries = [];
            for (let i = 1; i < cells.length - 1; i++) {
              let value = cells[i].innerText.trim();
              let numValue = value.replace(/[^\d.]/g, '');
              if (numValue !== "" && !isNaN(numValue) && Number(numValue) !== 0) {
                if (menuType === "Pay by delivery") {
                  deliveries.push(`${headers[i-1]}: ${numValue}$`);
                } else if (menuType === "Amount of delivery") {
                  deliveries.push(`${headers[i-1]}: ${numValue}`);
                } else {
                  deliveries.push(`${headers[i-1]}: ${numValue}`);
                }
              }
            }
            let totalVal = cells[cells.length - 1].innerText.trim();
            let totalNum = totalVal.replace(/[^\d.]/g, '');
            let totalLine = "";
            if (totalNum !== "" && !isNaN(totalNum) && Number(totalNum) !== 0) {
              if (menuType === "Pay by delivery") {
                totalLine = `Total: ${totalNum}$`;
              } else {
                totalLine = `Total: ${totalNum}`;
              }
            }
            if (deliveries.length === 0 && totalLine === "") return;
            msg += `Date: ${date}\n${deliveries.join('\n')}`;
            if (totalLine) msg += `\n${totalLine}`;
            msg += '\n\n';
          });
        }
        
        if (!msg.trim()) {
          alert("No data to send.");
          return;
        }
        
        fetch('send_telegram.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ message: msg })
        })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            alert('Sent to Telegram!');
          } else {
            alert('Failed: ' + (data.description || 'Unknown error'));
          }
        })
        .catch(err => {
          alert('Send error: ' + err);
        });
      } catch (err) {
        alert('Could not read report data.');
        return;
      }
    };

    document.getElementById('sendAllToTelegramMenu').onclick = function() {
      var iframe = document.getElementById('reportFrame');
      var selectedDate = document.getElementById('dateSelector').value;
      if (!selectedDate) {
        alert("Please select a date.");
        return;
      }
      try {
        var table = iframe.contentWindow.document.getElementById('pivotTable');
        if (!table) {
          alert("No report data found.");
          return;
        }
        let menuType = getActiveMenu();
        const rows = table.querySelectorAll('tbody tr');
        let msg = '';
        let found = false;
        
        if(menuType === "Status report") {
          rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length < 4) return;
            let date = cells[0].innerText.trim();
            if (date !== selectedDate || date.toLowerCase().includes('no report')) return;
            found = true;
            let paid = cells[1].innerText.trim();
            let unpaid = cells[2].innerText.trim();
            let total = cells[3].innerText.trim();
            msg += `Date: ${date}\nPaid: ${paid}\nUnpaid: ${unpaid}\nសរុបទឹកលុយ: ${total}\n\n`;
          });
        } else {
          let headers = [];
          table.querySelectorAll('thead th').forEach((th, i) => {
            if (i > 0 && i < table.querySelectorAll('thead th').length - 1) {
              headers.push(th.innerText.trim());
            }
          });
          rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length < 2) return;
            let date = cells[0].innerText.trim();
            if (date !== selectedDate) return;
            found = true;
            let deliveries = [];
            for (let i = 1; i < cells.length - 1; i++) {
              let value = cells[i].innerText.trim();
              let numValue = value.replace(/[^\d.]/g, '');
              if (numValue !== "" && !isNaN(numValue) && Number(numValue) !== 0) {
                if (menuType === "Pay by delivery") {
                  deliveries.push(`${headers[i-1]}: ${numValue}$`);
                } else if (menuType === "Amount of delivery") {
                  deliveries.push(`${headers[i-1]}: ${numValue}`);
                } else {
                  deliveries.push(`${headers[i-1]}: ${numValue}`);
                }
              }
            }
            let totalVal = cells[cells.length - 1].innerText.trim();
            let totalNum = totalVal.replace(/[^\d.]/g, '');
            let totalLine = "";
            if (totalNum !== "" && !isNaN(totalNum) && Number(totalNum) !== 0) {
              if (menuType === "Pay by delivery") {
                totalLine = `Total: ${totalNum}$`;
              } else {
                totalLine = `Total: ${totalNum}`;
              }
            }
            if (deliveries.length === 0 && totalLine === "") return;
            msg += `Date: ${date}\n${deliveries.join('\n')}`;
            if (totalLine) msg += `\n${totalLine}`;
            msg += '\n\n';
          });
        }
        
        if (!found) {
          alert("No data for selected date.");
          return;
        }
        if (!msg.trim()) {
          alert("No data to send.");
          return;
        }
        
        fetch('send_telegram.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ message: msg })
        })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            alert('Sent to Telegram!');
          } else {
            alert('Failed: ' + (data.description || 'Unknown error'));
          }
        })
        .catch(err => {
          alert('Send error: ' + err);
        });
      } catch (err) {
        alert('Could not read report data.');
        return;
      }
    };
  </script>
</body>
</html>

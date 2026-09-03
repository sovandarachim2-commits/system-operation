<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');

$deliveryOptions = [];
try {
  $pdo = get_db_connection();
  $stmt = $pdo->query("SELECT name FROM scanner_out_items_delivery_by WHERE is_active = 1 ORDER BY name ASC");
  $deliveryOptions = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Throwable $e) {
  // Keep empty list if config table is not ready.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Return Items Entry</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons" />
  <script src="html5-qrcode.min.js"></script>
  <!-- Load backend API config -->
  <script src="api_config.php"></script>
  <style>
    body {margin:0; background:#1a1a1a; color:#fdb04c; font-family:sans-serif; min-height:100vh; padding-bottom:80px;}
    .card {background:#232323; border-radius:20px; box-shadow:0 2px 8px #1114; padding:18px 14px; margin:15px 10px; color:#fdb04c; position:relative;}
    .card-title {font-size:1.1em; font-weight:bold; margin-bottom:8px; display:flex; align-items:center;}
    .card-icon {font-size:1.5em; margin-right:8px;}
    .scan-status {font-size:1.2em; color:#fdb04c; text-align:center; padding:12px 0;}
    .card-action {position:absolute; right:18px; top:16px; background:#25252a; border:none; border-radius:50%; width:40px; height:40px; display:flex; align-items:center; justify-content:center; color:#ffb44c; cursor:pointer;}
    .input-field, select {width:100%; font-size:1.1em; color:white; margin:8px 0 4px 0; padding:10px 8px; border-radius:8px; border:none; background:#292929;}
    .input-row {display:flex; gap:8px;}
    .input-row > * {flex:1;}
    .camera-btn {background:#ffb44c; color:#222; border:none; border-radius:9px; padding:8px 8px; margin-left:6px; cursor:pointer;}
    .img-preview {height:40px; width:40px; border-radius:7px; background:#444; object-fit:cover; margin:3px;}
    .bottom-bar {position:fixed; left:0; bottom:0; width:100%; background:#232323; height:65px; display:flex; justify-content:space-around; align-items:center; border-top:2px solid #444;}
    .bottom-btn {background:none; border:none; color:#ffb44c; font-size:2em; cursor:pointer;}
    #scannerOverlay {display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(30,30,30,0.96); z-index:9999; align-items:center; justify-content:center; flex-direction:column;}
    #qr-reader {width:95vw; max-width:480px; height:60vh; background:#101010; margin:auto; border-radius:12px;}
    #closeScannerBtn {margin-top:20px; background:#ff726f; color:white; padding:16px 28px; border:none; border-radius:14px; font-size:1.2em;}
    .save-btn {background:#2ecc71; color:white; border:none; border-radius:10px; padding:12px 24px; font-size:1.15em; margin:30px 10px 10px 10px; cursor:pointer; font-weight:bold;}
    .history-btn {background:#2980b9; color:white; border:none; border-radius:10px; padding:12px 24px; font-size:1.15em; margin:10px 10px 30px 10px; cursor:pointer; font-weight:bold;}
    .home-btn {background:#4400ff; color:white; border:none; border-radius:10px; padding:12px 24px; font-size:1.15em; margin:10px 10px 30px 10px; cursor:pointer; font-weight:bold;}
  </style>
</head>
<body>
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">qr_code</span>Barcode / QR</div>
    <div class="scan-status" id="barcodeStatus">No code scanned</div>
    <input type="text" id="barcodeInput" class="input-field" placeholder="Barcode / QR" />
    <button class="card-action" id="scanBarcodeBtn"><span class="material-icons">qr_code_scanner</span></button>
  </div>
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">person</span>Delivery By</div>
    <select id="deliveryByInput" class="input-field">
      <option>- Please select -</option>
      <?php foreach ($deliveryOptions as $deliveryName): ?>
      <option><?= htmlspecialchars((string)$deliveryName) ?></option>
      <?php endforeach; ?>
      <?php if (count($deliveryOptions) === 0): ?>
      <option value="" disabled>No Delivery By configured</option>
      <?php endif; ?>
    </select>
  </div>
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">report</span>Reason</div>
    <input type="text" id="reasonInput" class="input-field" placeholder="Reason for return" />
  </div>
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">receipt</span>INV PHOTO</div>
    <input type="file" accept="image/*" capture="environment" style="display:none" id="invPhotoUpload" />
    <button class="camera-btn" id="invPhotoBtn"><span class="material-icons">photo_camera</span>Take Photo</button>
    <img id="invPhotoPreview" class="img-preview" style="display:none" />
  </div>
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">photo_library</span>FULL PHOTO</div>
    <input type="file" accept="image/*" capture="environment" style="display:none" id="fullPhotoUpload" />
    <button class="camera-btn" id="fullPhotoBtn"><span class="material-icons">photo_camera</span>Take Photo</button>
    <img id="fullPhotoPreview" class="img-preview" style="display:none" />
  </div>
  <div class="card input-row">
    <input type="text" id="userInput" class="input-field" placeholder="User" readonly />
    <input type="text" id="dateInput" class="input-field" disabled />
  </div>
  <button class="save-btn" id="saveBtn" onclick="handleSave()">Save to Database</button>
  <button class="history-btn" onclick="window.location.href='view_return_items.php'">View History</button>
  <button class="home-btn" onclick="window.location.href='home.php'">Back to Home</button>

  <div id="scannerOverlay">
    <div style="text-align:center; color:white; font-size:1.2em; margin-bottom:12px;">Scanning QR/Barcode...</div>
    <div id="qr-reader"></div>
    <button id="closeScannerBtn">Close</button>
  </div>
  <div id="customAlert" style="display:none;position:fixed;top:45%;left:50%;transform:translate(-50%,-50%);background:#232323;color:#2ecc71;font-size:1.3em;font-weight:bold;padding:30px 45px;border-radius:21px;box-shadow:0 4px 12px #1116;z-index:10000;text-align:center;"></div>

<script>
document.addEventListener("DOMContentLoaded", function() {
  // Fill current username from server session
  fetch('current_user.php')
    .then(r => r.json())
    .then(d => {
      if (d && d.success && d.username) {
        document.getElementById('userInput').value = d.username;
      }
    })
    .catch(() => {});

  document.getElementById('dateInput').value = new Date().toLocaleString();
  
  // Add event listener for manual barcode input
  document.getElementById('barcodeInput').addEventListener('input', function(e) {
    const barcode = e.target.value.trim();
    if (barcode) {
      fetchDeliveryByBarcode(barcode);
    }
  });
});

// Function to fetch delivery by barcode from out_items
function fetchDeliveryByBarcode(barcode) {
  fetch('get_delivery_by_barcode.php?barcode=' + encodeURIComponent(barcode))
    .then(response => response.json())
    .then(data => {
      if (data.success && data.delivery_by) {
        const deliverySelect = document.getElementById('deliveryByInput');
        deliverySelect.value = data.delivery_by;
        
        // Show visual feedback
        const statusElement = document.getElementById('barcodeStatus');
        statusElement.textContent = "✅ Auto-filled delivery: " + data.delivery_by;
        statusElement.style.color = '#2ecc71';
      }
    })
    .catch(error => {
      console.log('No delivery info found for barcode:', barcode);
    });
}

// Camera error helper
function getCameraErrorMessage(error) {
  const isHttp = location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1';
  if (isHttp) return 'Camera requires HTTPS. Please access via HTTPS or localhost.';
  const msg = String(error || '');
  if (/NotAllowedError|PermissionDenied/i.test(msg)) return 'Camera permission denied. Allow camera access in browser settings then reload.';
  if (/NotFoundError|DevicesNotFound/i.test(msg)) return 'No camera found on this device.';
  if (/NotReadableError|TrackStartError/i.test(msg)) return 'Camera is busy. Close other apps using the camera and try again.';
  return 'Camera error: ' + msg;
}

// QR/Barcode scan logic
const scannerOverlay = document.getElementById('scannerOverlay');
const scanBarcodeBtn = document.getElementById('scanBarcodeBtn');
const closeScannerBtn = document.getElementById('closeScannerBtn');
const barcodeInput = document.getElementById('barcodeInput');
const barcodeStatus = document.getElementById('barcodeStatus');
let html5QrCode = null;
let isScannerActive = false;

scanBarcodeBtn.onclick = function() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    showCustomAlert(getCameraErrorMessage(null), '#ff4d4f', true); return;
  }
  scannerOverlay.style.display = 'flex';
  if (!html5QrCode) html5QrCode = new Html5Qrcode('qr-reader');
  if (isScannerActive) return;
  isScannerActive = true;
  html5QrCode.start(
    { facingMode: 'environment' },
    { fps: 5, qrbox: 350 },
    function(decodedText) {
      document.getElementById('barcodeInput').value = decodedText;
      barcodeStatus.textContent = "Scanned: " + decodedText;
      barcodeStatus.style.color = '#2ecc71';
      
      // Auto-fill delivery by from out_items
      fetchDeliveryByBarcode(decodedText);
      
      stopScanner();
    },
    function() {}
  ).catch(function(error) {
    showCustomAlert(getCameraErrorMessage(error), '#ff4d4f', true);
    stopScanner();
  });
};
closeScannerBtn.onclick = stopScanner;
function stopScanner() {
  if (!isScannerActive) {
    scannerOverlay.style.display = 'none';
    return;
  }
  html5QrCode.stop().then(() => {
    scannerOverlay.style.display = 'none';
    isScannerActive = false;
  }).catch(() => {
    scannerOverlay.style.display = 'none';
    isScannerActive = false;
  });
}

// Image compression logic
function compressImage(file, maxWidth=600, maxHeight=600, quality=0.7, callback) {
  const img = new Image();
  const reader = new FileReader();
  reader.onload = function(e) {
    img.onload = function() {
      let ratio = Math.min(maxWidth / img.width, maxHeight / img.height, 1);
      let width = img.width * ratio, height = img.height * ratio;
      const canvas = document.createElement('canvas');
      canvas.width = width; canvas.height = height;
      canvas.getContext('2d').drawImage(img, 0, 0, width, height);
      const base64 = canvas.toDataURL('image/jpeg', quality);
      callback(base64);
    };
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}
function base64ToBlob(base64, mime = 'image/jpeg') {
  const byteStr = atob(base64.split(',')[1]);
  const ab = new ArrayBuffer(byteStr.length);
  const ia = new Uint8Array(ab);
  for (let i = 0; i < byteStr.length; i++) ia[i] = byteStr.charCodeAt(i);
  return new Blob([ab], { type: mime });
}

document.getElementById('invPhotoBtn').onclick = function() {
  document.getElementById('invPhotoUpload').click();
};
document.getElementById('invPhotoUpload').onchange = function(e) {
  const file = e.target.files[0];
  if (!file) return;
  compressImage(file, 600, 600, 0.7, function(base64) {
    const img = document.getElementById('invPhotoPreview');
    img.src = base64; img.style.display='inline';
    img.dataset.base64 = base64;
  });
};

document.getElementById('fullPhotoBtn').onclick = function() {
  document.getElementById('fullPhotoUpload').click();
};
document.getElementById('fullPhotoUpload').onchange = function(e) {
  const file = e.target.files[0];
  if (!file) return;
  compressImage(file, 600, 600, 0.7, function(base64) {
    const img = document.getElementById('fullPhotoPreview');
    img.src = base64; img.style.display='inline';
    img.dataset.base64 = base64;
  });
};

function showCustomAlert(msg, color = '#2ecc71', isError = false) {
  const alertDiv = document.getElementById('customAlert');
  const icon = isError ? '<span style="font-size:2em;">❌</span>' : '<span style="font-size:2em;">✅</span>';
  alertDiv.innerHTML = msg + ' ' + icon; alertDiv.style.color = color;
  alertDiv.style.display = 'block';
  setTimeout(() => { alertDiv.style.display = 'none'; }, isError ? 3000 : 2000);
}

function clearForm() {
  const deliveryBySelect = document.getElementById('deliveryByInput');
  const lastDeliveryBy = deliveryBySelect.value;
  const lastReason = document.getElementById('reasonInput').value;

  document.getElementById('barcodeInput').value = '';
  // Do not reset deliveryByInput here, keep the last selected value
  document.getElementById('invPhotoPreview').style.display = 'none';
  document.getElementById('fullPhotoPreview').style.display = 'none';
  document.getElementById('invPhotoPreview').src = '';
  document.getElementById('fullPhotoPreview').src = '';
  document.getElementById('invPhotoUpload').value = '';
  document.getElementById('fullPhotoUpload').value = '';
  document.getElementById('invPhotoPreview').dataset.base64 = '';
  document.getElementById('fullPhotoPreview').dataset.base64 = '';
  document.getElementById('dateInput').value = new Date().toLocaleString();
  document.getElementById('barcodeStatus').textContent = 'No code scanned';

  // Reapply last deliveryBy selection
  deliveryBySelect.value = lastDeliveryBy;
  // Reapply last reason for faster repeated entries
  document.getElementById('reasonInput').value = lastReason;
}

function handleSave() {
  const deliveryBy = document.getElementById('deliveryByInput').value;
  const userName = document.getElementById('userInput').value || "default_user";
  const reason = document.getElementById('reasonInput').value;

  if (!document.getElementById('barcodeInput').value.trim()) {
    showCustomAlert('Please scan or enter the Barcode/QR code.', '#ff4d4f', true); return;
  }
  if (deliveryBy === '- Please select -') {
    showCustomAlert('Please select a Delivery By option.', '#ff4d4f', true); return;
  }
  if (!reason.trim()) {
    showCustomAlert('Please enter the reason.', '#ff4d4f', true); return;
  }
  if (!document.getElementById('invPhotoPreview').dataset.base64) {
    showCustomAlert('Please upload or take the INV PHOTO.', '#ff4d4f', true); return;
  }
  if (!document.getElementById('fullPhotoPreview').dataset.base64) {
    showCustomAlert('Please upload or take the FULL PHOTO.', '#ff4d4f', true); return;
  }

  // --- Get local date/time ---
  const now = new Date();
  const pad = n => n.toString().padStart(2, '0');
  const localTime = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;

  const formData = new FormData();
  formData.append('inv', document.getElementById('barcodeInput').value);
  formData.append('delivery_by', deliveryBy);
  formData.append('reason', reason);
  formData.append('username', userName); // ← ONLY USERNAME (NO user_phone)
  formData.append('date_time', localTime);
  formData.append('inv_photo', base64ToBlob(document.getElementById('invPhotoPreview').dataset.base64), 'inv_photo.jpg');
  formData.append('full_photo', base64ToBlob(document.getElementById('fullPhotoPreview').dataset.base64), 'full_photo.jpg');

  const saveBtn = document.getElementById('saveBtn');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving...';

  let saveEndpoint = 'save_return_items.php';
  if (typeof API_SAVE_RETURN_ITEMS === 'string' && API_SAVE_RETURN_ITEMS.trim() !== '') {
    try {
      const candidate = new URL(API_SAVE_RETURN_ITEMS, window.location.href);
      if (candidate.origin === window.location.origin && /\/scanner\/save_return_items\.php$/i.test(candidate.pathname)) {
        saveEndpoint = candidate.href;
      }
    } catch (error) {}
  }

  fetch(saveEndpoint, {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: formData
  })
  .then(async (response) => {
    const text = await response.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch (error) {
      const message = text
        ? text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
        : 'Invalid API response';
      throw new Error(message);
    }
    if (!response.ok) {
      throw new Error((data && (data.message || data.error)) || 'API request failed');
    }
    return data || {};
  })
  .then(res => {
    if (res.result === 'success') {
      showCustomAlert('Saved to Database!');
      clearForm();
    } else {
      const msg = (res.message && String(res.message).trim()) ? res.message : ('Save failed: ' + (res.result || 'unknown'));
      showCustomAlert(msg, '#ff4d4f', true);
    }
  })
  .catch((error) => {
    const message = error && error.message ? error.message : 'Network/API error. Please try again.';
    showCustomAlert(message, '#ff4d4f', true);
  })
  .finally(() => {
    saveBtn.disabled = false;
    saveBtn.textContent = 'Save to Database';
  });
}
</script>
</body>
</html>

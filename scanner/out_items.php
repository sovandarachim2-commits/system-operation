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
  <title>Out Items Entry</title>
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
    #qr-reader {width:95vw; max-width:600px; height:70vh; background:#101010; margin:auto; border-radius:12px;}
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
  <button class="history-btn" onclick="window.location.href='view_out_items.php'">View History</button>
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
});

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
    { 
      fps: 30,                    // Maximum speed for ultra-fast scanning
      qrbox: { width: 400, height: 400 },  // Larger scan area for bigger frame
      aspectRatio: 1.0,
      disableFlip: false,
      supportedScanTypes: [0, 1],  // Support both QR and barcode
      experimental: true,           // Enable experimental features for better performance
      useBarCodeDetectorIfSupported: true,  // Use native barcode detector when available
      formatsToSupport: ['qr_code', 'aztec', 'code_128', 'code_39', 'code_93', 'codabar', 'data_matrix', 'ean_13', 'ean_8', 'itf', 'pdf417', 'upc_a', 'upc_e'],  // All supported formats
      videoConstraints: {
        width: { ideal: 1280 },
        height: { ideal: 720 },
        facingMode: 'environment'
      }  // Higher resolution for better detection
    },
    function(decodedText) {
      // Immediate feedback - vibrate if supported
      if (navigator.vibrate) {
        navigator.vibrate(100); // Quick vibration feedback
      }

      barcodeInput.value = decodedText;
      barcodeStatus.textContent = "✅ Scanned: " + decodedText;
      barcodeStatus.style.color = '#2ecc71'; // Green for success

      // Auto-stop scanner immediately for instant response
      setTimeout(() => stopScanner(), 200); // Close after 200ms
    },
    function(errorMessage) {
      // Silent error handling to avoid console spam
    }
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
function compressImage(file, maxWidth=800, maxHeight=800, quality=0.78, callback) {
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
  compressImage(file, 800, 800, 0.78, function(base64) {
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
  compressImage(file, 800, 800, 0.78, function(base64) {
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

  document.getElementById('barcodeInput').value = '';
  // Do NOT reset deliveryByInput here, keep the last selected
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
}


function handleSave() {
  const saveBtn = document.getElementById('saveBtn');
  const deliveryBy = document.getElementById('deliveryByInput').value;
  const userName = document.getElementById('userInput').value || "default_user";

  if (!document.getElementById('barcodeInput').value.trim()) {
    showCustomAlert('Please scan or enter the Barcode/QR code.', '#ff4d4f', true); return;
  }
  if (deliveryBy === '- Please select -') {
    showCustomAlert('Please select a Delivery By option.', '#ff4d4f', true); return;
  }
  if (!userName.trim()) {
    showCustomAlert('Please enter the User.', '#ff4d4f', true); return;
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
  formData.append('user_phone', userName);
  formData.append('date_time', localTime); // now local time, MySQL format
  formData.append('inv_photo', base64ToBlob(document.getElementById('invPhotoPreview').dataset.base64), 'inv_photo.jpg');
  formData.append('full_photo', base64ToBlob(document.getElementById('fullPhotoPreview').dataset.base64), 'full_photo.jpg');

  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving...';

  let saveEndpoint = 'save_out_items.php';
  if (typeof API_SAVE_OUT_ITEMS === 'string' && API_SAVE_OUT_ITEMS.trim() !== '') {
    try {
      const candidate = new URL(API_SAVE_OUT_ITEMS, window.location.href);
      if (candidate.origin === window.location.origin && /\/scanner\/save_out_items\.php$/i.test(candidate.pathname)) {
        saveEndpoint = candidate.href;
      }
    } catch (error) {}
  }

  fetch(saveEndpoint, {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(res => {
    if (res.result === 'success') {
      showCustomAlert('Saved to Database!');
      clearForm();
    } else {
      showCustomAlert(res.message ||'Error!វិក្កយបត្រជាន់គ្នាទាក់ទងទៅAccountant❌','#ff4d4f', true);
    }
  })
  .catch(() => showCustomAlert('Network/API error. Please try again.', '#ff4d4f', true))
  .finally(() => {
    saveBtn.disabled = false;
    saveBtn.textContent = 'Save to Database';
  });
}
</script>
</body>
</html>


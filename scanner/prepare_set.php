<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
require_once __DIR__ . '/config.php';

// Active sets whose linked set product has product_costs for the **current month**
// (same idea as seller orders / product costs by month).
$current_month = date('Y-m');
$costMonthLabel = date('F Y', strtotime($current_month . '-01'));
$prepareSetOptions = [];
if ($conn && !$conn->connect_error) {
    $sql = '
        SELECT DISTINCT ps.set_name
        FROM product_sets ps
        INNER JOIN products p
            ON p.name = ps.set_name
            AND COALESCE(NULLIF(p.product_type, \'\'), \'normal\') = \'set\'
        INNER JOIN product_costs pc
            ON p.id = pc.product_id AND pc.month_year = ?
        WHERE COALESCE(ps.is_active, 1) = 1
        AND p.active = 1
        AND p.id IN (
            SELECT DISTINCT product_id FROM product_costs WHERE month_year <= ?
        )
        ORDER BY ps.set_name ASC
    ';
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $current_month, $current_month);
        $stmt->execute();
        $setsRes = $stmt->get_result();
        if ($setsRes) {
            while ($row = $setsRes->fetch_assoc()) {
                $name = trim((string)($row['set_name'] ?? ''));
                if ($name !== '') {
                    $prepareSetOptions[] = $name;
                }
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Prepare Set Entry</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons" />
  <script src="html5-qrcode.min.js"></script>
  <script src="api_config.php"></script>
  <style>
    body {margin:0; background:#1a1a1a; color:#fdb04c; font-family:sans-serif; min-height:100vh; padding-bottom:80px;}
    .card {background:#232323; border-radius:20px; box-shadow:0 2px 8px #1114; padding:18px 14px; margin:15px 10px; color:#fdb04c; position:relative;}
    .card-title {font-size:1.1em; font-weight:bold; margin-bottom:8px; display:flex; align-items:center;}
    .card-icon {font-size:1.5em; margin-right:8px;}
    .scan-status {font-size:1.2em; color:#fdb04c; text-align:center; padding:12px 0;}
    .card-action {position:absolute; right:18px; top:16px; background:#25252a; border:none; border-radius:50%; width:40px; height:40px; display:flex; align-items:center; justify-content:center; color:#ffb44c; cursor:pointer;}
    .input-field, select, textarea {width:100%; font-size:1.1em; color:white; margin:8px 0 4px 0; padding:10px 8px; border-radius:8px; border:none; background:#292929;}
    select {color:#fdb04c; background:#292929;}
    .input-row {display:flex; gap:8px;}
    .input-row > * {flex:1;}
    .camera-btn {background:#ffb44c; color:#222; border:none; border-radius:9px; padding:8px 8px; margin-left:6px; cursor:pointer;}
    .img-preview {height:40px; width:40px; border-radius:7px; background:#444; object-fit:cover; margin:3px;}
    .save-btn {background:#2ecc71; color:white; border:none; border-radius:10px; padding:12px 24px; font-size:1.15em; margin:30px 10px 10px 10px; cursor:pointer; font-weight:bold;}
    .history-btn {background:#2980b9; color:white; border:none; border-radius:10px; padding:12px 24px; font-size:1.15em; margin:10px 10px 30px 10px; cursor:pointer; font-weight:bold;}
    .home-btn {background:#4400ff; color:white; border:none; border-radius:10px; padding:12px 24px; font-size:1.15em; margin:10px 10px 30px 10px; cursor:pointer; font-weight:bold;}
    #scannerOverlay {display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(30,30,30,0.96); z-index:9999; align-items:center; justify-content:center; flex-direction:column;}
    #qr-reader {width:95vw; max-width:600px; height:70vh; background:#101010; margin:auto; border-radius:12px;}
    #closeScannerBtn {margin-top:20px; background:#ff726f; color:white; padding:16px 28px; border:none; border-radius:14px; font-size:1.2em;}
  </style>
</head>
<body>
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">qr_code</span>INV</div>
    <div class="scan-status" id="barcodeStatus">No code scanned</div>
    <input type="text" id="invInput" class="input-field" placeholder="INV Barcode / QR" />
    <button class="card-action" id="scanBarcodeBtn"><span class="material-icons">qr_code_scanner</span></button>
  </div>

  <!-- Scanner overlay -->
  <div id="scannerOverlay">
    <div style="text-align:center; color:white; font-size:1.2em; margin-bottom:12px;">Scanning QR/Barcode...</div>
    <div id="qr-reader"></div>
    <button id="closeScannerBtn">Close</button>
  </div>

  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">group_work</span>Set</div>
    <div style="font-size:0.85em;opacity:0.85;margin:-4px 0 8px 0;">Product costs month: <strong><?= htmlspecialchars($costMonthLabel, ENT_QUOTES, 'UTF-8') ?></strong> — only sets with pricing for this month are listed.</div>
    <select id="setInput" class="input-field">
      <option value="">- Please select -</option>
      <?php foreach ($prepareSetOptions as $setName): ?>
      <option value="<?= htmlspecialchars($setName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($setName, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">receipt</span>Photo</div>
    <input type="file" accept="image/*" capture="environment" style="display:none" id="photoUpload" />
    <button class="camera-btn" id="photoBtn"><span class="material-icons">photo_camera</span>Take Photo</button>
    <img id="photoPreview" class="img-preview" style="display:none" />
  </div>
  <div class="card input-row">
    <input type="text" id="userInput" class="input-field" placeholder="User" readonly />
    <input type="text" id="dateInput" class="input-field" disabled />
  </div>
  <button class="save-btn" id="saveBtn" onclick="handleSave()">Save to Database</button>
  <button class="history-btn" onclick="window.location.href='view_prepare_set.php'">View History</button>
  <button class="home-btn" onclick="window.location.href='home.php'">Back to Home</button>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    // Fill current username from server session
    fetch('current_user.php')
      .then(r => r.json())
      .then(d => {
        if (d && d.success && d.username) {
          document.getElementById("userInput").value = d.username;
        }
      })
      .catch(() => {});

    document.getElementById('dateInput').value = new Date().toLocaleString();
  });

  const scannerOverlay = document.getElementById('scannerOverlay');
  const scanBarcodeBtn = document.getElementById('scanBarcodeBtn');
  const closeScannerBtn = document.getElementById('closeScannerBtn');
  const barcodeInput = document.getElementById('invInput');
  const barcodeStatus = document.getElementById('barcodeStatus');
  const setInput = document.getElementById('setInput');
  let html5QrCode = null, isScannerActive = false;

  function getCameraErrorMessage(error) {
    const isHttp = location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1';
    if (isHttp) return 'Camera requires HTTPS. Please access via HTTPS or localhost.';
    const msg = String(error || '');
    if (/NotAllowedError|PermissionDenied/i.test(msg)) return 'Camera permission denied. Allow camera access in browser settings then reload.';
    if (/NotFoundError|DevicesNotFound/i.test(msg)) return 'No camera found on this device.';
    if (/NotReadableError|TrackStartError/i.test(msg)) return 'Camera is busy. Close other apps using the camera and try again.';
    return 'Camera error: ' + msg;
  }

  scanBarcodeBtn.onclick = function () {
    if (typeof Html5Qrcode === 'undefined') {
      showCustomAlert('QR scanner library could not load. Please refresh and try again.', '#ff4d4f', true);
      return;
    }
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
        fps: 30,
        qrbox: { width: 400, height: 400 },
        aspectRatio: 1.0,
        disableFlip: false,
        supportedScanTypes: [0, 1],
        experimental: true,
        useBarCodeDetectorIfSupported: true,
        formatsToSupport: ['qr_code', 'aztec', 'code_128', 'code_39', 'code_93', 'codabar', 'data_matrix', 'ean_13', 'ean_8', 'itf', 'pdf417', 'upc_a', 'upc_e'],
        videoConstraints: {
          width: { ideal: 1280 },
          height: { ideal: 720 },
          facingMode: 'environment'
        }
      },
      function (decodedText) {
        if (navigator.vibrate) {
          navigator.vibrate(100);
        }
        const normalizedCode = normalizeSetQrCode(decodedText);
        barcodeInput.value = normalizedCode;
        barcodeStatus.textContent = "Scanned: " + normalizedCode;
        barcodeStatus.style.color = '#2ecc71';
        autoSelectSetFromQr(normalizedCode);
        setTimeout(() => stopScanner(), 200);
      },
      function () {}
    ).catch(function (error) {
      showCustomAlert(getCameraErrorMessage(error), '#ff4d4f', true);
      stopScanner();
    });
  };
  closeScannerBtn.onclick = stopScanner;
  barcodeInput.addEventListener('change', () => autoSelectSetFromQr(barcodeInput.value));
  barcodeInput.addEventListener('blur', () => autoSelectSetFromQr(barcodeInput.value));
  barcodeInput.addEventListener('input', () => scheduleSetQrLookup(barcodeInput.value));
  barcodeInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      autoSelectSetFromQr(barcodeInput.value, true);
    }
  });

  function normalizeSetQrCode(value) {
    let code = String(value || '').trim();
    const keyedMatch = code.match(/(?:^|\b)(?:code|qr|data)\s*[:=]\s*([A-Za-z0-9_-]+)/i);
    if (keyedMatch) {
      return keyedMatch[1].trim();
    }

    try {
      const url = new URL(code);
      for (const key of ['data', 'qr', 'code', 'label_code']) {
        const paramValue = url.searchParams.get(key);
        if (paramValue) {
          return paramValue.trim();
        }
      }
    } catch (error) {}

    return code;
  }

  function setSelectByName(setName, addIfMissing = false) {
    const wanted = String(setName || '').trim();
    if (!wanted) return false;
    const wantedLower = wanted.toLowerCase();
    const match = Array.from(setInput.options).find((option) => option.value.trim().toLowerCase() === wantedLower);
    if (!match) {
      if (!addIfMissing) return false;

      const option = document.createElement('option');
      option.value = wanted;
      option.textContent = wanted;
      option.dataset.fromQrHistory = '1';
      setInput.appendChild(option);
      setInput.value = wanted;
      return true;
    }
    setInput.value = match.value;
    return true;
  }

  let lastLookupQr = '';
  let setQrLookupTimer = null;

  function scheduleSetQrLookup(qrCode) {
    clearTimeout(setQrLookupTimer);
    setQrLookupTimer = setTimeout(() => autoSelectSetFromQr(qrCode), 250);
  }

  function autoFillFromSetQrData(code, data) {
    const setName = data && data.set_name ? String(data.set_name).trim() : '';
    if (!setName) {
      barcodeStatus.textContent = 'Scanned: ' + code + ' | Set data missing';
      barcodeStatus.style.color = '#ff4d4f';
      showCustomAlert('Set data missing for this QR.', '#ff4d4f', true);
      return;
    }

    if (setSelectByName(setName, true)) {
      barcodeInput.value = code;
      barcodeStatus.textContent = 'Scanned: ' + code + ' | Set: ' + setName;
      barcodeStatus.style.color = '#2ecc71';
      document.getElementById('dateInput').value = new Date().toLocaleString();
      showCustomAlert('Data loaded from Set QR', '#4CAF50');
      return;
    }

    barcodeStatus.textContent = 'Scanned: ' + code + ' | Set not in this month list: ' + setName;
    barcodeStatus.style.color = '#ff4d4f';
    showCustomAlert('QR is for "' + setName + '", but that Set is not available in this month list.', '#ff4d4f', true);
  }

  function autoSelectSetFromQr(qrCode, forceLookup = false) {
    const code = normalizeSetQrCode(qrCode);
    if (!code || (!forceLookup && code === lastLookupQr)) return;
    lastLookupQr = code;
    barcodeInput.value = code;
    barcodeStatus.textContent = 'Looking up Set for: ' + code;
    barcodeStatus.style.color = '#fdb04c';

    fetch(API_LOOKUP_SET_BY_QR + '?qr=' + encodeURIComponent(code), {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
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
      .then((data) => {
        if (!data || !data.success) {
          barcodeStatus.textContent = 'Scanned: ' + code + ' | Set not found';
          barcodeStatus.style.color = '#ff4d4f';
          if (data && data.message) {
            showCustomAlert(data.message, '#ff4d4f', true);
          }
          return;
        }

        autoFillFromSetQrData(code, data);
      })
      .catch((error) => {
        const message = error && error.message
          ? error.message
          : 'Cannot load Set data. Please check server/API connection.';
        barcodeStatus.textContent = 'Scanned: ' + code + ' | Cannot load Set';
        barcodeStatus.style.color = '#ff4d4f';
        showCustomAlert(message, '#ff4d4f', true);
      });
  }

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

  document.getElementById('photoBtn').onclick = function() {
    document.getElementById('photoUpload').click();
  };
  document.getElementById('photoUpload').onchange = function(e) {
    const file = e.target.files[0];
    if (!file) return;
    compressImage(file, 600, 600, 0.7, function(base64) {
      const img = document.getElementById('photoPreview');
      img.src = base64;
      img.style.display = 'inline';
      img.dataset.base64 = base64;
    });
  };

  function showCustomAlert(msg, color = '#2ecc71', isError = false) {
    let div = document.getElementById('customAlert');
    if (!div) {
      div = document.createElement('div');
      div.id = "customAlert";
      div.style = "position:fixed;top:45%;left:50%;transform:translate(-50%,-50%);background:#232323;color:#2ecc71;font-size:1.3em;font-weight:bold;padding:30px 45px;border-radius:21px;box-shadow:0 4px 12px #1116;display:none;z-index:10000;text-align:center;letter-spacing:1px;";
      document.body.appendChild(div);
    }
    const icon = isError
      ? '<span style="font-size:2em;">❌</span>'
      : '<span style="font-size:2em;">✅</span>';
    div.innerHTML = msg + ' ' + icon;
    div.style.color = color;
    div.style.display = 'block';
    setTimeout(() => { div.style.display = 'none'; }, 2000);
  }

  function clearForm() {
    const setSelect = document.getElementById('setInput');
    const lastSet = setSelect.value;  // Save current selection
    const currentUser = document.getElementById('userInput').value;  // Save current user

    document.getElementById('invInput').value = '';
    // Do NOT reset setInput here, keep last selection
    // Restore the user field to maintain the logged-in user
    document.getElementById('userInput').value = currentUser;
    document.getElementById('photoPreview').style.display = 'none';
    document.getElementById('photoPreview').src = '';
    document.getElementById('photoUpload').value = '';
    document.getElementById('photoPreview').dataset.base64 = '';
    document.getElementById('dateInput').value = new Date().toLocaleString();

    // Reapply last set selection
    setSelect.value = lastSet;
  }


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

  function handleSave() {
    const saveBtn = document.getElementById('saveBtn');
    if (!document.getElementById('invInput').value.trim()) {
      showCustomAlert('Please scan or enter the INV.', '#ff4d4f', true);
      return;
    }
    if (!document.getElementById('setInput').value) {
      showCustomAlert('Please select a Set.', '#ff4d4f', true);
      return;
    }
    if (!document.getElementById('userInput').value.trim()) {
      showCustomAlert('Please enter the User.', '#ff4d4f', true);
      return;
    }
    if (!document.getElementById('photoPreview').dataset.base64) {
      showCustomAlert('Please upload or take a photo.', '#ff4d4f', true);
      return;
    }

    const formData = new FormData();
    formData.append('inv', document.getElementById('invInput').value);
    formData.append('set', document.getElementById('setInput').value);
    formData.append('user', document.getElementById('userInput').value || "default_user");
    const now = new Date();
    const pad = n => n.toString().padStart(2, '0');
    const localTime = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
    formData.append('date_time', localTime);

    const photoBase64 = document.getElementById('photoPreview').dataset.base64;
    formData.append('photo', base64ToBlob(photoBase64), 'photo.jpg');

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';

    let saveEndpoint = 'save_prepare_set.php';
    if (typeof API_SAVE_PREPARE_SET === 'string' && API_SAVE_PREPARE_SET.trim() !== '') {
      try {
        const candidate = new URL(API_SAVE_PREPARE_SET, window.location.href);
        if (candidate.origin === window.location.origin && /\/scanner\/save_prepare_set\.php$/i.test(candidate.pathname)) {
          saveEndpoint = candidate.href;
        }
      } catch (error) {}
    }

    fetch(saveEndpoint, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
      },
      body: formData
    })
    .then(async (response) => {
      const text = await response.text();
      let data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch (error) {
        throw new Error(text ? text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : 'Invalid API response');
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
        const msg = (res.message && String(res.message).trim()) ? res.message : 'Error!វិក្កយបត្រជាន់គ្នាទាក់ទងទៅAccountant❌';
        showCustomAlert(msg, '#ff4d4f', true);
      }
    })
    .catch((error) => {
      const message = error && error.message ? error.message : 'Network/API error';
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

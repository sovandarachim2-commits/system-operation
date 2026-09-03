<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Product Entry</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons" />
  <script src="html5-qrcode.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/4.1.1/tesseract.min.js"></script>
  <script src="api_config.php"></script>
  <style>
    body {margin:0; background:#1a1a1a; color:#fdb04c; font-family:sans-serif; min-height:100vh; padding-bottom:80px;}
    .card {background:#232323; border-radius:20px; box-shadow:0 2px 8px #1114; padding:18px 14px; margin:15px 10px;}
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
    #qr-reader {width:95vw; max-width:100%; height:100vh; background:#000;}
    #closeScannerBtn {position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:#ff726f;color:white;padding:16px 28px;border:none;border-radius:14px;font-size:1.2em;z-index:9998;}
    #scanStatus {position:fixed;top:40px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.8);color:#2ecc71;padding:15px 30px;border-radius:10px;font-size:1.1em;z-index:9998;}
    .save-btn {background:#2ecc71; color:white; border:none; border-radius:10px; padding:12px 24px; font-size:1.15em; margin:30px 10px 10px 10px; cursor:pointer; font-weight:bold;}
    .history-btn {background:#2980b9; color:white; border:none; border-radius:10px; padding:12px 24px; font-size:1.15em; margin:10px 10px 30px 10px; cursor:pointer; font-weight:bold;}
    .home-btn {background:#4400ff; color:white; border:none; border-radius:10px; padding:12px 24px; font-size:1.15em; margin:10px 10px 30px 10px; cursor:pointer; font-weight:bold;}
    #addSubSetBtn {width:100%; border-radius:9px; font-weight:bold;}
    .remove-sub-btn {background:#e74c3c; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:1.02em; padding:6px 12px;}
  </style>
</head>
<body>
  <!-- BARCODE SCANNER -->
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">qr_code</span>BARCODE</div>
    <div class="scan-status" id="barcodeStatus">No code scanned</div>
    <input type="text" id="barcodeInput" class="input-field" placeholder="Barcode / QR" />
    <button class="card-action" id="scanBarcodeBtn"><span class="material-icons">qr_code_scanner</span></button>
  </div>
  <div id="scannerOverlay">
    <div id="qr-reader"></div>
    <div id="scanStatus">📱 Point camera at invoice...</div>
    <button id="closeScannerBtn">Close</button>
  </div>

  <!-- PHONE NUMBER (Live Scan - Khmer Support) -->
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">phone</span>PHONE NUMBER</div>
    <div style="display:flex;align-items:center;gap:8px;">
      <input type="text" id="phoneInput" class="input-field" placeholder="Phone Number" style="flex:1;" />
      <button class="camera-btn" id="scanPhoneBtn" style="background:#ff9800;"><span class="material-icons">photo_camera</span></button>
    </div>
    <div id="phoneStatus" style="font-size:0.9em;color:#ffa726;margin-top:5px;"></div>
  </div>

  <!-- PAID/UNPAID -->
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">paid</span>PAID/UNPAID</div>
    <select id="paidInput" class="input-field">
      <option>- Please select -</option>
      <option>Paid</option>
      <option>Unpaid</option>
      <option>Partial</option>
    </select>
  </div>

  <!-- AMOUNT -->
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">calculate</span>AMOUNT</div>
    <input type="number" id="amountInput" class="input-field" placeholder="Amount" />
  </div>

  <!-- Set Control -->
  <div class="card" id="setTypeCard">
    <div class="card-title"><span class="material-icons card-icon">settings</span>SET TYPE</div>
    <select id="setTypeInput" class="input-field">
      <option value="" disabled selected>-Select-Set-</option>
      <option value="non-set">Non-Set</option>
      <option value="set">Set</option>

    </select>
    <div id="luckySetHint" style="display:none;font-size:0.95em;color:#ffb74d;margin-top:10px;line-height:1.4;"></div>
    <!-- Container for Set options -->
    <div id="setOptions" style="display:none; margin-top:12px;">
      <div style="display:flex; align-items:center; gap:8px;">
        <input type="text" id="setQrInput" class="input-field" placeholder="Scan Set QR" style="flex:1;" readonly />
        <button class="camera-btn" id="scanSetQrBtn" style="background:#ffb44c;">
          <span class="material-icons">qr_code_scanner</span>
        </button>
      </div>
      <button id="addSubSetBtn" class="camera-btn" style="margin-top: 10px; background:#2980b9;">
        Add Set      </button>
      <div id="subNameContainer" style="margin-top:8px;"></div>
    </div>
  </div>

  <!-- INV PHOTO -->
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">receipt</span>INV PHOTO</div>
    <input type="file" accept="image/*" capture="environment" style="display:none" id="invPhotoUpload" />
    <button class="camera-btn" id="invPhotoBtn"><span class="material-icons">photo_camera</span>Take Photo</button>
    <img id="invPhotoPreview" class="img-preview" style="display:none" />
  </div>

  <!-- FULL PHOTO -->
  <div class="card">
    <div class="card-title"><span class="material-icons card-icon">photo_library</span>FULL PHOTO</div>
    <input type="file" accept="image/*" capture="environment" style="display:none" id="fullPhotoUpload" />
    <button class="camera-btn" id="fullPhotoBtn"><span class="material-icons">photo_camera</span>Take Photo</button>
    <img id="fullPhotoPreview" class="img-preview" style="display:none" />
  </div>

  <!-- USER & DATE -->
  <div class="card input-row">
    <input type="text" id="userInput" class="input-field" placeholder="User" readonly />
    <input type="text" id="dateInput" class="input-field" disabled />
  </div>

  <!-- BUTTONS -->
  <button class="save-btn" id="saveBtn" type="button">Save to Database</button>
  <button class="history-btn" onclick="window.location.href='view_prepare_items.php'">View History</button>
  <button class="home-btn" onclick="window.location.href='home.php'">Back to Home</button>

  <!-- CUSTOM ALERT -->
  <div id="customAlert" style="display:none;position:fixed;top:45%;left:50%;transform:translate(-50%,-50%);background:#232323;color:#2ecc71;font-size:1.3em;font-weight:bold;padding:30px 45px;border-radius:21px;box-shadow:0 4px 12px #1116;z-index:10000;text-align:center;"></div>

<script>
  /** After invoice scan/load: lucky box qty + allowed set names from order */
  let luckyOrderContext = null;

  // ===== ORDER CACHE (offline support) =====
  const ORDERS_CACHE_KEY = 'prepare_items_orders_v1';

  function loadOrdersCache() {
    fetch('get_orders_cache.php', { headers: { 'Accept': 'application/json' } })
      .then(r => r.json())
      .then(list => {
        if (!Array.isArray(list)) return;
        const map = {};
        list.forEach(o => { if (o.inv) map[String(o.inv)] = o; });
        try { localStorage.setItem(ORDERS_CACHE_KEY, JSON.stringify({ ts: Date.now(), map })); } catch (e) {}
      })
      .catch(() => {});
  }

  function getOrderFromCache(inv) {
    try {
      const raw = localStorage.getItem(ORDERS_CACHE_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      return (parsed && parsed.map && parsed.map[String(inv)]) || null;
    } catch (e) { return null; }
  }

  function cacheOneOrder(data) {
    try {
      const raw = localStorage.getItem(ORDERS_CACHE_KEY);
      const parsed = raw ? JSON.parse(raw) : { ts: Date.now(), map: {} };
      if (!parsed.map) parsed.map = {};
      parsed.map[String(data.inv)] = {
        inv: data.inv,
        phone: data.phone || '',
        status: data.status || '',
        amount: data.amount || 0,
        lucky_box_qty: data.lucky_box_qty || 0,
        lucky_set_names: data.lucky_set_names || [],
      };
      localStorage.setItem(ORDERS_CACHE_KEY, JSON.stringify(parsed));
    } catch (e) {}
  }

  function formatLocalDateTime(dateObj) {
    const yyyy = dateObj.getFullYear();
    const mm = String(dateObj.getMonth() + 1).padStart(2, '0');
    const dd = String(dateObj.getDate()).padStart(2, '0');
    const hh = String(dateObj.getHours()).padStart(2, '0');
    const min = String(dateObj.getMinutes()).padStart(2, '0');
    const ss = String(dateObj.getSeconds()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
  }

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

    document.getElementById('dateInput').value = formatLocalDateTime(new Date());

    document.getElementById('saveBtn').addEventListener('click', function () {
      handleSave();
    });

    loadOrdersCache();
  });

    // ===== LOOKUP ORDER BY INVOICE (order_code) =====
  function fillFromInvoiceCode(invCode) {
    invCode = invCode.trim();
    if (!invCode) return;

    barcodeInput.value = invCode;
    barcodeStatus.textContent = "Scanned: " + invCode;

    const cached = getOrderFromCache(invCode);
    if (cached) {
      autoFillFromOrder(cached);
      return;
    }

    fetch('get_order.php?inv=' + encodeURIComponent(invCode))
      .then(r => r.json())
      .then(res => {
        if (res.result === 'success' && res.data) {
          cacheOneOrder(res.data);
          autoFillFromOrder(res.data);
        } else if (res.result === 'not_found') {
          luckyOrderContext = null;
          updateLuckyHint();
          showCustomAlert('Invoice not found in orders', '#ff4d4f', true);
        } else {
          showCustomAlert('Order error: ' + (res.message || ''), '#ff4d4f', true);
        }
      })
      .catch((err) => {
        console.error('Fetch error:', err);
        showCustomAlert('Order lookup error: ' + err, '#ff4d4f', true);
      });
  }

  // ===== AUTO-FILL FIELDS FROM ORDER DATA =====
  function autoFillFromOrder(data) {
    const rawStatus = (data.status || '').toString().toLowerCase();

    if (rawStatus === 'cancelled' || rawStatus === 'canceled') {
      showCustomAlert('Order is CANCELLED — cannot save prepare items for this invoice.', '#ff4d4f', true);
      return;
    }

    if (data.inv) {
      barcodeInput.value = data.inv;
    }
    if (data.phone) {
      document.getElementById('phoneInput').value = data.phone;
    }
    if (data.amount) {
      document.getElementById('amountInput').value = data.amount;
    }
    const paidSelect = document.getElementById('paidInput');
    if (rawStatus === 'paid')    paidSelect.value = 'Paid';
    if (rawStatus === 'unpaid')  paidSelect.value = 'Unpaid';
    if (rawStatus === 'partial') paidSelect.value = 'Partial';

    luckyOrderContext = {
      qty: parseInt(data.lucky_box_qty, 10) || 0,
      names: Array.isArray(data.lucky_set_names) ? data.lucky_set_names : [],
      status: rawStatus,
    };
    document.getElementById('dateInput').value = formatLocalDateTime(new Date());
    updateLuckyHint();
    syncSubSetRowsToLuckyQty();
    showCustomAlert('✅ Data loaded from order', '#4CAF50');
  }

  function updateLuckyHint() {
    updateAddSetButtonVisibility();
    const hint = document.getElementById('luckySetHint');
    const st = document.getElementById('setTypeInput').value;
    if (!hint) return;
    if (st === 'non-set' && luckyOrderContext && luckyOrderContext.qty >= 1) {
      hint.style.display = 'block';
      hint.style.color = '#ff8a80';
      hint.innerHTML =
        '<strong>Cannot use Save Data:</strong> Order has lucky box qty <strong>' +
        luckyOrderContext.qty +
        '</strong>.';
      return;
    }
    hint.style.color = '#ffb74d';
    if (st !== 'set') {
      hint.style.display = 'none';
      hint.textContent = '';
      return;
    }
    hint.style.display = 'block';
    if (!luckyOrderContext) {
      hint.textContent = 'Scan or enter the invoice (order code) first. Set mode requires an order with lucky box line(s).';
      return;
    }
    if (luckyOrderContext.qty < 1) {
      hint.textContent = 'This order has no lucky box line. Use Non-Set, or scan a different invoice.';
      return;
    }
    const names = luckyOrderContext.names.length
      ? luckyOrderContext.names.join(', ')
      : '(set names from order)';
    const q = luckyOrderContext.qty;
    const extra = Math.max(0, q - 1);
    const slotNote =
      q === 1
        ? 'Fill the <strong>main</strong> Set QR field only (1 = lucky box qty).'
        : 'Fill <strong>' + q + '</strong> Set QRs total: <strong>main</strong> field + <strong>' + extra + '</strong> row(s) below (matches lucky box qty).';
    hint.innerHTML =
      slotNote +
      ' Each label must match lucky product: <strong>' +
      names +
      '</strong>. Each set QR must already be saved in <strong>Prepare Set</strong> (same code as INV, same set).';
  }

  function updateAddSetButtonVisibility() {
    const btn = document.getElementById('addSubSetBtn');
    if (!btn) return;
    const st = document.getElementById('setTypeInput').value;
    if (st === 'set' && luckyOrderContext && luckyOrderContext.qty >= 1) {
      btn.style.display = 'none';
    } else {
      btn.style.display = '';
    }
  }

  // ============ CAMERA ERROR HELPER ============
  function getCameraErrorMessage(error) {
    const isHttp = location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1';
    if (isHttp) return 'Camera requires HTTPS. Please access via HTTPS or localhost.';
    const msg = String(error || '');
    if (/NotAllowedError|PermissionDenied/i.test(msg)) return 'Camera permission denied. Allow camera access in browser settings then reload.';
    if (/NotFoundError|DevicesNotFound/i.test(msg)) return 'No camera found on this device.';
    if (/NotReadableError|TrackStartError/i.test(msg)) return 'Camera is busy. Close other apps using the camera and try again.';
    return 'Camera error: ' + msg;
  }
  function isCameraSupported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }

  // ============ QR/BARCODE SCAN ============
  const scannerOverlay = document.getElementById('scannerOverlay');
  const scanBarcodeBtn = document.getElementById('scanBarcodeBtn');
  const closeScannerBtn = document.getElementById('closeScannerBtn');
  const barcodeInput = document.getElementById('barcodeInput');
  const barcodeStatus = document.getElementById('barcodeStatus');
  let html5QrCode = null;
  let isScannerActive = false;

  scanBarcodeBtn.onclick = function() {
    if (!isCameraSupported()) { showCustomAlert(getCameraErrorMessage(null), '#ff4d4f', true); return; }
    scannerOverlay.style.display = 'flex';
    if (!html5QrCode) html5QrCode = new Html5Qrcode('qr-reader');
    if (isScannerActive) return;
    isScannerActive = true;
    html5QrCode.start(
      { facingMode: 'environment' },
      { fps: 20, qrbox: 250, disableFlip: false },
      function(decodedText) {
        console.log('decodedText = [' + decodedText + ']');
        fillFromInvoiceCode(decodedText);
        stopScanner();
      },
      function() {}
    ).catch(function(error) {
      showCustomAlert(getCameraErrorMessage(error), '#ff4d4f', true);
      stopScanner();
    });
  };

  closeScannerBtn.onclick = stopScanner;

  // Auto-fill when typing manually or using a hardware barcode scanner
  let barcodeInputTimer = null;
  barcodeInput.addEventListener('input', function () {
    clearTimeout(barcodeInputTimer);
    const val = barcodeInput.value.trim();
    if (!val) return;
    barcodeInputTimer = setTimeout(() => fillFromInvoiceCode(val), 600);
  });
  barcodeInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      clearTimeout(barcodeInputTimer);
      const val = barcodeInput.value.trim();
      if (val) fillFromInvoiceCode(val);
    }
  });

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

  // ============ KHMER TO ENGLISH DIGIT CONVERSION ============
  function convertKhmerToEnglish(text) {
    const khmerDigits = ['០', '១', '២', '៣', '៤', '៥', '៦', '៧', '៨', '៩'];
    const englishDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    let converted = text;
    for (let i = 0; i < 10; i++) {
      converted = converted.split(khmerDigits[i]).join(englishDigits[i]);
    }
    return converted;
  }

  function extractPhoneNumber(text) {
    const englishText = convertKhmerToEnglish(text);
    const phoneMatch = englishText.match(/0\d{8,}/);
    return phoneMatch ? phoneMatch[0] : null;
  }

  // ============ LIVE PHONE SCAN (Continuous) - KHMER SUPPORT ============
  const scanPhoneBtn = document.getElementById('scanPhoneBtn');
  const phoneInput = document.getElementById('phoneInput');
  const phoneStatus = document.getElementById('phoneStatus');
  const scanStatus = document.getElementById('scanStatus');
  let liveScanner = null;
  let isPhoneScannerActive = false;
  let lastDetectedPhone = null;
  let lastDetectTime = 0;

  scanPhoneBtn.onclick = function() {
    if (!isCameraSupported()) { showCustomAlert(getCameraErrorMessage(null), '#ff4d4f', true); return; }
    scannerOverlay.style.display = 'flex';
    phoneStatus.textContent = 'Opening camera...';
    phoneStatus.style.color = '#2196f3';
    if (!liveScanner) liveScanner = new Html5Qrcode('qr-reader');
    if (isPhoneScannerActive) return;
    isPhoneScannerActive = true;
    lastDetectedPhone = null;
    scanStatus.textContent = '📱 Point camera at invoice...';
    liveScanner.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 300, height: 300 } },
      function(decodedText) {},
      function(error) {}
    ).then(() => { analyzePhoneFrames(); }).catch(function(error) {
      showCustomAlert(getCameraErrorMessage(error), '#ff4d4f', true);
      stopPhoneScanner();
    });
  };

  function analyzePhoneFrames() {
    if (!isPhoneScannerActive) return;
    const video = document.querySelector('#qr-reader video');
    if (video && video.readyState === video.HAVE_ENOUGH_DATA) {
      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0);
      const frameData = canvas.toDataURL('image/jpeg', 0.5);
      Tesseract.recognize(frameData, ['eng', 'khm'], { logger: () => {} }).then(({ data: { text } }) => {
        const phone = extractPhoneNumber(text);
        if (phone && phone !== lastDetectedPhone) {
          lastDetectedPhone = phone;
          lastDetectTime = Date.now();
          phoneInput.value = phone;
          scanStatus.textContent = '✅ Detected: ' + phone;
          scanStatus.style.color = '#4caf50';
          setTimeout(() => {
            stopPhoneScanner();
            showCustomAlert('✅ Phone: ' + phone);
          }, 1500);
        }
      }).catch(() => {});
    }
    setTimeout(analyzePhoneFrames, 800);
  }

  function stopPhoneScanner() {
    if (!isPhoneScannerActive) {
      scannerOverlay.style.display = 'none';
      phoneStatus.textContent = '';
      return;
    }
    isPhoneScannerActive = false;
    liveScanner.stop().then(() => {
      scannerOverlay.style.display = 'none';
      phoneStatus.textContent = '';
    }).catch(() => {
      scannerOverlay.style.display = 'none';
      phoneStatus.textContent = '';
    });
  }

  // ============ IMAGE COMPRESSION ============
  let invPhotoBlob = null;
  let fullPhotoBlob = null;
  let invPhotoPreviewUrl = '';
  let fullPhotoPreviewUrl = '';

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
        canvas.toBlob(function(blob) {
          callback(blob);
        }, 'image/jpeg', quality);
      };
      img.src = e.target.result;
    };
    reader.readAsDataURL(file);
  }

  // ============ INV PHOTO ============
  document.getElementById('invPhotoBtn').onclick = function() {
    document.getElementById('invPhotoUpload').click();
  };
  document.getElementById('invPhotoUpload').onchange = function(e) {
    const file = e.target.files[0];
    if (!file) return;
    compressImage(file, 600, 600, 0.7, function(blob) {
      if (!blob) return;
      const img = document.getElementById('invPhotoPreview');
      if (invPhotoPreviewUrl) {
        URL.revokeObjectURL(invPhotoPreviewUrl);
      }
      invPhotoBlob = blob;
      invPhotoPreviewUrl = URL.createObjectURL(blob);
      img.src = invPhotoPreviewUrl; img.style.display='inline';
    });
  };

  // ============ FULL PHOTO ============
  document.getElementById('fullPhotoBtn').onclick = function() {
    document.getElementById('fullPhotoUpload').click();
  };
  document.getElementById('fullPhotoUpload').onchange = function(e) {
    const file = e.target.files[0];
    if (!file) return;
    compressImage(file, 600, 600, 0.7, function(blob) {
      if (!blob) return;
      const img = document.getElementById('fullPhotoPreview');
      if (fullPhotoPreviewUrl) {
        URL.revokeObjectURL(fullPhotoPreviewUrl);
      }
      fullPhotoBlob = blob;
      fullPhotoPreviewUrl = URL.createObjectURL(blob);
      img.src = fullPhotoPreviewUrl; img.style.display='inline';
    });
  };

  // Set controls: show/hide setOptions and allow adding/removing sub sets
  const setTypeInput = document.getElementById('setTypeInput');
  const setOptions = document.getElementById('setOptions');
  const setQrInput = document.getElementById('setQrInput');
  const addSubSetBtn = document.getElementById('addSubSetBtn');
  const subNameContainer = document.getElementById('subNameContainer');

  setTypeInput.addEventListener('change', () => {
    if (setTypeInput.value === 'set') {
      setOptions.style.display = 'block';
      setQrInput.value = '';
      subNameContainer.innerHTML = '';
      syncSubSetRowsToLuckyQty();
    } else {
      setOptions.style.display = 'none';
      setQrInput.value = '';
      subNameContainer.innerHTML = '';
    }
    updateLuckyHint();
  });

  function stopSubSetScanner() {
    if (window.isSubSetScannerActive && window.subSetQrInstance) {
      window.subSetQrInstance.stop().then(() => {
        window.isSubSetScannerActive = false;
        const overlay = document.getElementById('subSetScannerOverlay');
        if (overlay) overlay.style.display = 'none';
      }).catch(() => {
        window.isSubSetScannerActive = false;
        const overlay = document.getElementById('subSetScannerOverlay');
        if (overlay) overlay.style.display = 'none';
      });
    }
  }

  function appendSubSetRow() {
    const newInputWrapper = document.createElement('div');
    newInputWrapper.style = 'display:flex; gap:8px; margin-bottom:8px; align-items:center;';

    const newInput = document.createElement('input');
    newInput.type = 'text';
    newInput.className = 'input-field';
    newInput.placeholder = 'Set QR';
    newInput.style.flex = '1';

    const scanBtn = document.createElement('button');
    scanBtn.type = 'button';
    scanBtn.className = 'camera-btn';
    scanBtn.style.background = '#ffb44c';
    scanBtn.innerHTML = '<span class="material-icons">qr_code_scanner</span>';

    scanBtn.onclick = function() {
      let subScanOverlay = document.getElementById('subSetScannerOverlay');
      if (!subScanOverlay) {
        subScanOverlay = document.createElement('div');
        subScanOverlay.id = 'subSetScannerOverlay';
        subScanOverlay.style = `
        position: fixed; top:0; left:0; width:100vw; height:100vh;
        background: rgba(30,30,30,0.96); z-index: 10001; display:flex;
        align-items:center; justify-content:center; flex-direction: column;
      `;
        subScanOverlay.innerHTML = `
        <div id="sub-set-qr-reader" style="width: 95vw; max-width: 100%; height: 80vh; background:#000;"></div>
        <button id="closeSubSetScannerBtn" style="position: fixed; bottom: 30px; left:50%; transform: translateX(-50%);
          background:#ff726f; color:white; padding:16px 28px; border:none; border-radius:14px; font-size:1.2em; z-index:10002;">Close</button>
      `;
        document.body.appendChild(subScanOverlay);
        document.getElementById('closeSubSetScannerBtn').onclick = stopSubSetScanner;
      }
      subScanOverlay.style.display = 'flex';

      window.subSetQrInstance = new Html5Qrcode('sub-set-qr-reader');
      window.isSubSetScannerActive = true;
      window.subSetQrInstance.start(
        { facingMode: 'environment' },
        { fps: 5, qrbox: 350 },
        function(decodedText) {
          newInput.value = decodedText;
          stopSubSetScanner();
        },
        function() {}
      ).catch(function(error) {
        showCustomAlert(getCameraErrorMessage(error), '#ff4d4f', true);
        stopSubSetScanner();
      });
    };

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.textContent = 'Remove';
    removeBtn.className = 'remove-sub-btn';
    removeBtn.onclick = () => {
      subNameContainer.removeChild(newInputWrapper);
    };

    newInputWrapper.appendChild(newInput);
    newInputWrapper.appendChild(scanBtn);
    newInputWrapper.appendChild(removeBtn);
    subNameContainer.appendChild(newInputWrapper);
  }

  function syncSubSetRowsToLuckyQty() {
    if (setTypeInput.value !== 'set' || !luckyOrderContext || luckyOrderContext.qty < 1) {
      return;
    }
    const needExtra = Math.max(0, luckyOrderContext.qty - 1);
    subNameContainer.innerHTML = '';
    for (let i = 0; i < needExtra; i++) {
      appendSubSetRow();
    }
  }

  addSubSetBtn.onclick = function() {
    if (luckyOrderContext && luckyOrderContext.qty >= 1) {
      const maxExtra = Math.max(0, luckyOrderContext.qty - 1);
      const cur = subNameContainer.querySelectorAll('input').length;
      if (cur >= maxExtra) {
        showCustomAlert(
          'Lucky box qty is ' + luckyOrderContext.qty + '. Only ' + maxExtra + ' extra row(s) besides the main Set QR (' + luckyOrderContext.qty + ' QRs total).',
          '#ff4d4f',
          true
        );
        return;
      }
    }
    appendSubSetRow();
  };

  // QR Scanner for Set QR Code
  let html5QrCodeSet = null;
  let isSetScannerActive = false;
  const scanSetQrBtn = document.getElementById('scanSetQrBtn');

  scanSetQrBtn.onclick = function() {
    if (!isCameraSupported()) { showCustomAlert(getCameraErrorMessage(null), '#ff4d4f', true); return; }
    if (isSetScannerActive) return;

    let overlay = document.getElementById('setScannerOverlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'setScannerOverlay';
      overlay.style = `
        position: fixed; top:0; left:0; width:100vw; height:100vh;
        background: rgba(30,30,30,0.96); z-index: 10000; display:flex;
        align-items:center; justify-content:center; flex-direction: column;
      `;
      overlay.innerHTML = `
        <div id="set-qr-reader" style="width: 95vw; max-width: 100%; height: 80vh; background:#000;"></div>
        <button id="closeSetScannerBtn" style="position: fixed; bottom: 30px; left:50%; transform: translateX(-50%);
          background:#ff726f; color:white; padding:16px 28px; border:none; border-radius:14px; font-size:1.2em; z-index:10001;">Close</button>
      `;
      document.body.appendChild(overlay);
      document.getElementById('closeSetScannerBtn').onclick = stopSetScanner;
    }
    overlay.style.display = 'flex';

    if (!html5QrCodeSet) html5QrCodeSet = new Html5Qrcode('set-qr-reader');
    isSetScannerActive = true;

    html5QrCodeSet.start(
      { facingMode: 'environment' },
      { fps: 5, qrbox: 350 },
      (decodedText) => {
        setQrInput.value = decodedText;
        stopSetScanner();
      },
      (error) => {}
    ).catch(error => {
      showCustomAlert(getCameraErrorMessage(error), '#ff4d4f', true);
      stopSetScanner();
    });
  };

  function stopSetScanner() {
    if (!isSetScannerActive) return;
    html5QrCodeSet.stop().then(() => {
      isSetScannerActive = false;
      const overlay = document.getElementById('setScannerOverlay');
      if (overlay) overlay.style.display = 'none';
    }).catch(() => {
      isSetScannerActive = false;
      const overlay = document.getElementById('setScannerOverlay');
      if (overlay) overlay.style.display = 'none';
    });
  }

  // ============ CUSTOM ALERT ============
  function showCustomAlert(msg, color = '#2ecc71', isError = false) {
    const alertDiv = document.getElementById('customAlert');
    const icon = isError ? '<span style="font-size:2em;">❌</span>' : '<span style="font-size:2em;">✅</span>';
    alertDiv.innerHTML = msg + ' ' + icon;
    alertDiv.style.color = color;
    alertDiv.style.display = 'block';
    setTimeout(() => { alertDiv.style.display = 'none'; }, isError ? 3000 : 2000);
  }

  // ============ CLEAR FORM ============
  function clearForm() {
    const paidSelect = document.getElementById('paidInput');
    const lastPaid = paidSelect.value;
    const setTypeInput = document.getElementById('setTypeInput');

    document.getElementById('barcodeInput').value = '';
    document.getElementById('phoneInput').value = '';
    document.getElementById('amountInput').value = '';
    document.getElementById('invPhotoPreview').style.display = 'none';
    document.getElementById('fullPhotoPreview').style.display = 'none';
    document.getElementById('invPhotoPreview').src = '';
    document.getElementById('fullPhotoPreview').src = '';
    document.getElementById('invPhotoUpload').value = '';
    document.getElementById('fullPhotoUpload').value = '';
    if (invPhotoPreviewUrl) {
      URL.revokeObjectURL(invPhotoPreviewUrl);
      invPhotoPreviewUrl = '';
    }
    if (fullPhotoPreviewUrl) {
      URL.revokeObjectURL(fullPhotoPreviewUrl);
      fullPhotoPreviewUrl = '';
    }
    invPhotoBlob = null;
    fullPhotoBlob = null;
    document.getElementById('dateInput').value = formatLocalDateTime(new Date());
    document.getElementById('barcodeStatus').textContent = 'No code scanned';
    setQrInput.value = '';
    subNameContainer.innerHTML = '';
    paidSelect.value = lastPaid;
    setTypeInput.value = "";  // Reset to -Select-Set- placeholder
    luckyOrderContext = null;
    updateLuckyHint();

  }

  // ============ SAVE TO DATABASE ============
  async function handleSave() {
    console.log('Save button clicked');
    
    const formData = new FormData();

    const barcode = document.getElementById('barcodeInput').value.trim();
    const phone = document.getElementById('phoneInput').value.trim();
    const paid = document.getElementById('paidInput').value;
    const amount = document.getElementById('amountInput').value.trim();
    let user = document.getElementById('userInput').value.trim();
    const setType = setTypeInput.value;
    const setQr = setQrInput.value.trim();

    // Gather all sub names into array
    const subNameInputs = document.querySelectorAll('#subNameContainer input');
    const subNames = [];
    subNameInputs.forEach(input => {
      if (input.value.trim() !== '') {
        subNames.push(input.value.trim());
      }
    });

    if (!barcode) { console.log('Missing barcode'); showCustomAlert('Enter Barcode', '#ff4d4f', true); return; }
    if (luckyOrderContext && (luckyOrderContext.status === 'cancelled' || luckyOrderContext.status === 'canceled')) {
      showCustomAlert('Order is CANCELLED — cannot save prepare items for this invoice.', '#ff4d4f', true); return;
    }
    if (!phone) { console.log('Missing phone'); showCustomAlert('Enter Phone Number', '#ff4d4f', true); return; }
    if (paid === "" || paid === "- Please select -") { console.log('Missing paid status'); showCustomAlert('Select Paid/Unpaid', '#ff4d4f', true); return; }
    if (!amount) { console.log('Missing amount'); showCustomAlert('Enter Amount', '#ff4d4f', true); return; }
    if (setType !== 'non-set' && setType !== 'set') {
      showCustomAlert('Error!សូមជ្រើសរើស Set-Type ជាមុនសិន.❌', '#ff4d4f', true);
      return;
    }
    if (setType === 'non-set') {
      let luckyQtyCheck = null;
      if (luckyOrderContext) {
        luckyQtyCheck = luckyOrderContext.qty;
      } else {
        const cachedOrder = getOrderFromCache(barcode);
        if (cachedOrder) {
          const cs = (cachedOrder.status || '').toLowerCase();
          if (cs === 'cancelled' || cs === 'canceled') { showCustomAlert('Order is CANCELLED — cannot save prepare items.', '#ff4d4f', true); return; }
          luckyQtyCheck = parseInt(cachedOrder.lucky_box_qty, 10) || 0;
          luckyOrderContext = { qty: luckyQtyCheck, names: cachedOrder.lucky_set_names || [], status: cs };
        } else {
          try {
            const r = await fetch('get_order.php?inv=' + encodeURIComponent(barcode));
            const j = await r.json();
            if (j.result === 'success' && j.data) {
              const cs = (j.data.status || '').toLowerCase();
              if (cs === 'cancelled' || cs === 'canceled') { showCustomAlert('Order is CANCELLED — cannot save prepare items.', '#ff4d4f', true); return; }
              luckyQtyCheck = parseInt(j.data.lucky_box_qty, 10) || 0;
              cacheOneOrder(j.data);
            }
          } catch (e) {
            showCustomAlert('Could not verify order for lucky box.', '#ff4d4f', true);
            return;
          }
        }
      }
      if (luckyQtyCheck !== null && luckyQtyCheck >= 1) {
        showCustomAlert(
          'This order has lucky box (qty ' + luckyQtyCheck + '). Non-Set is not allowed.',
          '#ff4d4f',
          true
        );
        return;
      }
    }
    if (setType === 'set') {
      if (!barcode) {
        showCustomAlert('Enter invoice / order code before Set save.', '#ff4d4f', true);
        return;
      }
      if (!luckyOrderContext) {
        const cachedOrder = getOrderFromCache(barcode);
        if (cachedOrder) {
          const cs = (cachedOrder.status || '').toLowerCase();
          if (cs === 'cancelled' || cs === 'canceled') { showCustomAlert('Order is CANCELLED — cannot save prepare items.', '#ff4d4f', true); return; }
          luckyOrderContext = {
            qty: parseInt(cachedOrder.lucky_box_qty, 10) || 0,
            names: Array.isArray(cachedOrder.lucky_set_names) ? cachedOrder.lucky_set_names : [],
            status: cs,
          };
          updateLuckyHint();
        } else {
          try {
            const r = await fetch('get_order.php?inv=' + encodeURIComponent(barcode));
            const j = await r.json();
            if (j.result !== 'success' || !j.data) {
              showCustomAlert('Order not found. Scan invoice QR first.', '#ff4d4f', true);
              return;
            }
            const cs = (j.data.status || '').toLowerCase();
            if (cs === 'cancelled' || cs === 'canceled') { showCustomAlert('Order is CANCELLED — cannot save prepare items.', '#ff4d4f', true); return; }
            luckyOrderContext = {
              qty: parseInt(j.data.lucky_box_qty, 10) || 0,
              names: Array.isArray(j.data.lucky_set_names) ? j.data.lucky_set_names : [],
            };
            cacheOneOrder(j.data);
            updateLuckyHint();
          } catch (e) {
            showCustomAlert('Could not load order for lucky box check.', '#ff4d4f', true);
            return;
          }
        }
      }
      if (luckyOrderContext.qty < 1) {
        showCustomAlert('This order has no lucky box line. Use Non-Set.', '#ff4d4f', true);
        return;
      }
      const codes = [];
      if (setQr.trim() !== '') codes.push(setQr.trim());
      subNames.forEach(function (s) {
        if (s && String(s).trim() !== '') codes.push(String(s).trim());
      });
      if (codes.length !== luckyOrderContext.qty) {
        showCustomAlert(
          'Lucky box qty is ' + luckyOrderContext.qty + '. Scan exactly that many set QR labels (main field + Add Set rows). You have ' + codes.length + '.',
          '#ff4d4f',
          true
        );
        return;
      }
      const uniq = new Set(codes);
      if (uniq.size !== codes.length) {
        showCustomAlert('Each set QR label must be different (no duplicates).', '#ff4d4f', true);
        return;
      }
    }
    if (setType === 'set' && !setQr) {
      showCustomAlert('Please scan or enter the first Set QR', '#ff4d4f', true);
      return;
    }
    if (!user) { 
      // Try to get user from session if field is empty
      user = document.getElementById('userInput').value.trim();
      if (!user) {
        showCustomAlert('User not loaded. Please wait and try again.', '#ff4d4f', true); 
        return;
      }
    }
    if (!invPhotoBlob) { showCustomAlert('Take INV PHOTO', '#ff4d4f', true); return; }
    if (!fullPhotoBlob) { showCustomAlert('Take FULL PHOTO', '#ff4d4f', true); return; }
    


    formData.append('inv', barcode);
    formData.append('phone', phone);
    formData.append('status', paid);
    formData.append('amount', amount);
    formData.append('user', user);
    formData.append('date_time', formatLocalDateTime(new Date()));
    formData.append('inv_photo', invPhotoBlob, 'inv_photo.jpg');
    formData.append('full_photo', fullPhotoBlob, 'full_photo.jpg');
    formData.append('set_type', setType);

    if (setType === 'set') {
      formData.append('set_qr', setQr);
      formData.append('sub_names', JSON.stringify(subNames)); // send sub sets as JSON array
    }

    let saveEndpoint = 'save_prepare_items.php';
    if (typeof API_SAVE_PREPARE_ITEMS === 'string' && API_SAVE_PREPARE_ITEMS.trim() !== '') {
      try {
        const candidate = new URL(API_SAVE_PREPARE_ITEMS, window.location.href);
        const sameOrigin = candidate.origin === window.location.origin;
        const looksCorrectPath = /\/scanner\/save_prepare_items\.php$/i.test(candidate.pathname);
        if (sameOrigin && looksCorrectPath) {
          saveEndpoint = candidate.toString();
        }
      } catch (_) {
        // Keep local fallback endpoint.
      }
    }
    console.log('Validation passed, sending data to server...');
    console.log('API endpoint:', saveEndpoint);
    
    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';

    fetch(saveEndpoint, {
      method: 'POST',
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
        showCustomAlert('✅ Saved!');
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

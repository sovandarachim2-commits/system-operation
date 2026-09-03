<?php
require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin', 'cashier', 'scanner'], 'scanner_home.view');
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Product Home</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <style>
    body {
      background: #232323;
      color: #fdb04c;
      font-family: sans-serif;
      text-align: center;
      margin: 0;
      padding: 20px;
    }
    h1 {
      margin-top: 48px;
      margin-bottom: 16px;
      font-size: 2.1em;
      font-weight: bold;
      letter-spacing: 2px;
    }
    .menu-area {
      display: flex;
      flex-direction: column;
      gap: 38px;
      max-width: 340px;
      margin: 80px auto 0 auto;
    }
    .menu-btn {
      background: #ffb44c;
      color: #232323;
      font-size: 1.2em;
      padding: 30px 6px;
      border-radius: 20px;
      border: none;
      box-shadow: 0 2px 8px #1114;
      font-weight: bold;
      cursor: pointer;
      transition: background 0.2s, color 0.2s;
    }
    .menu-btn:hover {
      background: #fdb04c;
      color: #ffffff;
    }
    .return-btn {
      background: #ff6b6b;
      border: 2px solid #ff5252;
      animation: pulse 2s infinite;
    }
    .return-btn:hover {
      background: #ff5252;
      color: #ffffff;
    }
    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.05); }
      100% { transform: scale(1); }
    }
    .quick-return {
      background: #333;
      border-radius: 15px;
      padding: 15px;
      margin: 20px auto;
      max-width: 320px;
      border: 2px solid #ff6b6b;
    }
    .quick-return h3 {
      color: #ff6b6b;
      margin-bottom: 10px;
      font-size: 1.1em;
    }
    .quick-return .btn {
      background: #ff6b6b;
      color: white;
      border: none;
      border-radius: 8px;
      padding: 8px 16px;
      margin: 5px;
      cursor: pointer;
      font-weight: bold;
    }
    .quick-return .btn:hover {
      background: #ff5252;
    }
    .icon {
      font-size: 2em;
      vertical-align: middle;
      margin-right: 10px;
    }
    /* Back button on left for desktop */
    .back-container {
      position: absolute;
      top: 18px;
      left: 32px;
      z-index: 20;
      display: none; /* Hidden on mobile by default */
    }
    /* Profile + View Items: flex row, top-right */
    .profile-container {
      position: absolute; 
      top: 18px; 
      right: 32px; 
      z-index: 20;
      display: flex;
      flex-direction: row;
      align-items: center;
    }
    /* Show back button on left for desktop, hide from top-right */
    @media (min-width: 768px) {
      .back-container {
        display: block;
      }
      #backBtnMobile {
        display: none;
      }
    }
    .profile-dropdown {
      display: none; 
      position: absolute; 
      left: 0; 
      top: 48px;
      background: #232323;
      border-radius: 12px;
      box-shadow: 0 2px 8px #1114; 
      min-width: 170px;
      z-index: 30;
      text-align: left;
    }
    .profile-info {
      padding: 12px 16px 4px 16px;
      color: #fdb04c;
      font-weight: bold;
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 1.02em;
    }
    .dropdown-btn {
      background: #ffb44c;
      color: #232323;
      border: none;
      border-radius: 10px;
      padding: 12px 0;
      width: 90%;
      font-weight: bold;
      margin: 8px 5%;
      cursor: pointer;
      text-align: center;
      font-size: 1em;
    }
    .dropdown-btn:hover {
      background: #fdb04c;
      color: #fff;
    }
    @media (max-width: 500px) {
      .profile-container { right: 4vw; }
      .profile-dropdown { min-width: 130px; }
    }
  </style>
  <!-- Material Icons -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons" />
</head>
<body>
  <!-- Back button on left (desktop only) -->
  <div class="back-container">
    <button class="menu-btn" id="backBtn" style="width: 110px; padding: 14px 0; font-size: 1em;">
      <span class="material-icons icon" style="margin-right:4px;">arrow_back</span>Back
    </button>
  </div>

  <!-- Top-right buttons row -->
  <div class="profile-container">
    <!-- Back button (mobile only) -->
    <button class="menu-btn" id="backBtnMobile" style="width: 110px; padding: 14px 0; font-size: 1em; margin-right:12px;">
      <span class="material-icons icon" style="margin-right:4px;">arrow_back</span>Back
    </button>
    <!-- Confirm button, far left-->
    <!--<button class="menu-btn" id="confirmBtn" style="width: 110px; padding: 14px 0; font-size: 1em; margin-right:12px;">-->
    <!--  <span class="material-icons icon" style="margin-right:4px;">check_circle</span>Confirm-->
    <!--</button>-->
    <!-- View Items button -->
    <button class="menu-btn" id="viewItemsBtn" style="width: 110px; padding: 14px 0; font-size: 1em; margin-right:12px;">
      <span class="material-icons icon" style="margin-right:4px;">list_alt</span>Search
    </button>
    <!-- Profile button -->
    <button class="menu-btn" id="profileBtn" style="width: 110px; padding: 14px 0; font-size: 1em;">
      <span class="material-icons icon" style="margin-right:4px;">person</span>Profile
    </button>
    <div id="profileMenu" class="profile-dropdown">
      <div class="profile-info">
        <span class="material-icons icon" style="font-size:1.01em;">person</span>
        <span id="profileUsername"></span>
      </div>
      <hr style="border: none; border-top: 1px solid #444; margin: 6px 0;">
      <button class="dropdown-btn" onclick="logout()">Logout</button>
    </div>
  </div>
  <!-- Dashboard Title & Main Menu -->
  <h1>Welcome To AI Scan</h1>
  <div class="menu-area">
    <button class="menu-btn" onclick="window.location.href='prepare_items.php'">
      <span class="material-icons icon">edit_note</span>
      វេចខ្ចប់ឥវ៉ាន់
    </button>
    <button class="menu-btn" onclick="window.location.href='out_items.php'">
      <span class="material-icons icon">local_shipping</span>
      ដាក់ឥរ៉ាន់ចេញ
    </button>
    <button class="menu-btn" onclick="window.location.href='prepare_set.php'">
      <span class="material-icons icon">category</span>
      រៀបចំឥវ៉ាន់Set
    </button>
    <button class="menu-btn return-btn" onclick="window.location.href='return_items.php'">
      <span class="material-icons icon">undo</span>
      Returnឥវ៉ាន់
    </button>
    <button class="menu-btn" onclick="window.location.href='sourcedata.php'">
      <span class="material-icons icon">list_alt</span>
      របាយការណ៍ចេញឥវ៉ាន់
    </button>
      <!-- Report button -->
    <button class="menu-btn" onclick="window.location.href='report_menu.php'">
      <span class="material-icons icon">bar_chart</span>
      Report
    </button>
  </div>
  
  <!-- Quick Return Controls -->
  <div class="quick-return">
    <h3>⚡ Quick Return Actions</h3>
    <button class="btn" onclick="quickReturnScan()">
      <span class="material-icons" style="font-size:1.2em; vertical-align:middle; margin-right:5px;">qr_code_scanner</span>
      Scan & Return
    </button>
    <button class="btn" onclick="viewReturnHistory()">
      <span class="material-icons" style="font-size:1.2em; vertical-align:middle; margin-right:5px;">history</span>
      Return History
    </button>
    <button class="btn" onclick="window.location.href='return_items.php'">
      <span class="material-icons" style="font-size:1.2em; vertical-align:middle; margin-right:5px;">undo</span>
      Manual Return
    </button>
  </div>
  <script>
    // Back button behavior - redirect based on role, or back to report system
    function handleBackClick() {
      // If opened from the report system, return there (URL param or stored session).
      var returnUrl = new URLSearchParams(window.location.search).get('return') || null;
      try { if (sessionStorage.getItem('ai_scan_return')) { returnUrl = returnUrl || sessionStorage.getItem('ai_scan_return'); } } catch (e) {}
      if (returnUrl) {
        try {
          var target = new URL(returnUrl, window.location.origin);
          if (target.origin === window.location.origin) {
            window.location.href = target.href;
            return;
          }
        } catch (e) { /* fall through to default */ }
      }
      const userRole = <?php echo json_encode($user['role'] ?? ''); ?>;
      if (userRole === 'admin') {
        window.location.href = "../admin/statistics.php";
      } else if (userRole === 'cashier') {
        window.location.href = "../cashier/dashboard.php";
      } else if (userRole === 'seller') {
        window.location.href = "../seller/statistics.php";
      } else {
        window.location.href = "../index.php";
      }
    }
    // Persist the return target so it survives navigation across scanner pages.
    (function storeReturn() {
      var r = new URLSearchParams(window.location.search).get('return');
      if (r) {
        try { sessionStorage.setItem('ai_scan_return', r); } catch (e) {}
      }
    })();
    
    // Attach to both back buttons
    document.getElementById("backBtn").onclick = handleBackClick;
    document.getElementById("backBtnMobile").onclick = handleBackClick;

    // Confirm button behavior (change page if needed)
    // document.getElementById("confirmBtn").onclick = function() {
    //   window.location.href = "view_confirm_items.php";
    // };

    // -- Top View Items button redirect
    document.getElementById("viewItemsBtn").onclick = function() {
      window.location.href = "view_all_items.php";
    };

    // -- Profile dropdown behavior
    const profileBtn = document.getElementById("profileBtn");
    const profileMenu = document.getElementById("profileMenu");
    profileBtn.onclick = function(e) {
      e.stopPropagation();
      profileMenu.style.display = profileMenu.style.display === "block" ? "none" : "block";
    };
    document.body.onclick = function(e) {
      if (e.target !== profileBtn && !profileMenu.contains(e.target)) {
        profileMenu.style.display = "none";
      }
    };
    // Fill in logged-in user's name from server-side session
    document.addEventListener("DOMContentLoaded", function() {
      const username = <?php echo json_encode($user['username'] ?? 'Unknown'); ?>;
      document.getElementById("profileUsername").textContent = username;
    });
    function logout() {
      window.location.href = "../logout.php";
    }

    // Quick Return Functions
    function quickReturnScan() {
      // Direct to return items with QR scanner enabled
      window.location.href = "return_items.php?quickscan=1";
    }

    function viewReturnHistory() {
      // Show return history - could be a separate page or modal
      alert("Return History - Feature coming soon!");
      // window.location.href = "return_history.html";
    }
  </script>
</body>
</html>

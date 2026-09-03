<?php
require_once 'config.php';
header('Content-Type: application/javascript');
?>
// Prefer the current browser origin to avoid protocol/host mismatches in local/dev
var API_DOMAIN = (typeof window !== 'undefined' && window.location)
  ? (window.location.origin + '<?php echo $BASE_URL; ?>/scanner')
  : '<?php echo DOMAIN; ?>';

// All your API endpoints
var API_SAVE_OUT_ITEMS = API_DOMAIN + '/save_out_items.php';
var API_SAVE_PREPARE_ITEMS = API_DOMAIN + '/save_prepare_items.php';
var API_SAVE_PREPARE_SET = API_DOMAIN + '/save_prepare_set.php';
var API_SAVE_RETURN_ITEMS = API_DOMAIN + '/save_return_items.php';
var API_LOOKUP_SET_BY_QR = API_DOMAIN + '/lookup_set_by_qr.php';


var API_GET_OUT_ITEMS = API_DOMAIN + '/get_out_items.php';
var API_GET_PREPARE_ITEMS = API_DOMAIN + '/get_prepare_items.php';
var API_GET_PREPARE_SET = API_DOMAIN + '/get_prepare_set.php';
var API_GET_RETURN_ITEMS = API_DOMAIN + '/get_return_items.php';
var API_GET_ALL_ITEMS = API_DOMAIN + '/get_all_items.php';
var API_GET_CONFIRM_DATA = API_DOMAIN + '/get_confirm_data.php';
var API_GET_SOURCE_DATA = API_DOMAIN + '/getsourcedata.php';
var API_GET_ORDER = API_DOMAIN + '/get_order.php';

var API_EDIT_OUT_ITEMS = API_DOMAIN + '/edit_out_items.php';
var API_EDIT_PREPARE_ITEMS = API_DOMAIN + '/edit_prepare_items.php';
var API_EDIT_PREPARE_SET = API_DOMAIN + '/edit_prepare_set.php';
var API_EDIT_RETURN_ITEMS = API_DOMAIN + '/edit_return_items.php';

var API_DELETE_OUT_ITEMS = API_DOMAIN + '/delete_out_items.php';
var API_DELETE_PREPARE_ITEMS = API_DOMAIN + '/delete_prepare_items.php';
var API_DELETE_PREPARE_SET = API_DOMAIN + '/delete_prepare_set.php';
var API_DELETE_RETURN_ITEMS = API_DOMAIN + '/delete_return_items.php';
var API_DELETE_CONFIRM = API_DOMAIN + '/delete_confirm.php';

var API_SAVE_CONFIRM = API_DOMAIN + '/save_confirm.php';
var API_SEND_TELEGRAM = API_DOMAIN + '/send_to_telegram.php';

// Upload URL for images (align with API domain)
var UPLOAD_URL = API_DOMAIN + '/uploads/';

// Access denied modal - intercept fetch 403 and show popup
(function(){
  if (window.__scannerApiFetchInterceptorInstalled) return;
  window.__scannerApiFetchInterceptorInstalled = true;
  const origFetch = window.fetch;
  window.fetch = function(...args) {
    return origFetch.apply(this, args).then(res => {
      if (res.status === 403) {
        showAccessDeniedModal();
        return Promise.reject(new Error('Access denied'));
      }
      return res;
    });
  };
  window.showAccessDeniedModal = function() {
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
      let el = document.getElementById('accessDeniedModal');
      if (!el) {
        el = document.createElement('div');
        el.id = 'accessDeniedModal';
        el.innerHTML = '<div class="modal fade" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-danger"><div class="modal-header border-danger bg-danger text-white"><h5 class="modal-title">&#9888; Access Denied</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center py-4"><span style="font-size:3rem;">&#128274;</span><p class="mb-0 mt-3">You do not have permission to access this resource.</p></div></div></div></div>';
        document.body.appendChild(el);
      }
      new bootstrap.Modal(el.querySelector('.modal')).show();
    } else {
      alert('Access denied. You do not have permission to access this resource.');
    }
  };
})();

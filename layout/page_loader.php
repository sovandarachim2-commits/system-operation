<?php
/**
 * Full-page loading overlay - shows before page content, hides when load complete.
 */
?>
<div id="pageLoaderOverlay" class="page-loader-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;background:rgba(0,0,0,0.75);display:flex;align-items:center;justify-content:center;">
    <div class="page-loader-box">
        <div class="page-loader-header">
            <span class="page-loader-text">Loading....</span>
            <span id="pageLoaderPercent" class="page-loader-percent">0%</span>
        </div>
        <div class="page-loader-bar">
            <div id="pageLoaderFill" class="page-loader-fill"></div>
        </div>
    </div>
</div>
<style>
.page-loader-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.75);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    transition: opacity 0.3s ease, visibility 0.3s ease;
}
.page-loader-overlay .page-loader-box {
    position: relative;
    margin: auto;
}
.page-loader-overlay.loaded {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}
.page-loader-box {
    flex-shrink: 0;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
    padding: 1.5rem 2rem;
    min-width: 320px;
}
.page-loader-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
    font-weight: bold;
    font-size: 1rem;
    color: #fff;
}
.page-loader-bar {
    height: 24px;
    background: #fff;
    border: 2px solid #000;
    border-radius: 999px;
    overflow: hidden;
}
.page-loader-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(to right, #000 0%, #000 85%, #444 90%, #888 95%, #ccc 100%);
    border-radius: 999px 0 0 999px;
    transition: width 0.2s ease-out;
}
</style>
<script>
(function() {
    var overlay = document.getElementById('pageLoaderOverlay');
    var fill = document.getElementById('pageLoaderFill');
    var percentEl = document.getElementById('pageLoaderPercent');
    var p = 0;
    var interval;

    function setProgress(val) {
        p = Math.min(100, val);
        if (fill) fill.style.width = p + '%';
        if (percentEl) percentEl.textContent = Math.round(p) + '%';
    }

    function hideLoader() {
        if (!overlay) return;
        setProgress(100);
        var content = document.getElementById('pageContent');
        if (content) content.style.visibility = 'visible';
        document.body.style.overflow = '';
        setTimeout(function() {
            overlay.classList.add('loaded');
            if (interval) clearInterval(interval);
            setTimeout(function() { overlay.remove(); }, 350);
        }, 150);
    }

    // Simulated progress: ramp up, slow near end
    interval = setInterval(function() {
        if (p >= 90) {
            clearInterval(interval);
            return;
        }
        var step = p < 50 ? 8 : (p < 80 ? 3 : 1);
        setProgress(p + step);
    }, 150);

    if (document.readyState === 'complete') {
        hideLoader();
    } else {
        window.addEventListener('load', hideLoader);
    }
})();
</script>

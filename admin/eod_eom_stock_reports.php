<?php
/**
 * EOD (End of Day) and EOM (End of Month) Stock Reports
 * Captures and manages stock snapshots for reporting purposes
 */

require_once __DIR__ . '/../auth.php';
require_role_or_permission(['admin'], 'eod_eom_reports.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../upload_paths.php';

$pdo = get_db_connection();
$errors = [];
$success = '';

$formToken = $_SESSION['form_token'] ?? '';
if ($formToken === '') {
    $formToken = bin2hex(random_bytes(32));
    $_SESSION['form_token'] = $formToken;
}

$submittedReportDate = $_POST['report_date'] ?? date('Y-m-d');
$submittedReportMonth = $_POST['report_month'] ?? date('Y-m');

function eodEomReturnedOrdersSql(PDO $pdo): string
{
    try {
        $pdo->query('SELECT 1 FROM return_items LIMIT 1');
    } catch (Throwable $e) {
        return "(
            SELECT
                o.order_code as inv,
                o.updated_at as return_date
            FROM orders o
            WHERE o.is_returned = 1
        )";
    }

    return "(
        SELECT
            ri.inv,
            ri.date_time as return_date
        FROM return_items ri

        UNION ALL

        SELECT
            o.order_code as inv,
            o.updated_at as return_date
        FROM orders o
        WHERE o.is_returned = 1
          AND NOT EXISTS (
              SELECT 1
              FROM return_items ri2
              WHERE ri2.inv = o.order_code
          )
    )";
}

// Function to compress images
function compressImage($source_path, $destination_path, $quality = 50, $max_width = 1920, $max_height = 1080) {
    // Check if GD extension is available
    if (!extension_loaded('gd') || !function_exists('imagecreatefromjpeg')) {
        // GD not available, just copy the file as-is
        if (copy($source_path, $destination_path)) {
            return true;
        }
        return false;
    }

    $image_info = getimagesize($source_path);
    if (!$image_info) {
        return false;
    }

    $width = $image_info[0];
    $height = $image_info[1];
    $mime = $image_info['mime'];

    // Create image resource based on type
    $image = null;
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source_path);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source_path);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source_path);
            break;
        default:
            return false;
    }

    if (!$image) {
        return false;
    }

    // Resize if image is too large
    $needs_resize = ($width > $max_width) || ($height > $max_height);

    if ($needs_resize) {
        // Calculate new dimensions maintaining aspect ratio
        $aspect_ratio = $width / $height;

        if ($width > $height) {
            $new_width = $max_width;
            $new_height = $max_width / $aspect_ratio;
        } else {
            $new_height = $max_height;
            $new_width = $max_height * $aspect_ratio;
        }

        // Ensure new dimensions don't exceed max
        if ($new_width > $max_width) {
            $new_width = $max_width;
            $new_height = $max_width / $aspect_ratio;
        }
        if ($new_height > $max_height) {
            $new_height = $max_height;
            $new_width = $max_height * $aspect_ratio;
        }

        // Cast dimensions to integers
        $new_width = intval($new_width);
        $new_height = intval($new_height);

        $resized_image = imagecreatetruecolor($new_width, $new_height);

        // Preserve transparency for PNG
        if ($mime === 'image/png') {
            imagealphablending($resized_image, false);
            imagesavealpha($resized_image, true);
            $transparent = imagecolorallocatealpha($resized_image, 255, 255, 255, 127);
            imagefill($resized_image, 0, 0, $transparent);
        } else {
            // White background for JPEG/WebP
            $white = imagecolorallocate($resized_image, 255, 255, 255);
            imagefill($resized_image, 0, 0, $white);
        }

        imagecopyresampled($resized_image, $image, 0, 0, 0, 0, intval($new_width), intval($new_height), intval($width), intval($height));
        imagedestroy($image);
        $image = $resized_image;
    }

    // Save the compressed image
    $success = false;
    switch ($mime) {
        case 'image/jpeg':
        case 'image/webp':
            $success = imagejpeg($image, $destination_path, $quality);
            break;
        case 'image/png':
            // Convert PNG to JPEG for better compression
            $bg = imagecreatetruecolor(imagesx($image), imagesy($image));
            $white = imagecolorallocate($bg, 255, 255, 255);
            imagefill($bg, 0, 0, $white);
            imagecopy($bg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $success = imagejpeg($bg, $destination_path, $quality);
            imagedestroy($bg);
            break;
    }

    imagedestroy($image);
    return $success;
}

// Function to create image thumbnails
function createThumbnail($source_path, $thumbnail_path, $max_width = 150, $max_height = 150, $quality = 80) {
    // Check if GD extension is available
    if (!extension_loaded('gd') || !function_exists('imagecreatefromjpeg')) {
        return false;
    }

    $image_info = getimagesize($source_path);
    if (!$image_info) {
        return false;
    }

    $width = $image_info[0];
    $height = $image_info[1];
    $mime = $image_info['mime'];

    // Create image resource
    $image = null;
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source_path);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source_path);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source_path);
            break;
        default:
            return false;
    }

    if (!$image) {
        return false;
    }

    // Calculate thumbnail dimensions
    $aspect_ratio = $width / $height;

    if ($width > $height) {
        $new_width = min($width, $max_width);
        $new_height = $new_width / $aspect_ratio;
        if ($new_height > $max_height) {
            $new_height = $max_height;
            $new_width = $new_height * $aspect_ratio;
        }
    } else {
        $new_height = min($height, $max_height);
        $new_width = $new_height * $aspect_ratio;
        if ($new_width > $max_width) {
            $new_width = $max_width;
            $new_height = $new_width / $aspect_ratio;
        }
    }

    // Create thumbnail
    $thumbnail = imagecreatetruecolor($new_width, $new_height);

    // Handle transparency for PNG
    if ($mime === 'image/png') {
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
        imagefill($thumbnail, 0, 0, $transparent);
    } else {
        $white = imagecolorallocate($thumbnail, 255, 255, 255);
        imagefill($thumbnail, 0, 0, $white);
    }

    imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    // Save thumbnail
    $success = imagejpeg($thumbnail, $thumbnail_path, $quality);

    imagedestroy($image);
    imagedestroy($thumbnail);

    return $success;
}

// Handle thumbnail generation
if (isset($_GET['action']) && $_GET['action'] === 'get_thumbnail') {
    $attachment_id = (int)$_GET['attachment_id'];
    $report_type = $_GET['report_type'];

    try {
        $stmt = $pdo->prepare("
            SELECT file_path, original_filename, mime_type
            FROM eod_eom_report_attachments
            WHERE id = ? AND report_type = ?
        ");
        $stmt->execute([$attachment_id, $report_type]);
        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($attachment && !empty($attachment['file_path'])) {
            $original_path = __DIR__ . '/../uploads/eod_eom_reports/' . $attachment['file_path'];
            if (upload_storage_is_r2() && !is_file($original_path)) {
                header('Location: ' . uploaded_file_url($attachment['file_path'], 'eod_eom_reports'));
                exit;
            }
            $thumbnail_dir = __DIR__ . '/../uploads/eod_eom_reports/thumbnails/';
            $thumbnail_filename = 'thumb_' . $attachment['id'] . '.jpg';
            $thumbnail_path = $thumbnail_dir . $thumbnail_filename;

            // Create thumbnail directory if it doesn't exist
            if (!is_dir($thumbnail_dir)) {
                mkdir($thumbnail_dir, 0755, true);
            }

            // Generate thumbnail if it doesn't exist
            if (!file_exists($thumbnail_path) && file_exists($original_path)) {
                createThumbnail($original_path, $thumbnail_path, 150, 150, 80);
            }

            // Serve thumbnail
            if (file_exists($thumbnail_path)) {
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . filesize($thumbnail_path));
                readfile($thumbnail_path);
                exit;
            }
        }
    } catch (Exception $e) {
        // Return a placeholder or error image
        header('Content-Type: image/jpeg');
        // Create a simple placeholder image
        $placeholder = imagecreatetruecolor(150, 150);
        $gray = imagecolorallocate($placeholder, 200, 200, 200);
        $text_color = imagecolorallocate($placeholder, 100, 100, 100);
        imagefill($placeholder, 0, 0, $gray);
        imagestring($placeholder, 5, 50, 70, 'No Preview', $text_color);
        imagejpeg($placeholder);
        imagedestroy($placeholder);
        exit;
    }
}

// Handle finalized report details request (for View Finalize button)
if (isset($_GET['action']) && $_GET['action'] === 'get_finalized_details') {
    header('Content-Type: application/json');
    
    $report_id = (int)$_GET['report_id'];
    $report_type = $_GET['report_type'];
    
    if (!in_array($report_type, ['eod', 'eom'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid report type']);
        exit;
    }
    
    try {
        // Get report basic info
        if ($report_type === 'eod') {
            $stmt = $pdo->prepare("
                SELECT esr.*, u.name as finalized_by_name
                FROM eod_stock_reports esr
                LEFT JOIN users u ON esr.finalized_by = u.id
                WHERE esr.id = ? AND esr.status = 'finalized'
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT esr.*, u.name as finalized_by_name
                FROM eom_stock_reports esr
                LEFT JOIN users u ON esr.finalized_by = u.id
                WHERE esr.id = ? AND esr.status = 'finalized'
            ");
        }
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$report) {
            echo json_encode(['success' => false, 'error' => 'Finalized report not found']);
            exit;
        }
        
        // Get verified products (we'll store this during finalization in the future)
        // For now, get the current report details as a proxy for verified products
        if ($report_type === 'eod') {
            $stmt = $pdo->prepare("
                SELECT esrd.*, sl.location_name,
                       CASE WHEN ps.id IS NOT NULL THEN 'set' ELSE COALESCE(p.product_type, 'normal') END AS product_type,
                       COALESCE(b.name, '') AS brand_name
                FROM eod_stock_report_details esrd
                LEFT JOIN storage_locations sl ON esrd.storage_location_id = sl.id
                LEFT JOIN products p ON p.name = esrd.item_name
                LEFT JOIN product_sets ps ON p.name = ps.set_name
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE esrd.eod_report_id = ?
                ORDER BY esrd.item_name
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT esrd.*, sl.location_name,
                       CASE WHEN ps.id IS NOT NULL THEN 'set' ELSE COALESCE(p.product_type, 'normal') END AS product_type,
                       COALESCE(b.name, '') AS brand_name
                FROM eom_stock_report_details esrd
                LEFT JOIN storage_locations sl ON esrd.storage_location_id = sl.id
                LEFT JOIN products p ON p.name = esrd.item_name
                LEFT JOIN product_sets ps ON p.name = ps.set_name
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE esrd.eom_report_id = ?
                ORDER BY esrd.item_name
            ");
        }
        $stmt->execute([$report_id]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($report_type === 'eod') {
            $products = eodEomAttachTotalSoldForEodDetails($pdo, $products, (string)($report['report_date'] ?? ''));
        }
        
        // For now, simulate verified amounts as the closing quantities
        // In the future, we could store the verified amounts separately
        foreach ($products as &$product) {
            if ($report_type === 'eod') {
                $product['verified_amount'] = $product['quantity_on_hand'];
            } else {
                $product['verified_amount'] = $product['closing_quantity'];
            }
        }
        
        // Get attachments
        $stmt = $pdo->prepare("
            SELECT * FROM eod_eom_report_attachments
            WHERE report_id = ? AND report_type = ?
            ORDER BY uploaded_at ASC
        ");
        $stmt->execute([$report_id, $report_type]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'report' => [
                'report_type' => $report_type,
                'notes' => $report['notes'],
                'finalized_at' => $report['finalized_at'],
                'finalized_by_name' => $report['finalized_by_name']
            ],
            'products' => $products,
            'attachments' => $attachments
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle manual export download for EOD/EOM report details.
if (isset($_GET['action']) && $_GET['action'] === 'export_report') {
    $report_id = (int)($_GET['report_id'] ?? 0);
    $report_type = $_GET['report_type'] ?? 'eod';

    if ($report_id <= 0 || !in_array($report_type, ['eod', 'eom'], true)) {
        http_response_code(400);
        echo 'Invalid report request';
        exit;
    }

    try {
        $exportResult = eodEomBuildStockReportExcel($pdo, $report_id, $report_type);
    } catch (Throwable $e) {
        error_log('EOD/EOM export_report: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $exportResult = ['success' => false, 'error' => $e->getMessage()];
    }

    if (!$exportResult['success'] || empty($exportResult['file_path']) || !is_file($exportResult['file_path'])) {
        http_response_code(500);
        $hint = '';
        if (!empty($exportResult['error'])) {
            error_log('EOD/EOM export_report failed: ' . $exportResult['error']);
            $hint = ': ' . htmlspecialchars((string)$exportResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } elseif (!empty($exportResult['file_path'])) {
            error_log('EOD/EOM export_report: missing file after success flag: ' . $exportResult['file_path']);
            $hint = ': Expected file missing: ' . htmlspecialchars((string)$exportResult['file_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        echo 'Export file not available' . $hint;
        exit;
    }

    $filePath = $exportResult['file_path'];
    $downloadName = $exportResult['file_name'] ?? basename($filePath);

    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($filePath);
    @unlink($filePath);
    exit;
}

// Handle thumbnail generation
if (isset($_GET['action']) && $_GET['action'] === 'download_attachment') {
    $attachment_id = (int)$_GET['attachment_id'];
    $report_type = $_GET['report_type'];

    try {
        $stmt = $pdo->prepare("
            SELECT file_path, original_filename, mime_type
            FROM eod_eom_report_attachments
            WHERE id = ? AND report_type = ?
        ");
        $stmt->execute([$attachment_id, $report_type]);
        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($attachment && !empty($attachment['file_path'])) {
            $file_path = __DIR__ . '/../uploads/eod_eom_reports/' . $attachment['file_path'];

            if (file_exists($file_path)) {
                $mimeType = (string)($attachment['mime_type'] ?? '');
                if ($mimeType === '') {
                    $mimeType = mime_content_type($file_path) ?: 'application/octet-stream';
                }
                $isImage = strpos($mimeType, 'image/') === 0;
                header('Content-Type: ' . ($isImage ? $mimeType : 'application/octet-stream'));
                header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . '; filename="' . $attachment['original_filename'] . '"');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit;
            } elseif (upload_storage_is_r2()) {
                header('Location: ' . uploaded_file_url($attachment['file_path'], 'eod_eom_reports'));
                exit;
            } else {
                $errors[] = "Attachment file not found";
            }
        } else {
            $errors[] = "Attachment not found";
        }
    } catch (Exception $e) {
        $errors[] = "Error downloading attachment: " . $e->getMessage();
    }
}

// Get filters
$report_type = $_GET['report_type'] ?? 'eod'; // 'eod' or 'eom'
$selected_date = $_GET['date'] ?? date('Y-m-d');
$location_filter = (int)($_GET['location_filter'] ?? 0);

// Create required tables if they don't exist
try {
    // EOD Stock Reports table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eod_stock_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT,
            total_items INT DEFAULT 0,
            total_quantity DECIMAL(15,2) DEFAULT 0,
            total_value DECIMAL(15,2) DEFAULT 0,
            status ENUM('draft', 'finalized') DEFAULT 'draft',
            notes TEXT,
            finalized_at TIMESTAMP NULL,
            finalized_by INT,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY unique_eod_date (report_date)
        )
    ");

    foreach ([
        'notes' => 'TEXT NULL',
        'finalized_at' => 'TIMESTAMP NULL',
        'finalized_by' => 'INT NULL',
    ] as $column => $definition) {
        try {
            $pdo->exec("ALTER TABLE eod_stock_reports ADD COLUMN {$column} {$definition}");
        } catch (Exception $e) {
            // Column already exists, ignore error
        }
    }

    // EOD Stock Report Details table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eod_stock_report_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            eod_report_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            sku VARCHAR(100),
            storage_location_id INT,
            quantity_on_hand DECIMAL(15,2) DEFAULT 0,
            available_quantity DECIMAL(15,2) DEFAULT 0,
            opening_quantity DECIMAL(15,2) DEFAULT 0,
            daily_received DECIMAL(15,2) DEFAULT 0,
            return_quantity DECIMAL(15,2) DEFAULT 0,
            movements_in DECIMAL(15,2) DEFAULT 0,
            movements_out DECIMAL(15,2) DEFAULT 0,
            transfer_in DECIMAL(15,2) DEFAULT 0,
            transfer_out DECIMAL(15,2) DEFAULT 0,
            adjustments DECIMAL(15,2) DEFAULT 0,
            unit_cost DECIMAL(10,2) DEFAULT 0,
            total_value DECIMAL(15,2) DEFAULT 0,
            last_movement_date TIMESTAMP NULL,
            notes TEXT,
            FOREIGN KEY (eod_report_id) REFERENCES eod_stock_reports(id) ON DELETE CASCADE,
            FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL
        )
    ");

    // Add missing columns to existing table (for backwards compatibility)
    try {
        $pdo->exec("ALTER TABLE eod_stock_report_details ADD COLUMN opening_quantity DECIMAL(15,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists, ignore error
    }
    
    try {
        $pdo->exec("ALTER TABLE eod_stock_report_details DROP COLUMN daily_sold");
    } catch (Exception $e) {
        // Column already dropped or doesn't exist, ignore error
    }
    
    try {
        $pdo->exec("ALTER TABLE eod_stock_report_details ADD COLUMN daily_received DECIMAL(15,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists, ignore error
    }
    
    try {
        $pdo->exec("ALTER TABLE eod_stock_report_details ADD COLUMN return_quantity DECIMAL(15,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists, ignore error
    }

    try {
        $pdo->exec("ALTER TABLE eod_stock_report_details ADD COLUMN movements_in DECIMAL(15,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists, ignore error
    }

    try {
        $pdo->exec("ALTER TABLE eod_stock_report_details ADD COLUMN movements_out DECIMAL(15,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists, ignore error
    }

    try {
        $pdo->exec("ALTER TABLE eod_stock_report_details ADD COLUMN transfer_in DECIMAL(15,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists, ignore error
    }

    try {
        $pdo->exec("ALTER TABLE eod_stock_report_details ADD COLUMN transfer_out DECIMAL(15,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists, ignore error
    }

    try {
        $pdo->exec("ALTER TABLE eod_stock_report_details ADD COLUMN adjustments DECIMAL(15,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists, ignore error
    }

    // EOM Stock Reports table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eom_stock_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_month VARCHAR(7) NOT NULL COMMENT 'YYYY-MM format',
            report_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT,
            total_items INT DEFAULT 0,
            total_quantity DECIMAL(15,2) DEFAULT 0,
            total_value DECIMAL(15,2) DEFAULT 0,
            status ENUM('draft', 'finalized') DEFAULT 'draft',
            notes TEXT,
            finalized_at TIMESTAMP NULL,
            finalized_by INT,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY unique_eom_month (report_month)
        )
    ");

    foreach ([
        'notes' => 'TEXT NULL',
        'finalized_at' => 'TIMESTAMP NULL',
        'finalized_by' => 'INT NULL',
    ] as $column => $definition) {
        try {
            $pdo->exec("ALTER TABLE eom_stock_reports ADD COLUMN {$column} {$definition}");
        } catch (Exception $e) {
            // Column already exists, ignore error
        }
    }

    // EOM Stock Report Details table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eom_stock_report_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            eom_report_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            sku VARCHAR(100),
            storage_location_id INT,
            opening_quantity DECIMAL(15,2) DEFAULT 0,
            closing_quantity DECIMAL(15,2) DEFAULT 0,
            average_quantity DECIMAL(15,2) DEFAULT 0,
            unit_cost DECIMAL(10,2) DEFAULT 0,
            opening_value DECIMAL(15,2) DEFAULT 0,
            closing_value DECIMAL(15,2) DEFAULT 0,
            average_value DECIMAL(15,2) DEFAULT 0,
            movements_in DECIMAL(15,2) DEFAULT 0,
            return_quantity DECIMAL(15,2) DEFAULT 0,
            movements_out DECIMAL(15,2) DEFAULT 0,
            adjustments DECIMAL(15,2) DEFAULT 0,
            notes TEXT,
            FOREIGN KEY (eom_report_id) REFERENCES eom_stock_reports(id) ON DELETE CASCADE,
            FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL
        )
    ");

    try {
        $pdo->exec("ALTER TABLE eom_stock_report_details ADD COLUMN return_quantity DECIMAL(15,2) DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists, ignore error
    }

    // Attachments table for multiple file support
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eod_eom_report_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            report_type ENUM('eod', 'eom') NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_size INT NOT NULL,
            mime_type VARCHAR(100),
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            uploaded_by INT,
            FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_report (report_id, report_type)
        )
    ");

    error_log("EOD/EOM stock report tables created successfully");

} catch (PDOException $e) {
    error_log("Error creating EOD/EOM tables: " . $e->getMessage());
    $errors[] = "Database setup error: " . $e->getMessage();
}

// EOD/EOM report: Telegram message HTML + shared Excel (.xlsx) helpers
function escapeTelegramHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Strip characters that break SpreadsheetML / XML 1.0 (e.g. pasted notes). */
function eodEomExcelSafeCellText(string $value): string {
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

    return is_string($clean) ? $clean : $value;
}

/** Same escaping as sold_products.php soldProductsXml() for cell text. */
function eodEomExcelCellTextForXml(string $value): string {
    return htmlspecialchars(eodEomExcelSafeCellText($value), ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

/** Portable ZIP writer when ZipArchive is missing (aligned with sold_products.php). */
function eodEomExcelDosDateTime(): array {
    $dt = getdate();
    $year = max(1980, (int)$dt['year']);
    $dosTime = (($dt['hours'] & 0x1F) << 11) | (($dt['minutes'] & 0x3F) << 5) | ((int)floor($dt['seconds'] / 2) & 0x1F);
    $dosDate = ((($year - 1980) & 0x7F) << 9) | (($dt['mon'] & 0x0F) << 5) | ($dt['mday'] & 0x1F);

    return [$dosDate, $dosTime];
}

function eodEomExcelUInt32(int $value): int {
    return (int)sprintf('%u', $value);
}

function eodEomExcelCreateZipWithoutExtension(string $filePath, array $entries): void {
    if (!function_exists('gzdeflate')) {
        throw new RuntimeException('Cannot create XLSX: zlib extension is missing');
    }

    [$dosDate, $dosTime] = eodEomExcelDosDateTime();
    $zipData = '';
    $central = '';
    $offset = 0;
    $count = 0;

    foreach ($entries as $name => $data) {
        $name = str_replace('\\', '/', (string)$name);
        $data = (string)$data;
        $rawLen = strlen($data);
        $compressed = gzdeflate($data, 9);
        if ($compressed === false) {
            throw new RuntimeException('Failed to compress XLSX data');
        }
        $compLen = strlen($compressed);
        $crc = eodEomExcelUInt32(crc32($data));

        $local = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $crc,
            $compLen,
            $rawLen,
            strlen($name),
            0
        );
        $zipData .= $local . $name . $compressed;

        $centralHeader = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            0x0314,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $crc,
            $compLen,
            $rawLen,
            strlen($name),
            0,
            0,
            0,
            0,
            32,
            $offset
        );
        $central .= $centralHeader . $name;
        $offset = strlen($zipData);
        $count++;
    }

    $eocd = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $count,
        $count,
        strlen($central),
        strlen($zipData),
        0
    );

    if (file_put_contents($filePath, $zipData . $central . $eocd) === false) {
        throw new RuntimeException('Unable to write XLSX file');
    }
}

function excelColumnName($index) {
    $name = '';
    $index = (int)$index;

    while ($index >= 0) {
        $name = chr(($index % 26) + 65) . $name;
        $index = intdiv($index, 26) - 1;
    }

    return $name;
}

/** Group finalized report detail rows the same way as the on-screen / print table. */
function eodEomGroupDetailsByLocationForExcel(array $details): array {
    $groups = [];
    foreach ($details as $detail) {
        $locationCode = trim((string)($detail['location_code'] ?? ''));
        $locationName = trim((string)($detail['location_name'] ?? ''));
        $locationLabel = $locationCode !== '' ? $locationCode : 'Unknown';
        if ($locationName !== '') {
            $locationLabel .= ' - ' . $locationName;
        }
        if (!isset($groups[$locationLabel])) {
            $groups[$locationLabel] = [
                'label' => $locationLabel,
                'items' => [],
            ];
        }
        $groups[$locationLabel]['items'][] = $detail;
    }

    return array_values($groups);
}

/**
 * Keep rows with stock or movement activity so print and Excel include products
 * that returned to zero stock during the report period.
 */
function eodEomFilterDetailsRowsForPrintLike(array $details, string $reportType): array {
    $filtered = [];
    foreach ($details as $row) {
        if ($reportType === 'eod') {
            $opening = (float)($row['opening_quantity'] ?? 0);
            $closing = (float)($row['quantity_on_hand'] ?? 0);
        } else {
            $opening = (float)($row['opening_quantity'] ?? 0);
            $closing = (float)($row['closing_quantity'] ?? 0);
        }
        $movementTotal =
            abs((float)($row['daily_received'] ?? 0))
            + abs((float)($row['purchase_return_vendor'] ?? 0))
            + abs((float)($row['total_sold'] ?? 0))
            + abs((float)($row['offline_sale'] ?? 0))
            + abs((float)($row['offline_purchase_back'] ?? 0))
            + abs((float)($row['cancelled_offline_sale'] ?? 0))
            + abs((float)($row['offline_cancelled_purchase_back'] ?? 0))
            + abs((float)($row['marketing_take_out'] ?? 0))
            + abs((float)($row['marketing_return'] ?? 0))
            + abs((float)($row['return_quantity'] ?? 0))
            + abs((float)($row['movements_in'] ?? 0))
            + abs((float)($row['movements_out'] ?? 0))
            + abs((float)($row['transfer_in'] ?? 0))
            + abs((float)($row['transfer_out'] ?? 0))
            + abs((float)($row['adjustments'] ?? 0));
        if ($opening !== 0.0 || $closing !== 0.0 || $movementTotal >= 0.005) {
            $filtered[] = $row;
        }
    }

    return $filtered;
}

function eodEomReportQty($value, string $direction = ''): string {
    $number = (float)($value ?? 0);
    if (abs($number) < 0.005) {
        return '0.00';
    }

    if ($direction === '+') {
        return '+' . number_format(abs($number), 2);
    }

    if ($direction === '-') {
        return '-' . number_format(abs($number), 2);
    }

    return number_format($number, 2);
}

function eodEomKeepReturnsOnDefaultLocationOnly(array $details): array {
    foreach ($details as $index => $row) {
        if ((int)($row['location_is_default'] ?? 0) !== 1) {
            $details[$index]['return_quantity'] = 0;
        }
    }

    return $details;
}

function eodEomExcelProductTypeLabel(?string $productType): string {
    return function_exists('product_type_display_label')
        ? product_type_display_label($productType)
        : ucfirst(str_replace('_', ' ', strtolower((string)($productType ?? 'normal'))));
}

/**
 * Add Total Sold to EOD rows using the same gross quantity basis as Sold Products:
 * printed order items, cancelled orders excluded, returned orders still included.
 * Set products are expanded into their component products for stock reporting.
 */
function eodEomAttachTotalSoldForEodDetails(PDO $pdo, array $details, string $reportDate): array {
    if ($details === [] || $reportDate === '') {
        return $details;
    }

    foreach ($details as $index => $row) {
        $details[$index]['total_sold'] = 0;
    }

    try {
        $defaultLocationId = null;
        try {
            $defaultLocationId = $pdo->query("SELECT id FROM storage_locations WHERE is_default = 1 LIMIT 1")->fetchColumn();
            $defaultLocationId = $defaultLocationId === false ? null : (int)$defaultLocationId;
        } catch (Throwable $e) {
            $defaultLocationId = null;
        }

        $stmt = $pdo->prepare("
            SELECT
                sold.item_name,
                sold.storage_location_id,
                SUM(sold.total_sold) AS total_sold
            FROM (
                SELECT
                    CASE
                        WHEN COALESCE(p.product_type, 'normal') = 'set' AND component_product.id IS NOT NULL
                        THEN component_product.name
                        ELSE p.name
                    END AS item_name,
                    ? AS storage_location_id,
                    SUM(
                        CASE
                            WHEN COALESCE(p.product_type, 'normal') = 'set' AND component_product.id IS NOT NULL
                            THEN oi.quantity * COALESCE(psi.quantity, 0)
                            ELSE oi.quantity
                        END
                    ) AS total_sold
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN products p ON oi.product_id = p.id
                JOIN print_jobs pj ON pj.order_id = o.id
                LEFT JOIN product_sets ps
                    ON ps.set_name = p.name
                   AND COALESCE(p.product_type, 'normal') = 'set'
                LEFT JOIN product_set_items psi ON psi.product_set_id = ps.id
                LEFT JOIN products component_product ON component_product.id = psi.product_id
                WHERE DATE(pj.printed_at) = ?
                  AND COALESCE(o.is_cancelled, 0) = 0
                GROUP BY item_name, storage_location_id
            ) sold
            WHERE sold.item_name IS NOT NULL
            GROUP BY sold.item_name, sold.storage_location_id
        ");
        $stmt->execute([$defaultLocationId, $reportDate]);
        $soldRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $soldLookup = [];
        foreach ($soldRows as $row) {
            $itemName = trim((string)($row['item_name'] ?? ''));
            if ($itemName === '') {
                continue;
            }
            $locationId = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $soldLookup[strtolower($itemName) . '|' . $locationId] = (float)($row['total_sold'] ?? 0);
        }

        $locationLookup = [];
        foreach ($details as $row) {
            $locationId = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            if (!isset($locationLookup[$locationId])) {
                $locationLookup[$locationId] = [
                    'location_code' => $row['location_code'] ?? null,
                    'location_name' => $row['location_name'] ?? null,
                    'location_is_default' => (int)($row['location_is_default'] ?? 0),
                ];
            }
        }
        try {
            $locRows = $pdo->query("SELECT id, location_code, location_name, COALESCE(is_default, 0) AS location_is_default FROM storage_locations")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($locRows as $locRow) {
                $locationLookup[(string)$locRow['id']] = [
                    'location_code' => $locRow['location_code'] ?? null,
                    'location_name' => $locRow['location_name'] ?? null,
                    'location_is_default' => (int)($locRow['location_is_default'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            error_log('EOD location lookup for sold-only rows failed: ' . $e->getMessage());
        }

        $matchedSoldKeys = [];
        foreach ($details as $index => $row) {
            $itemName = strtolower(trim((string)($row['item_name'] ?? '')));
            $locationId = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $soldKey = $itemName . '|' . $locationId;
            $details[$index]['total_sold'] = $soldLookup[$soldKey] ?? 0;
            if (isset($soldLookup[$soldKey])) {
                $matchedSoldKeys[$soldKey] = true;
            }
        }

        foreach ($soldRows as $row) {
            $itemName = trim((string)($row['item_name'] ?? ''));
            if ($itemName === '') {
                continue;
            }
            $locationId = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $soldKey = strtolower($itemName) . '|' . $locationId;
            if (isset($matchedSoldKeys[$soldKey])) {
                continue;
            }
            $location = $locationLookup[$locationId] ?? [];
            $details[] = [
                'item_name' => $itemName,
                'sku' => '',
                'storage_location_id' => $row['storage_location_id'],
                'location_code' => $location['location_code'] ?? null,
                'location_name' => $location['location_name'] ?? null,
                'location_is_default' => (int)($location['location_is_default'] ?? 0),
                'product_type' => 'normal',
                'opening_quantity' => 0,
                'quantity_on_hand' => 0,
                'available_quantity' => 0,
                'daily_received' => 0,
                'purchase_return_vendor' => 0,
                'total_sold' => (float)($row['total_sold'] ?? 0),
                'marketing_take_out' => 0,
                'marketing_return' => 0,
                'return_quantity' => 0,
                'movements_in' => 0,
                'movements_out' => 0,
                'transfer_in' => 0,
                'transfer_out' => 0,
                'adjustments' => 0,
                'unit_cost' => 0,
                'total_value' => 0,
            ];
        }
    } catch (Throwable $e) {
        error_log('EOD total sold lookup failed: ' . $e->getMessage());
    }

    return $details;
}

function eodEomAttachMarketingForEodDetails(PDO $pdo, array $details, string $reportDate): array {
    if ($details === [] || $reportDate === '') {
        return $details;
    }

    foreach ($details as $index => $row) {
        $details[$index]['marketing_take_out'] = 0;
        $details[$index]['marketing_return'] = 0;
    }

    try {
        $hasProductId = (bool)$pdo->query("SHOW COLUMNS FROM stock_operations LIKE 'product_id'")->fetchColumn();
        $hasProductName = (bool)$pdo->query("SHOW COLUMNS FROM stock_operations LIKE 'product_name'")->fetchColumn();
        $itemNameParts = [];
        if ($hasProductId) {
            $itemNameParts[] = "NULLIF(TRIM(p.name), '')";
        }
        if ($hasProductName) {
            $itemNameParts[] = "NULLIF(TRIM(so.product_name), '')";
        }
        $itemNameParts[] = "NULLIF(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(so.notes, ' | Note:', 1), ': ', -1)), '')";
        $itemNameExpr = 'COALESCE(' . implode(', ', $itemNameParts) . ')';
        $productJoin = $hasProductId ? 'LEFT JOIN products p ON p.id = so.product_id' : '';

        $stmt = $pdo->prepare("
            SELECT
                marketing.item_name,
                marketing.storage_location_id,
                SUM(marketing.marketing_take_out) AS marketing_take_out,
                SUM(marketing.marketing_return) AS marketing_return
            FROM (
                SELECT
                    p.name AS item_name,
                    mt.storage_location_id,
                    SUM(mti.quantity_taken) AS marketing_take_out,
                    0 AS marketing_return
                FROM marketing_takes mt
                JOIN marketing_take_items mti ON mti.marketing_take_id = mt.id
                JOIN products p ON p.id = mti.product_id
                WHERE DATE(mt.approved_at) = ?
                  AND mt.approved_at IS NOT NULL
                  AND COALESCE(mt.status, '') IN ('approved', 'pending', 'completed')
                GROUP BY p.name, mt.storage_location_id

                UNION ALL

                SELECT
                    {$itemNameExpr} AS item_name,
                    so.storage_location_id,
                    0 AS marketing_take_out,
                    SUM(ABS(so.quantity)) AS marketing_return
                FROM stock_operations so
                {$productJoin}
                WHERE DATE(so.created_at) = ?
                  AND so.reference_type = 'marketing_take'
                  AND so.operation_type = 'marketing_return'
                GROUP BY item_name, so.storage_location_id
            ) marketing
            WHERE marketing.item_name IS NOT NULL
            GROUP BY marketing.item_name, marketing.storage_location_id
        ");
        $stmt->execute([$reportDate, $reportDate]);
        $marketingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $marketingLookup = [];
        foreach ($marketingRows as $row) {
            $itemName = trim((string)($row['item_name'] ?? ''));
            if ($itemName === '') {
                continue;
            }
            $locationId = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $marketingLookup[strtolower($itemName) . '|' . $locationId] = [
                'marketing_take_out' => (float)($row['marketing_take_out'] ?? 0),
                'marketing_return' => (float)($row['marketing_return'] ?? 0),
            ];
        }

        foreach ($details as $index => $row) {
            $itemName = strtolower(trim((string)($row['item_name'] ?? '')));
            $locationId = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $marketing = $marketingLookup[$itemName . '|' . $locationId] ?? null;
            if ($marketing === null) {
                continue;
            }
            $details[$index]['marketing_take_out'] = $marketing['marketing_take_out'];
            $details[$index]['marketing_return'] = $marketing['marketing_return'];
        }
    } catch (Throwable $e) {
        error_log('EOD marketing lookup failed: ' . $e->getMessage());
    }

    return $details;
}

function eodEomAttachPurchaseReturnForEodDetails(PDO $pdo, array $details, string $reportDate): array {
    if ($details === [] || $reportDate === '') {
        return $details;
    }

    foreach ($details as $index => $row) {
        $details[$index]['purchase_return_vendor'] = 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(p.name), ''), NULLIF(TRIM(poi.item_name), '')) AS item_name,
                pri.storage_location_id,
                SUM(pri.quantity_returned) AS purchase_return_vendor
            FROM purchase_returns pr
            JOIN purchase_return_items pri ON pri.purchase_return_id = pr.id
            JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
            LEFT JOIN products p ON p.id = poi.product_id
            WHERE DATE(pr.return_date) = ?
              AND COALESCE(pr.status, 'completed') <> 'deleted'
            GROUP BY item_name, pri.storage_location_id
        ");
        $stmt->execute([$reportDate]);
        $returnRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $returnLookup = [];
        foreach ($returnRows as $row) {
            $itemName = trim((string)($row['item_name'] ?? ''));
            if ($itemName === '') {
                continue;
            }
            $locationId = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $returnLookup[strtolower($itemName) . '|' . $locationId] = (float)($row['purchase_return_vendor'] ?? 0);
        }

        foreach ($details as $index => $row) {
            $itemName = strtolower(trim((string)($row['item_name'] ?? '')));
            $locationId = $row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id'];
            $details[$index]['purchase_return_vendor'] = $returnLookup[$itemName . '|' . $locationId] ?? 0;
        }
    } catch (Throwable $e) {
        error_log('EOD purchase return lookup failed: ' . $e->getMessage());
    }

    return $details;
}

function eodEomAttachOfflineMovementsForEodDetails(PDO $pdo, array $details, string $reportDate): array {
    $fields = ['offline_sale', 'offline_purchase_back', 'cancelled_offline_sale', 'offline_cancelled_purchase_back'];
    foreach ($details as $index => $row) {
        foreach ($fields as $field) {
            $details[$index][$field] = 0;
        }
    }
    if ($details === [] || $reportDate === '') {
        return $details;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                p.name AS item_name,
                CASE
                    WHEN sm.reference_type IN ('offline_sale', 'offline_customer_purchase_cancel', 'offline_purchase_edit')
                        THEN sm.from_storage_location_id
                    ELSE sm.to_storage_location_id
                END AS storage_location_id,
                SUM(CASE WHEN sm.reference_type = 'offline_sale' THEN ABS(sm.quantity) ELSE 0 END) AS offline_sale,
                SUM(CASE WHEN sm.reference_type = 'offline_customer_purchase' THEN ABS(sm.quantity) ELSE 0 END) AS offline_purchase_back,
                SUM(CASE WHEN sm.reference_type IN ('offline_sale_cancel', 'offline_sale_edit') THEN ABS(sm.quantity) ELSE 0 END) AS cancelled_offline_sale,
                SUM(CASE WHEN sm.reference_type IN ('offline_customer_purchase_cancel', 'offline_purchase_edit') THEN ABS(sm.quantity) ELSE 0 END) AS offline_cancelled_purchase_back
            FROM stock_movements sm
            JOIN products p ON p.id = sm.item_id
            WHERE DATE(sm.created_at) = ?
              AND sm.reference_type IN (
                  'offline_sale',
                  'offline_customer_purchase',
                  'offline_sale_cancel',
                  'offline_sale_edit',
                  'offline_customer_purchase_cancel',
                  'offline_purchase_edit'
              )
            GROUP BY p.name, storage_location_id
        ");
        $stmt->execute([$reportDate]);

        $lookup = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(trim((string)$row['item_name'])) . '|' . ($row['storage_location_id'] === null ? 'null' : (string)$row['storage_location_id']);
            $lookup[$key] = $row;
        }

        foreach ($details as $index => $row) {
            $key = strtolower(trim((string)($row['item_name'] ?? ''))) . '|' . (($row['storage_location_id'] ?? null) === null ? 'null' : (string)$row['storage_location_id']);
            if (!isset($lookup[$key])) {
                continue;
            }
            foreach ($fields as $field) {
                $details[$index][$field] = (float)($lookup[$key][$field] ?? 0);
            }
        }
    } catch (Throwable $e) {
        error_log('EOD offline movement lookup failed: ' . $e->getMessage());
    }

    return $details;
}

/**
 * Same style index mapping as admin/sold_products.php soldProductsGetStyleIndex().
 */
function eodEomExcelStyleKeyToIndex(string $styleKey): int {
    $map = [
        'default' => 4,
        'title' => 1,
        'meta' => 2,
        'header' => 3,
        'section' => 5,
        'total' => 2,
    ];

    return $map[$styleKey] ?? 4;
}

/**
 * Infer per-row Excel styles to match Sold Products report (title / meta / section / header / body / totals).
 */
function eodEomBuildRowStyleKeysForSoldStyle(array $rows): array {
    $keys = [];
    foreach ($rows as $i => $row) {
        $c0 = trim((string)($row[0] ?? ''));
        $c1 = trim((string)($row[1] ?? ''));
        $c3 = trim((string)($row[3] ?? ''));

        if ($i === 0) {
            $keys[] = 'title';
            continue;
        }
        if (in_array($c0, ['Period', 'Finalized By', 'Finalized At', 'Notes'], true)) {
            $keys[] = 'meta';
            continue;
        }

        $allEmpty = true;
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                $allEmpty = false;
                break;
            }
        }
        if ($allEmpty) {
            $keys[] = 'default';
            continue;
        }
        if ($c0 === 'Summary') {
            $keys[] = 'section';
            continue;
        }
        if (in_array($c0, ['Total Items', 'Total Quantity', 'Total Value'], true)) {
            $keys[] = 'meta';
            continue;
        }
        if (stripos($c0, 'Report Details') !== false && !str_starts_with($c0, 'Location:')) {
            $keys[] = 'section';
            continue;
        }
        if ($c0 === 'No' && $c1 === 'Item Name') {
            $keys[] = 'header';
            continue;
        }
        if (str_starts_with($c0, 'Location:')) {
            $keys[] = 'section';
            continue;
        }
        if (str_contains($c3, 'Subtotal')) {
            $keys[] = 'total';
            continue;
        }
        if ($c3 === 'Total') {
            $keys[] = 'total';
            continue;
        }
        if (ctype_digit((string)$c0) && $c1 !== '') {
            $keys[] = 'default';
            continue;
        }

        $keys[] = 'default';
    }

    return $keys;
}

/**
 * Worksheet XML for EOD/EOM export — matches sold_products.php palette (6 cellXfs, inlineStr, thin borders).
 *
 * @param array<int, array<int, mixed>> $rows           Padded rows (fixed width per sheet)
 * @param array<int, string>            $rowStyleKeys  Same length as $rows: title|meta|header|section|default|total
 * @param array<int, float|int>|null    $columnWidths  Optional per-column width (Excel character units)
 */
function buildExcelSheetXml(array $rows, array $rowStyleKeys, ?array $columnWidths = null): string {
    $maxCols = 1;
    foreach ($rows as $r) {
        if (is_array($r) && count($r) > $maxCols) {
            $maxCols = count($r);
        }
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    $colsXml = '';
    if (!empty($columnWidths) && count($columnWidths) >= $maxCols) {
        foreach (array_slice(array_values($columnWidths), 0, $maxCols) as $index => $width) {
            $n = $index + 1;
            $colsXml .= '<col min="' . $n . '" max="' . $n . '" width="' . htmlspecialchars((string)$width, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '" customWidth="1"/>';
        }
        $xml .= '<cols>' . $colsXml . '</cols>';
    } else {
        $xml .= '<cols>';
        for ($c = 0; $c < $maxCols; $c++) {
            $w = ($c === 0) ? 8 : (($c === 1) ? 28 : (($c <= 3) ? 14 : 12));
            $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }

    $xml .= '<sheetData>';

    foreach ($rows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $xml .= '<row r="' . $excelRow . '">';
        $row = array_pad(is_array($row) ? array_values($row) : [], $maxCols, '');
        $styleKey = $rowStyleKeys[$rowIndex] ?? 'default';
        $styleIndex = eodEomExcelStyleKeyToIndex($styleKey);

        foreach ($row as $columnIndex => $value) {
            $cellRef = excelColumnName($columnIndex) . $excelRow;
            $xml .= '<c r="' . $cellRef . '" t="inlineStr" s="' . $styleIndex . '"><is><t>' . eodEomExcelCellTextForXml((string)$value) . '</t></is></c>';
        }

        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';

    return $xml;
}

/**
 * @param array<int, array<int, mixed>> $rows
 * @param array<int, string>            $rowStyleKeys title|meta|header|section|default|total
 * @param array<int, float|int>|null    $columnWidths
 */
function createSimpleXlsxFile(string $filePath, array $rows, array $rowStyleKeys = [], ?array $columnWidths = null): void {
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Stock Report" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    // Palette aligned with admin/sold_products.php soldProductsCreateSimpleXlsxFile() (6 cellXfs).
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5">'
        . '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="6">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Shadow</Application>'
        . '</Properties>';

    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>EOD/EOM Stock Report</dc:title>'
        . '<dc:creator>Shadow</dc:creator>'
        . '<cp:lastModifiedBy>Shadow</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $worksheet = buildExcelSheetXml($rows, $rowStyleKeys, $columnWidths);
    $entries = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $worksheet,
        'xl/styles.xml' => $styles,
        'docProps/core.xml' => $core,
        'docProps/app.xml' => $app,
    ];

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($entries as $entryName => $entryData) {
                $zip->addFromString($entryName, $entryData);
            }
            $zip->close();

            return;
        }
    }

    eodEomExcelCreateZipWithoutExtension($filePath, $entries);
}

function eodEomPruneZeroOnlyExcelColumns(array $rows, string $reportType): array {
    $headerIndex = null;
    foreach ($rows as $index => $row) {
        if (($row[0] ?? '') === 'No') {
            $headerIndex = $index;
            break;
        }
    }
    if ($headerIndex === null || !isset($rows[$headerIndex]) || !is_array($rows[$headerIndex])) {
        return $rows;
    }

    $headers = array_values($rows[$headerIndex]);
    $alwaysKeep = $reportType === 'eod'
        ? ['no', 'item name', 'type product', 'brand', 'location', 'opening stock', 'closing stock', 'remark']
        : ['no', 'item name', 'type product', 'brand', 'location', 'opening', 'closing', 'remark'];
    $keepIndexes = [];

    foreach ($headers as $colIndex => $header) {
        $label = strtolower(trim((string)$header));
        if ($label === '' || in_array($label, $alwaysKeep, true)) {
            $keepIndexes[] = $colIndex;
            continue;
        }

        $hasValue = false;
        for ($rowIndex = $headerIndex + 1; $rowIndex < count($rows); $rowIndex++) {
            $value = $rows[$rowIndex][$colIndex] ?? '';
            if ($value === '' || $value === '-') {
                continue;
            }
            if (abs((float)str_replace([',', '+'], '', (string)$value)) >= 0.005) {
                $hasValue = true;
                break;
            }
        }
        if ($hasValue) {
            $keepIndexes[] = $colIndex;
        }
    }

    return array_map(static function ($row) use ($keepIndexes) {
        $row = is_array($row) ? array_values($row) : [];
        $newRow = [];
        foreach ($keepIndexes as $colIndex) {
            $newRow[] = $row[$colIndex] ?? '';
        }
        return $newRow;
    }, $rows);
}

/**
 * EOD/EOM workbook builder.
 * Detail mode is used by the browser Excel button; specific mode is used by Telegram.
 */
function eodEomBuildStockReportExcel($pdo, $report_id, $report_type, string $columnMode = 'detail') {
    try {
        if ($report_type === 'eod') {
            $stmt = $pdo->prepare(" 
                SELECT esr.*, u.name as finalized_by_name
                FROM eod_stock_reports esr
                LEFT JOIN users u ON esr.finalized_by = u.id
                WHERE esr.id = ?
            ");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);

            $details_stmt = $pdo->prepare(" 
                SELECT esrd.*, sl.location_code, sl.location_name, COALESCE(sl.is_default, 0) as location_is_default,
                       CASE WHEN ps.id IS NOT NULL THEN 'set' ELSE COALESCE(p.product_type, 'normal') END AS product_type,
                       COALESCE(b.name, '') AS brand_name
                FROM eod_stock_report_details esrd
                LEFT JOIN storage_locations sl ON esrd.storage_location_id = sl.id
                LEFT JOIN products p ON p.name = esrd.item_name
                LEFT JOIN product_sets ps ON p.name = ps.set_name
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE esrd.eod_report_id = ?
                ORDER BY COALESCE(sl.location_code, '') ASC, location_is_default DESC, esrd.item_name
            ");
            $details_stmt->execute([$report_id]);
        } else {
            $stmt = $pdo->prepare(" 
                SELECT esr.*, u.name as finalized_by_name
                FROM eom_stock_reports esr
                LEFT JOIN users u ON esr.finalized_by = u.id
                WHERE esr.id = ?
            ");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);

            $details_stmt = $pdo->prepare(" 
                SELECT esrd.*, sl.location_code, sl.location_name, COALESCE(sl.is_default, 0) as location_is_default,
                       CASE WHEN ps.id IS NOT NULL THEN 'set' ELSE COALESCE(p.product_type, 'normal') END AS product_type,
                       COALESCE(b.name, '') AS brand_name
                FROM eom_stock_report_details esrd
                LEFT JOIN storage_locations sl ON esrd.storage_location_id = sl.id
                LEFT JOIN products p ON p.name = esrd.item_name
                LEFT JOIN product_sets ps ON p.name = ps.set_name
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE esrd.eom_report_id = ?
                ORDER BY COALESCE(sl.location_code, '') ASC, location_is_default DESC, esrd.item_name
            ");
            $details_stmt->execute([$report_id]);
        }

        if (!$report) {
            return ['success' => false, 'error' => 'Report not found'];
        }

        $details = eodEomKeepReturnsOnDefaultLocationOnly($details_stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($report_type === 'eod') {
            $details = eodEomAttachTotalSoldForEodDetails($pdo, $details, (string)($report['report_date'] ?? ''));
            $details = eodEomAttachMarketingForEodDetails($pdo, $details, (string)($report['report_date'] ?? ''));
            $details = eodEomAttachPurchaseReturnForEodDetails($pdo, $details, (string)($report['report_date'] ?? ''));
            $details = eodEomAttachOfflineMovementsForEodDetails($pdo, $details, (string)($report['report_date'] ?? ''));
        }
        $details = eodEomFilterDetailsRowsForPrintLike($details, $report_type);
        $label = strtoupper($report_type);
        $periodLabel = $report_type === 'eod' ? ($report['report_date'] ?? '') : ($report['report_month'] ?? '');
        $safePeriod = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$periodLabel);
        $tempDir = sys_get_temp_dir();
        $xlsxPath = $tempDir . DIRECTORY_SEPARATOR . 'eod_eom_' . uniqid('', true) . '.xlsx';

        $rows = [
            ["{$label} Report Details"],
            ['Period', (string)$periodLabel],
            ['Finalized By', (string)($report['finalized_by_name'] ?? 'Unknown')],
            ['Finalized At', (string)($report['finalized_at'] ?? '')],
            ['Notes', (string)($report['notes'] ?? '')],
            [],
            ['Summary'],
            ['Total Items', (string)($report['total_items'] ?? 0)],
            ['Total Quantity', (string)($report['total_quantity'] ?? 0)],
            ['Total Value', (string)($report['total_value'] ?? 0)],
            [],
            ["{$label} Report Details"],
        ];

        if ($report_type === 'eod') {
            $rows[] = ['No', 'Item Name', 'Type product', 'Brand', 'Location', 'Opening Stock', 'Received', 'Return (to vendor)', 'Total Sold', 'Offline Sale', 'Offline Purchase Back', 'Cancelled Offline Sale', 'Offline Cancelled Purchase Back', 'Marketing Take Out', 'Marketing Return', 'Return Qty', 'Move In', 'Move Out', 'Transfer In', 'Transfer Out', 'Adjustment', 'Closing Stock', 'Remark'];
        } else {
            $rows[] = ['No', 'Item Name', 'Type product', 'Brand', 'Location', 'Opening', 'Closing', 'Average', 'Movements In', 'Movements Out', 'Value', 'Remark'];
        }

        $totalOpeningStock = 0;
        $totalReceivedStock = 0;
        $totalPurchaseReturnVendor = 0;
        $totalSoldStock = 0;
        $totalOfflineSale = 0;
        $totalOfflinePurchaseBack = 0;
        $totalCancelledOfflineSale = 0;
        $totalOfflineCancelledPurchaseBack = 0;
        $totalMarketingTakeOut = 0;
        $totalMarketingReturn = 0;
        $totalReturnStock = 0;
        $totalMoveIn = 0;
        $totalMoveOut = 0;
        $totalTransferIn = 0;
        $totalTransferOut = 0;
        $totalAdjustment = 0;
        $totalClosingStock = 0;
        $totalMoveInEom = 0;
        $totalMoveOutEom = 0;
        $totalClosingValue = 0;

        foreach ($details as $item) {
            if ($report_type === 'eod') {
                $totalOpeningStock += (float)($item['opening_quantity'] ?? 0);
                $totalReceivedStock += (float)($item['daily_received'] ?? 0);
                $totalPurchaseReturnVendor += (float)($item['purchase_return_vendor'] ?? 0);
                $totalSoldStock += (float)($item['total_sold'] ?? 0);
                $totalOfflineSale += (float)($item['offline_sale'] ?? 0);
                $totalOfflinePurchaseBack += (float)($item['offline_purchase_back'] ?? 0);
                $totalCancelledOfflineSale += (float)($item['cancelled_offline_sale'] ?? 0);
                $totalOfflineCancelledPurchaseBack += (float)($item['offline_cancelled_purchase_back'] ?? 0);
                $totalMarketingTakeOut += (float)($item['marketing_take_out'] ?? 0);
                $totalMarketingReturn += (float)($item['marketing_return'] ?? 0);
                $totalReturnStock += (float)($item['return_quantity'] ?? 0);
                $totalMoveIn += (float)($item['movements_in'] ?? 0);
                $totalMoveOut += (float)($item['movements_out'] ?? 0);
                $totalTransferIn += (float)($item['transfer_in'] ?? 0);
                $totalTransferOut += (float)($item['transfer_out'] ?? 0);
                $totalAdjustment += (float)($item['adjustments'] ?? 0);
                $totalClosingStock += (float)($item['quantity_on_hand'] ?? 0);
            } else {
                $totalOpeningStock += (float)($item['opening_quantity'] ?? 0);
                $totalClosingStock += (float)($item['closing_quantity'] ?? 0);
                $totalMoveInEom += (float)($item['movements_in'] ?? 0);
                $totalMoveOutEom += (float)($item['movements_out'] ?? 0);
                $totalClosingValue += (float)($item['closing_value'] ?? 0);
            }
        }

        $locationGroups = eodEomGroupDetailsByLocationForExcel($details);
        $rowNumber = 1;

        foreach ($locationGroups as $group) {
            $items = $group['items'];
            if ($items === []) {
                continue;
            }

            $rows[] = ['Location: ' . $group['label']];

            if ($report_type === 'eod') {
                foreach ($items as $item) {
                    $openingStock = $item['opening_quantity'] ?? 0;
                    $receivedStock = $item['daily_received'] ?? 0;
                    $purchaseReturnVendor = $item['purchase_return_vendor'] ?? 0;
                    $soldStock = $item['total_sold'] ?? 0;
                    $marketingTakeOut = $item['marketing_take_out'] ?? 0;
                    $marketingReturn = $item['marketing_return'] ?? 0;
                    $returnStock = $item['return_quantity'] ?? 0;
                    $moveIn = $item['movements_in'] ?? 0;
                    $moveOut = $item['movements_out'] ?? 0;
                    $transferIn = $item['transfer_in'] ?? 0;
                    $transferOut = $item['transfer_out'] ?? 0;
                    $adjustment = $item['adjustments'] ?? 0;
                    $closingStock = $item['quantity_on_hand'] ?? 0;
                    $locationDisplay = $item['location_code'] ?? $item['location_name'] ?? 'Unknown';

                    $rows[] = [
                        $rowNumber++,
                        $item['item_name'] ?? '',
                        eodEomExcelProductTypeLabel($item['product_type'] ?? null),
                        $item['brand_name'] ?? '',
                        $locationDisplay,
                        number_format((float)$openingStock, 2, '.', ''),
                        number_format((float)$receivedStock, 2, '.', ''),
                        number_format((float)$purchaseReturnVendor, 2, '.', ''),
                        number_format((float)$soldStock, 2, '.', ''),
                        number_format((float)($item['offline_sale'] ?? 0), 2, '.', ''),
                        number_format((float)($item['offline_purchase_back'] ?? 0), 2, '.', ''),
                        number_format((float)($item['cancelled_offline_sale'] ?? 0), 2, '.', ''),
                        number_format((float)($item['offline_cancelled_purchase_back'] ?? 0), 2, '.', ''),
                        number_format((float)$marketingTakeOut, 2, '.', ''),
                        number_format((float)$marketingReturn, 2, '.', ''),
                        number_format((float)$returnStock, 2, '.', ''),
                        number_format((float)$moveIn, 2, '.', ''),
                        number_format((float)$moveOut, 2, '.', ''),
                        number_format((float)$transferIn, 2, '.', ''),
                        number_format((float)$transferOut, 2, '.', ''),
                        number_format((float)$adjustment, 2, '.', ''),
                        number_format((float)$closingStock, 2, '.', ''),
                        '',
                    ];
                }

                $rows[] = [
                    '',
                    '',
                    '',
                    '',
                    'Subtotal - ' . $group['label'],
                    number_format(array_sum(array_column($items, 'opening_quantity')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'daily_received')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'purchase_return_vendor')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'total_sold')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'offline_sale')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'offline_purchase_back')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'cancelled_offline_sale')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'offline_cancelled_purchase_back')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'marketing_take_out')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'marketing_return')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'return_quantity')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'movements_in')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'movements_out')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'transfer_in')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'transfer_out')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'adjustments')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'quantity_on_hand')), 2, '.', ''),
                    '',
                ];
            } else {
                foreach ($items as $item) {
                    $openingStock = $item['opening_quantity'] ?? 0;
                    $closingStock = $item['closing_quantity'] ?? 0;
                    $avgStock = $item['average_quantity'] ?? 0;
                    $moveIn = $item['movements_in'] ?? 0;
                    $moveOut = $item['movements_out'] ?? 0;
                    $closingValue = $item['closing_value'] ?? 0;
                    $locationDisplay = $item['location_code'] ?? $item['location_name'] ?? 'Unknown';

                    $rows[] = [
                        $rowNumber++,
                        $item['item_name'] ?? '',
                        eodEomExcelProductTypeLabel($item['product_type'] ?? null),
                        $item['brand_name'] ?? '',
                        $locationDisplay,
                        number_format((float)$openingStock, 2, '.', ''),
                        number_format((float)$closingStock, 2, '.', ''),
                        number_format((float)$avgStock, 2, '.', ''),
                        number_format((float)$moveIn, 2, '.', ''),
                        number_format((float)$moveOut, 2, '.', ''),
                        number_format((float)$closingValue, 2, '.', ''),
                        '',
                    ];
                }

                $rows[] = [
                    '',
                    '',
                    '',
                    '',
                    'Subtotal - ' . $group['label'],
                    number_format(array_sum(array_column($items, 'opening_quantity')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'closing_quantity')), 2, '.', ''),
                    '-',
                    number_format(array_sum(array_column($items, 'movements_in')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'movements_out')), 2, '.', ''),
                    number_format(array_sum(array_column($items, 'closing_value')), 2, '.', ''),
                    '',
                ];
            }
        }

        if ($report_type === 'eod') {
            $rows[] = [
                '',
                '',
                '',
                '',
                'Total',
                number_format($totalOpeningStock, 2, '.', ''),
                number_format($totalReceivedStock, 2, '.', ''),
                number_format($totalPurchaseReturnVendor, 2, '.', ''),
                number_format($totalSoldStock, 2, '.', ''),
                number_format($totalOfflineSale, 2, '.', ''),
                number_format($totalOfflinePurchaseBack, 2, '.', ''),
                number_format($totalCancelledOfflineSale, 2, '.', ''),
                number_format($totalOfflineCancelledPurchaseBack, 2, '.', ''),
                number_format($totalMarketingTakeOut, 2, '.', ''),
                number_format($totalMarketingReturn, 2, '.', ''),
                number_format($totalReturnStock, 2, '.', ''),
                number_format($totalMoveIn, 2, '.', ''),
                number_format($totalMoveOut, 2, '.', ''),
                number_format($totalTransferIn, 2, '.', ''),
                number_format($totalTransferOut, 2, '.', ''),
                number_format($totalAdjustment, 2, '.', ''),
                number_format($totalClosingStock, 2, '.', ''),
                '',
            ];
        } else {
            $rows[] = [
                '',
                '',
                '',
                '',
                'Total',
                number_format($totalOpeningStock, 2, '.', ''),
                number_format($totalClosingStock, 2, '.', ''),
                '-',
                number_format($totalMoveInEom, 2, '.', ''),
                number_format($totalMoveOutEom, 2, '.', ''),
                number_format($totalClosingValue, 2, '.', ''),
                '',
            ];
        }

        if ($columnMode === 'specific') {
            $rows = eodEomPruneZeroOnlyExcelColumns($rows, $report_type);
        }

        $numCols = 0;
        foreach ($rows as $row) {
            $numCols = max($numCols, count(is_array($row) ? $row : []));
        }
        $rows = array_map(static function ($r) use ($numCols) {
            return array_pad(is_array($r) ? array_values($r) : [], $numCols, '');
        }, $rows);
        $rowStyleKeys = eodEomBuildRowStyleKeysForSoldStyle($rows);
        $columnWidths = $report_type === 'eod'
            ? [8, 28, 14, 16, 12, 11, 11, 13, 11, 13, 13, 11, 11, 11, 11, 11, 11, 12, 14]
            : [8, 28, 14, 16, 12, 12, 12, 11, 12, 12, 14, 14];
        $columnWidths = array_slice($columnWidths, 0, $numCols);

        createSimpleXlsxFile($xlsxPath, $rows, $rowStyleKeys, $columnWidths);

        if (!is_file($xlsxPath) || filesize($xlsxPath) < 64) {
            return [
                'success' => false,
                'error' => 'Excel file was not created or is empty. Enable the PHP zip extension and ensure this folder is writable: '
                    . dirname($xlsxPath),
            ];
        }

        return [
            'success' => true,
            'file_path' => $xlsxPath,
            'file_name' => strtolower($label) . '_stock_report_' . ($safePeriod !== '' ? $safePeriod : date('Y-m-d_H-i-s')) . '.xlsx',
            'caption' => "📊 {$label} stock report export\n📅 {$periodLabel}",
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function generateTelegramForm($pdo, $report_id, $report_type) {
    try {
        if ($report_type === 'eod') {
            $stmt = $pdo->prepare("
                SELECT esr.*, u.name as finalized_by_name
                FROM eod_stock_reports esr
                LEFT JOIN users u ON esr.finalized_by = u.id
                WHERE esr.id = ?
            ");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("
                SELECT esr.*, u.name as finalized_by_name
                FROM eom_stock_reports esr
                LEFT JOIN users u ON esr.finalized_by = u.id
                WHERE esr.id = ?
            ");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$report) {
            return ['success' => false, 'error' => 'Report not found'];
        }

        $report_label = strtoupper($report_type);
        $date_label = $report_type === 'eod' ? $report['report_date'] : $report['report_month'];
        $finalizedBy = escapeTelegramHtml($report['finalized_by_name'] ?? 'Unknown');
        $safeDateLabel = escapeTelegramHtml($date_label);

        $message = "📦 <b>{$report_label} Stock Report</b>\n";
        $message .= "📅 Date : {$safeDateLabel}\n";
        $message .= "👤 Finalize by : {$finalizedBy}\n";
        $message .= "⏰ Finalize at : " . escapeTelegramHtml(date('d M Y H:i', strtotime($report['finalized_at']))) . "\n\n";
        $message .= '📝 Note : ' . escapeTelegramHtml($report['notes'] ?? '');

        return ['success' => true, 'message' => $message];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getTelegramConfigForReportType($reportType = null) {
    global $TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $TELEGRAM_TARGETS;
    global $EOD_TELEGRAM_BOT_TOKEN, $EOD_TELEGRAM_CHAT_ID, $EOD_TELEGRAM_TARGETS;
    global $EOM_TELEGRAM_BOT_TOKEN, $EOM_TELEGRAM_CHAT_ID, $EOM_TELEGRAM_TARGETS;

    $botToken = $TELEGRAM_BOT_TOKEN ?? '';
    $chatId = $TELEGRAM_CHAT_ID ?? '';
    $targets = (isset($TELEGRAM_TARGETS) && is_array($TELEGRAM_TARGETS)) ? $TELEGRAM_TARGETS : [];

    if ($reportType === 'eod') {
        $botToken = $EOD_TELEGRAM_BOT_TOKEN ?? $botToken;
        $chatId = $EOD_TELEGRAM_CHAT_ID ?? $chatId;
        $targets = !empty($EOD_TELEGRAM_TARGETS) && is_array($EOD_TELEGRAM_TARGETS) ? $EOD_TELEGRAM_TARGETS : $targets;
    } elseif ($reportType === 'eom') {
        $botToken = $EOM_TELEGRAM_BOT_TOKEN ?? $botToken;
        $chatId = $EOM_TELEGRAM_CHAT_ID ?? $chatId;
        $targets = !empty($EOM_TELEGRAM_TARGETS) && is_array($EOM_TELEGRAM_TARGETS) ? $EOM_TELEGRAM_TARGETS : $targets;
    }

    if (empty($targets) && !empty($chatId)) {
        $targets = [['chat_id' => $chatId, 'thread_id' => null]];
    }

    return [
        'bot_token' => $botToken,
        'chat_id' => $chatId,
        'targets' => $targets,
    ];
}

// Function to send message to Telegram
function sendToTelegram($message, $reportType = null) {
    $telegramConfig = getTelegramConfigForReportType($reportType);
    $botToken = $telegramConfig['bot_token'];
    $targets = $telegramConfig['targets'];
    
    $results = [];

    if ($botToken === '' || empty($targets)) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'Telegram is not configured for this report type'
        ]];
    }
    
    foreach ($targets as $target) {
        $chat_id = trim($target['chat_id']);
        $thread_id = $target['thread_id'] ?? null;
        
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $params = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        
        // Add thread_id if specified
        if ($thread_id) {
            $params['message_thread_id'] = $thread_id;
        }
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
                'ignore_errors' => true
            ]
        ];
        
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        
        if ($response) {
            $result = json_decode($response, true);
            $results[] = [
                'chat_id' => $chat_id,
                'thread_id' => $thread_id,
                'success' => $result['ok'] ?? false,
                'response' => $result
            ];
        } else {
            $telegramError = 'Failed to send request';
            if (isset($http_response_header) && is_array($http_response_header)) {
                $telegramError = implode(' | ', $http_response_header);
            }

            $results[] = [
                'chat_id' => $chat_id,
                'thread_id' => $thread_id,
                'success' => false,
                'error' => $telegramError
            ];
        }
    }
    
    return $results;
}

function sendPhotoToTelegram($filePath, $fileName, $caption = '', $reportType = null) {
    $telegramConfig = getTelegramConfigForReportType($reportType);
    $botToken = $telegramConfig['bot_token'];
    $targets = $telegramConfig['targets'];

    $results = [];

    if ($botToken === '' || empty($targets)) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'Telegram is not configured for this report type'
        ]];
    }

    if (!file_exists($filePath)) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'Export file not found'
        ]];
    }

    if (!function_exists('curl_init')) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'cURL extension is not available'
        ]];
    }

    $mimeType = mime_content_type($filePath) ?: 'image/jpeg';
    $uploadTimeoutSeconds = 90;
    $connectTimeoutSeconds = 15;

    foreach ($targets as $target) {
        $chat_id = trim($target['chat_id']);
        $thread_id = $target['thread_id'] ?? null;
        $url = "https://api.telegram.org/bot{$botToken}/sendPhoto";

        $params = [
            'chat_id' => $chat_id,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'photo' => new CURLFile($filePath, $mimeType, $fileName)
        ];

        if ($thread_id) {
            $params['message_thread_id'] = $thread_id;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $uploadTimeoutSeconds);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false) {
            $decoded = json_decode($response, true);
            $results[] = [
                'chat_id' => $chat_id,
                'thread_id' => $thread_id,
                'success' => $decoded['ok'] ?? false,
                'response' => $decoded,
                'http_code' => $httpCode,
            ];
        } else {
            $results[] = [
                'chat_id' => $chat_id,
                'thread_id' => $thread_id,
                'success' => false,
                'error' => $curlError !== '' ? $curlError : 'Failed to send photo',
                'http_code' => $httpCode,
            ];
        }
    }

    return $results;
}

function sendDocumentToTelegram($filePath, $fileName, $caption = '', $reportType = null, $mimeType = null) {
    $telegramConfig = getTelegramConfigForReportType($reportType);
    $botToken = $telegramConfig['bot_token'];
    $targets = $telegramConfig['targets'];

    $results = [];

    if ($botToken === '' || empty($targets)) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'Telegram is not configured for this report type'
        ]];
    }

    if (!file_exists($filePath)) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'Export file not found'
        ]];
    }

    if (!function_exists('curl_init')) {
        return [[
            'chat_id' => null,
            'thread_id' => null,
            'success' => false,
            'error' => 'cURL extension is not available'
        ]];
    }

    $uploadTimeoutSeconds = 90;
    $connectTimeoutSeconds = 15;
    $mimeType = $mimeType ?: (mime_content_type($filePath) ?: 'application/octet-stream');

    foreach ($targets as $target) {
        $chat_id = trim($target['chat_id']);
        $thread_id = $target['thread_id'] ?? null;
        $url = "https://api.telegram.org/bot{$botToken}/sendDocument";

        $params = [
            'chat_id' => $chat_id,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'document' => new CURLFile($filePath, $mimeType, $fileName)
        ];

        if ($thread_id) {
            $params['message_thread_id'] = $thread_id;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $uploadTimeoutSeconds);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false) {
            $decoded = json_decode($response, true);
            $results[] = [
                'chat_id' => $chat_id,
                'thread_id' => $thread_id,
                'success' => $decoded['ok'] ?? false,
                'response' => $decoded,
                'http_code' => $httpCode,
            ];
        } else {
            $results[] = [
                'chat_id' => $chat_id,
                'thread_id' => $thread_id,
                'success' => false,
                'error' => $curlError !== '' ? $curlError : 'Failed to send document',
                'http_code' => $httpCode,
            ];
        }
    }

    return $results;
}

// Function to verify EOD/EOM reports against current_inventory and print reductions
function verifyReportIntegrity($pdo, $report_date = null) {
    $verification_results = [];
    
    try {
        // Get current inventory snapshot (aggregate multiple FIFO rows per product)
        $current_sql = "
            SELECT 
                ci.item_name,
                SUM(ci.quantity_on_hand) as current_quantity,
                SUM(COALESCE(ci.total_value, ci.quantity_on_hand * ci.unit_cost, 0)) as current_value,
                CASE WHEN MAX(ps.id) IS NOT NULL THEN 'set' ELSE 'normal' END as item_type
            FROM current_inventory ci
            LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
            WHERE ci.quantity_on_hand > 0
            GROUP BY ci.item_name
            ORDER BY ci.item_name
        ";
        $current_stmt = $pdo->query($current_sql);
        $current_data = $current_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get today's print reductions
        $print_sql = "
            SELECT 
                p.name as item_name,
                SUM(ABS(so.quantity)) as total_reduced
            FROM stock_operations so
            JOIN products p ON so.product_id = p.id
            WHERE so.operation_type = 'outbound'
            AND DATE(so.created_at) = CURDATE()
            GROUP BY p.name
            
            UNION ALL
            
            SELECT 
                TRIM(SUBSTRING(so.notes, LENGTH('Product set sold: ') + 1)) as item_name,
                SUM(ABS(so.quantity)) as total_reduced
            FROM stock_operations so
            WHERE so.operation_type = 'set_outbound'
            AND DATE(so.created_at) = CURDATE()
            AND so.notes LIKE 'Product set sold:%'
            GROUP BY TRIM(SUBSTRING(so.notes, LENGTH('Product set sold: ') + 1))
        ";
        $print_stmt = $pdo->query($print_sql);
        $print_data = $print_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create lookup arrays
        $current_lookup = [];
        foreach ($current_data as $item) {
            $current_lookup[$item['item_name']] = $item;
        }
        
        $print_lookup = [];
        foreach ($print_data as $item) {
            $print_lookup[$item['item_name']] = $item['total_reduced'];
        }
        
        // Check EOD report if date provided
        if ($report_date) {
            $eod_sql = "
                SELECT 
                    esrd.item_name,
                    esrd.quantity_on_hand as eod_quantity,
                    esrd.opening_quantity as opening_quantity
                FROM eod_stock_reports esr
                JOIN eod_stock_report_details esrd ON esr.id = esrd.eod_report_id
                WHERE esr.report_date = ?
            ";
            $eod_stmt = $pdo->prepare($eod_sql);
            $eod_stmt->execute([$report_date]);
            $eod_data = $eod_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $eod_lookup = [];
            foreach ($eod_data as $item) {
                $eod_lookup[$item['item_name']] = $item;
            }
            
            // Verify EOD vs Current + Received (no sold since we removed that tracking)
            foreach ($current_lookup as $item_name => $current_item) {
                $eod_item = $eod_lookup[$item_name] ?? null;
                $received_today = $print_lookup[$item_name] ?? 0; // This is actually received, not sold
                
                if ($eod_item) {
                    $expected_current = $eod_item['quantity_on_hand']; // EOD already includes received
                    $difference = abs($current_item['current_quantity'] - $expected_current);
                    
                    if ($difference > 0.01) { // Allow small floating point differences
                        $verification_results['discrepancies'][] = [
                            'item_name' => $item_name,
                            'type' => 'current vs expected',
                            'eod_quantity' => $eod_item['quantity_on_hand'],
                            'received_today' => $received_today,
                            'expected_current' => $expected_current,
                            'actual_current' => $current_item['current_quantity'],
                            'difference' => $difference
                        ];
                    }
                }
            }
        }
        
        // Check for items in print that aren't in current inventory
        foreach ($print_lookup as $item_name => $reduced) {
            if (!isset($current_lookup[$item_name])) {
                $verification_results['missing_items'][] = [
                    'item_name' => $item_name,
                    'print_reduced' => $reduced,
                    'current_quantity' => 0
                ];
            }
        }
        
        $verification_results['summary'] = [
            'current_inventory_items' => count($current_data),
            'print_reductions_today' => count($print_data),
            'discrepancies_found' => count($verification_results['discrepancies'] ?? []),
            'missing_items_found' => count($verification_results['missing_items'] ?? [])
        ];
        
    } catch (Exception $e) {
        $verification_results['error'] = $e->getMessage();
    }
    
    return $verification_results;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $strictTokenActions = ['finalize_report', 'delete_report', 'send_telegram_test'];
    $requiresStrictToken = in_array($action, $strictTokenActions, true);
    
    // Prevent duplicate submissions on refresh
    if (isset($_POST['form_token']) && isset($_SESSION['form_token'])) {
        if (!hash_equals((string)$_SESSION['form_token'], (string)$_POST['form_token']) && $requiresStrictToken) {
            $errors[] = "Invalid form submission. Please try again.";
            $formToken = bin2hex(random_bytes(32));
            $_SESSION['form_token'] = $formToken;
        }
        // Note: Token will be cleared only after successful finalization
    } elseif ($requiresStrictToken) {
        $errors[] = "Invalid form submission. Please try again.";
        $formToken = bin2hex(random_bytes(32));
        $_SESSION['form_token'] = $formToken;
    }

    if ($action === 'send_telegram_test' && empty($errors)) {
        header('Content-Type: application/json');

        $testMessage = "🧪 <b>Telegram Test</b>\n" .
            "⏰ " . date('d M Y H:i:s') . "\n" .
            "✅ This is a test message from the EOD/EOM finalize screen.";

        $testReportType = $_POST['report_type'] ?? 'eod';

        $results = sendToTelegram($testMessage, $testReportType);
        $successful = array_filter($results, fn($result) => $result['success']);

        echo json_encode([
            'success' => count($successful) > 0,
            'sent_count' => count($successful),
            'targets' => $results,
        ]);
        exit;
    }

    if ($action === 'delete_report' && empty($errors)) {
        require_role_or_permission(['admin'], 'eod_eom_reports.delete');
        $report_id = (int)($_POST['report_id'] ?? 0);
        $report_type = $_POST['report_type'] ?? '';

        if ($report_id <= 0 || !in_array($report_type, ['eod', 'eom'], true)) {
            $errors[] = 'Invalid report.';
        } else {
            try {
                $pdo->beginTransaction();

                if ($report_type === 'eod') {
                    $stmt = $pdo->prepare('SELECT id, status, report_date FROM eod_stock_reports WHERE id = ?');
                    $stmt->execute([$report_id]);
                    $report = $stmt->fetch(PDO::FETCH_ASSOC);
                    $label = $report['report_date'] ?? '';
                } else {
                    $stmt = $pdo->prepare('SELECT id, status, report_month FROM eom_stock_reports WHERE id = ?');
                    $stmt->execute([$report_id]);
                    $report = $stmt->fetch(PDO::FETCH_ASSOC);
                    $label = $report['report_month'] ?? '';
                }

                if (!$report) {
                    throw new Exception('Report not found.');
                }
                if (($report['status'] ?? '') !== 'draft') {
                    throw new Exception('Only draft reports can be deleted.');
                }

                // Remove attachments (if any) and delete files
                $stmt = $pdo->prepare('SELECT id, file_path FROM eod_eom_report_attachments WHERE report_id = ? AND report_type = ?');
                $stmt->execute([$report_id, $report_type]);
                $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($attachments as $att) {
                    if (!empty($att['file_path'])) {
                        upload_delete_local_file($att['file_path'], 'eod_eom_reports');
                        $thumb = __DIR__ . '/../uploads/eod_eom_reports/thumbnails/thumb_' . $att['id'] . '.jpg';
                        if (is_file($thumb)) {
                            @unlink($thumb);
                        }
                    }
                }
                $stmt = $pdo->prepare('DELETE FROM eod_eom_report_attachments WHERE report_id = ? AND report_type = ?');
                $stmt->execute([$report_id, $report_type]);

                // Delete main report (details are deleted via FK cascade)
                if ($report_type === 'eod') {
                    $stmt = $pdo->prepare('DELETE FROM eod_stock_reports WHERE id = ?');
                    $stmt->execute([$report_id]);
                } else {
                    $stmt = $pdo->prepare('DELETE FROM eom_stock_reports WHERE id = ?');
                    $stmt->execute([$report_id]);
                }

                $pdo->commit();
                $success = 'Draft report deleted: ' . $label;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Error deleting report: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'generate_eod_report' && empty($errors)) {
        // Debug logging
        error_log("=== EOD REPORT GENERATION DEBUG ===");
        error_log("POST Data: " . json_encode($_POST));
        error_log("Errors Count: " . count($errors));
        
        try {
            $report_date = $_POST['report_date'] ?? date('Y-m-d');
            error_log("Report Date: $report_date");

            // Check if report already exists
            $stmt = $pdo->prepare("SELECT id FROM eod_stock_reports WHERE report_date = ?");
            $stmt->execute([$report_date]);
            $existing_report = $stmt->fetch();
            
            error_log("Existing Report: " . ($existing_report ? "YES" : "NO"));

            if ($existing_report) {
                $errors[] = "EOD report for $report_date already exists";
                error_log("ERROR: Report already exists for $report_date");
            } else {
                error_log("Starting EOD report generation...");
                // Generate EOD report
                $pdo->beginTransaction();

                // Create main report record
                $stmt = $pdo->prepare("
                    INSERT INTO eod_stock_reports (report_date, created_by, status)
                    VALUES (?, ?, 'draft')
                ");
                $stmt->execute([$report_date, $_SESSION['user_id'] ?? null]);
                $report_id = $pdo->lastInsertId();

                // Inventory source must match inventory_view.php (inventory + sets + locations)
                // Closing Stock comes from current snapshot; Opening Stock comes from the most recent finalized EOD closing snapshot before the selected date.
                $returnedOrdersSql = eodEomReturnedOrdersSql($pdo);
                $inventory_sql = "
                    SELECT
                        combined.item_name,
                        combined.sku,
                        combined.quantity_on_hand,
                        combined.available_quantity,
                        combined.unit_cost,
                        combined.total_value,
                        combined.storage_location_id,
                        combined.last_updated,
                        COALESCE(prev.opening_quantity, 0) as opening_quantity,
                        COALESCE(received.daily_received, 0) as daily_received,
                        COALESCE(returns.return_quantity, 0) as return_quantity,
                        COALESCE(moves.movements_in, 0) as movements_in,
                        COALESCE(moves.movements_out, 0) as movements_out,
                        COALESCE(moves.transfer_in, 0) as transfer_in,
                        COALESCE(moves.transfer_out, 0) as transfer_out,
                        COALESCE(moves.adjustments, 0) as adjustments
                    FROM (
                        SELECT
                            raw.item_name,
                            raw.sku,
                            raw.storage_location_id,
                            SUM(raw.quantity_on_hand) as quantity_on_hand,
                            SUM(raw.available_quantity) as available_quantity,
                            SUM(raw.total_value) as total_value,
                            CASE
                                WHEN SUM(raw.quantity_on_hand) > 0 THEN SUM(raw.total_value) / SUM(raw.quantity_on_hand)
                                ELSE 0
                            END as unit_cost,
                            MAX(raw.last_updated) as last_updated
                        FROM (
                            SELECT
                                ci.item_name,
                                ci.sku,
                                ci.quantity_on_hand,
                                ci.quantity_on_hand as available_quantity,
                                ci.total_value,
                                ci.storage_location_id,
                                ci.last_updated
                            FROM current_inventory ci
                            LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
                            WHERE ps.id IS NULL

                            UNION ALL

                            SELECT
                                ps.set_name as item_name,
                                CONCAT('SET-', ps.id) as sku,
                                ps.available_stock as quantity_on_hand,
                                ps.available_stock as available_quantity,
                                (ps.available_stock * ps.selling_price) as total_value,
                                loc_finder.location_id as storage_location_id,
                                ps.created_at as last_updated
                            FROM product_sets ps
                            LEFT JOIN (
                                SELECT DISTINCT product_set_id,
                                       CAST(SUBSTRING(action_details, LOCATE('storage_location_id:', action_details) + LENGTH('storage_location_id:'), 255) AS UNSIGNED) as location_id
                                FROM product_set_audit_log
                                WHERE action_type IN ('created', 'updated')
                                  AND action_details LIKE '%storage_location_id:%'
                            ) loc_finder ON ps.id = loc_finder.product_set_id
                            WHERE ps.is_active = 1
                        ) raw
                        GROUP BY raw.item_name, raw.sku, raw.storage_location_id
                    ) combined
                    LEFT JOIN (
                        SELECT
                            esrd.item_name,
                            esrd.sku,
                            esrd.storage_location_id,
                            esrd.quantity_on_hand as opening_quantity
                        FROM eod_stock_report_details esrd
                        JOIN eod_stock_reports esr ON esr.id = esrd.eod_report_id
                        JOIN (
                            SELECT
                                latest_esrd.item_name,
                                latest_esrd.sku,
                                latest_esrd.storage_location_id,
                                MAX(latest_esr.report_date) as latest_report_date
                            FROM eod_stock_report_details latest_esrd
                            JOIN eod_stock_reports latest_esr ON latest_esr.id = latest_esrd.eod_report_id
                            WHERE latest_esr.status = 'finalized'
                              AND latest_esr.report_date < ?
                            GROUP BY latest_esrd.item_name, latest_esrd.sku, latest_esrd.storage_location_id
                        ) latest_prev
                            ON latest_prev.item_name = esrd.item_name
                           AND latest_prev.sku <=> esrd.sku
                           AND latest_prev.storage_location_id <=> esrd.storage_location_id
                           AND latest_prev.latest_report_date = esr.report_date
                        WHERE esr.status = 'finalized'
                    ) prev
                        ON prev.item_name = combined.item_name
                       AND prev.sku <=> combined.sku
                       AND prev.storage_location_id <=> combined.storage_location_id
                    LEFT JOIN (
                        SELECT 
                             sri.item_name,
                            sri.sku,
                            sri.storage_location_id,
                            SUM(sri.quantity_received) as daily_received
                        FROM storage_receipt_items sri
                        JOIN storage_receipts sr ON sri.receipt_id = sr.id
                        WHERE DATE(sr.receipt_date) = ?
                        GROUP BY sri.item_name, sri.sku, sri.storage_location_id

                        UNION ALL

                        SELECT 
                            ps.set_name as item_name,
                            CONCAT('SET-', ps.id) as sku,
                            loc_finder.location_id as storage_location_id,
                            ps.total_created as daily_received
                        FROM product_sets ps
                        LEFT JOIN (
                            SELECT DISTINCT product_set_id,
                                   CAST(SUBSTRING(action_details, LOCATE('storage_location_id:', action_details) + LENGTH('storage_location_id:'), 255) AS UNSIGNED) as location_id
                            FROM product_set_audit_log
                            WHERE action_type IN ('created', 'updated')
                              AND action_details LIKE '%storage_location_id:%'
                        ) loc_finder ON ps.id = loc_finder.product_set_id
                        WHERE DATE(ps.created_at) = ?
                        GROUP BY ps.id, ps.set_name, loc_finder.location_id
                    ) received ON received.item_name = combined.item_name
                              AND received.sku <=> combined.sku
                              AND received.storage_location_id <=> combined.storage_location_id
                    LEFT JOIN (
                        SELECT
                            restored.item_name,
                            SUM(restored.return_quantity) as return_quantity
                        FROM (
                            SELECT
                                p.name as item_name,
                                SUM(oi.quantity) as return_quantity
                            FROM {$returnedOrdersSql} returned_orders
                            JOIN orders o ON o.order_code = returned_orders.inv
                            JOIN order_items oi ON oi.order_id = o.id
                            JOIN products p ON p.id = oi.product_id
                            WHERE DATE(returned_orders.return_date) = ?
                              AND COALESCE(p.product_type, 'normal') <> 'set'
                            GROUP BY p.name

                            UNION ALL

                            SELECT
                                p.name as item_name,
                                SUM(psi.quantity * oi.quantity) as return_quantity
                            FROM {$returnedOrdersSql} returned_orders
                            JOIN orders o ON o.order_code = returned_orders.inv
                            JOIN order_items oi ON oi.order_id = o.id
                            JOIN products set_product ON set_product.id = oi.product_id
                            JOIN product_sets ps ON ps.set_name = set_product.name
                            JOIN product_set_items psi ON psi.product_set_id = ps.id
                            JOIN products p ON p.id = psi.product_id
                            WHERE DATE(returned_orders.return_date) = ?
                              AND COALESCE(set_product.product_type, 'normal') = 'set'
                            GROUP BY p.name
                        ) restored
                        GROUP BY restored.item_name
                    ) returns ON returns.item_name = combined.item_name
                             AND combined.storage_location_id = (
                                 SELECT id
                                 FROM storage_locations
                                 WHERE is_default = 1
                                 LIMIT 1
                             )
                    LEFT JOIN (
                        SELECT
                            mm.item_name,
                            mm.storage_location_id,
                                SUM(mm.movements_in) as movements_in,
                                SUM(mm.movements_out) as movements_out,
                                SUM(mm.transfer_in) as transfer_in,
                                SUM(mm.transfer_out) as transfer_out,
                                SUM(mm.adjustments) as adjustments
                        FROM (
                            SELECT
                                p.name as item_name,
                                CAST(
                                    SUBSTRING(sm.notes, LOCATE('[Location:', sm.notes) + LENGTH('[Location:'), LOCATE(']', sm.notes, LOCATE('[Location:', sm.notes)) - (LOCATE('[Location:', sm.notes) + LENGTH('[Location:')))
                                    AS UNSIGNED
                                ) as storage_location_id,
                                SUM(CASE WHEN sm.movement_type = 'in' THEN sm.quantity ELSE 0 END) as movements_in,
                                SUM(CASE WHEN sm.movement_type = 'out' THEN sm.quantity ELSE 0 END) as movements_out,
                                0 as transfer_in,
                                0 as transfer_out,
                                SUM(CASE WHEN sm.movement_type = 'adjustment' THEN sm.quantity ELSE 0 END) as adjustments
                            FROM stock_movements sm
                            JOIN products p ON sm.item_id = p.id
                            WHERE DATE(sm.created_at) = ?
                              AND sm.notes LIKE '%[Location:%]'
                              AND sm.movement_type IN ('in', 'out', 'adjustment')
                            GROUP BY p.name, storage_location_id

                            UNION ALL

                            SELECT
                                p.name as item_name,
                                CAST(
                                    SUBSTRING(sm.notes, LOCATE('To:', sm.notes) + LENGTH('To:'), LOCATE(']', sm.notes, LOCATE('To:', sm.notes)) - (LOCATE('To:', sm.notes) + LENGTH('To:')))
                                    AS UNSIGNED
                                ) as storage_location_id,
                                0 as movements_in,
                                0 as movements_out,
                                SUM(sm.quantity) as transfer_in,
                                0 as transfer_out,
                                0 as adjustments
                            FROM stock_movements sm
                            JOIN products p ON sm.item_id = p.id
                            WHERE DATE(sm.created_at) = ?
                              AND sm.movement_type = 'transfer'
                              AND sm.notes LIKE '%[From:%To:%]'
                            GROUP BY p.name, storage_location_id

                            UNION ALL

                            SELECT
                                p.name as item_name,
                                CAST(
                                    SUBSTRING(sm.notes, LOCATE('[From:', sm.notes) + LENGTH('[From:'), LOCATE(' ', sm.notes, LOCATE('[From:', sm.notes)) - (LOCATE('[From:', sm.notes) + LENGTH('[From:')))
                                    AS UNSIGNED
                                ) as storage_location_id,
                                0 as movements_in,
                                0 as movements_out,
                                0 as transfer_in,
                                SUM(sm.quantity) as transfer_out,
                                0 as adjustments
                            FROM stock_movements sm
                            JOIN products p ON sm.item_id = p.id
                            WHERE DATE(sm.created_at) = ?
                              AND sm.movement_type = 'transfer'
                              AND sm.notes LIKE '%[From:%To:%]'
                            GROUP BY p.name, storage_location_id
                        ) mm
                        WHERE mm.storage_location_id IS NOT NULL
                        GROUP BY mm.item_name, mm.storage_location_id
                    ) moves
                        ON moves.item_name = combined.item_name
                       AND moves.storage_location_id <=> combined.storage_location_id
                    ORDER BY combined.item_name, combined.sku
                ";

                $inventory_stmt = $pdo->prepare($inventory_sql);
                $placeholderCount = substr_count($inventory_sql, '?');
                $inventoryParams = array_fill(0, $placeholderCount, $report_date);
                error_log('EOD inventory_sql placeholders: ' . $placeholderCount . ' params: ' . count($inventoryParams));
                $inventory_stmt->execute($inventoryParams);
                $inventory_data = $inventory_stmt->fetchAll();
                
                error_log("Inventory Data Count: " . count($inventory_data));

                $total_items = 0;
                $total_quantity = 0;
                $total_value = 0;

                // Insert inventory details
                foreach ($inventory_data as $index => $item) {
                    // Include all products (even with 0 quantity) in the report

                        // EOD meaning:
                        // - Opening Stock: yesterday's Closing Stock (from last finalized EOD)
                        // - Closing Stock: today's current_inventory snapshot (already includes reductions + purchases)
                        $opening_stock = (float)($item['opening_quantity'] ?? 0);
                        $closing_quantity = (float)($item['quantity_on_hand'] ?? 0);
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO eod_stock_report_details
                            (eod_report_id, item_name, sku, storage_location_id, quantity_on_hand,
                             available_quantity, opening_quantity, daily_received, return_quantity,
                             movements_in, movements_out, transfer_in, transfer_out, adjustments,
                             unit_cost, total_value, last_movement_date)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $report_id,
                            $item['item_name'],
                            $item['sku'],
                            $item['storage_location_id'],
                            $closing_quantity,
                            $item['available_quantity'],
                            $opening_stock,
                            $item['daily_received'],
                            $item['return_quantity'],
                            $item['movements_in'],
                            $item['movements_out'],
                            $item['transfer_in'],
                            $item['transfer_out'],
                            $item['adjustments'],
                            $item['unit_cost'],
                            $item['total_value'],
                            $item['last_updated']
                        ]);

                        $total_items++;
                        $total_quantity += $closing_quantity;
                        $total_value += $item['total_value'];
                }
                
                error_log("Totals - Items: $total_items, Quantity: $total_quantity, Value: $total_value");

                // Update main report with totals
                $stmt = $pdo->prepare("
                    UPDATE eod_stock_reports
                    SET total_items = ?, total_quantity = ?, total_value = ?
                    WHERE id = ?
                ");
                $stmt->execute([$total_items, $total_quantity, $total_value, $report_id]);

                $pdo->commit();
                $success = "EOD report generated: {$report_date}\n" .
                           "Items captured: {$total_items}\n" .
                           "Includes: opening stock + received items\n" .
                           "Next step: finalize the report and attach supporting documents.";
                error_log("SUCCESS: EOD report generated - $success");
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error generating EOD report: " . $e->getMessage();
        }
    }

    if ($action === 'generate_eom_report' && empty($errors)) {
        try {
            $report_month = $_POST['report_month'] ?? date('Y-m');
            $report_date = $_POST['report_date'] ?? date('Y-m-d');

            // Check if report already exists
            $stmt = $pdo->prepare("SELECT id FROM eom_stock_reports WHERE report_month = ?");
            $stmt->execute([$report_month]);
            $existing_report = $stmt->fetch();

            if ($existing_report) {
                $errors[] = "EOM report for $report_month already exists";
            } else {
                // Generate EOM report
                $pdo->beginTransaction();

                // Create main report record
                $stmt = $pdo->prepare("
                    INSERT INTO eom_stock_reports (report_month, report_date, created_by, status)
                    VALUES (?, ?, ?, 'draft')
                ");
                $stmt->execute([$report_month, $report_date, $_SESSION['user_id'] ?? null]);
                $report_id = $pdo->lastInsertId();

                // Inventory source must match inventory_view.php (inventory + sets + locations)
                $returnedOrdersSql = eodEomReturnedOrdersSql($pdo);
                $inventory_sql = "
                    SELECT
                        combined.item_name,
                        combined.sku,
                        combined.quantity_on_hand as closing_quantity,
                        combined.unit_cost,
                        combined.total_value as closing_value,
                        combined.storage_location_id,
                        COALESCE(returns.return_quantity, 0) as return_quantity
                    FROM (
                        SELECT
                            raw.item_name,
                            raw.sku,
                            raw.storage_location_id,
                            SUM(raw.quantity_on_hand) as quantity_on_hand,
                            SUM(raw.total_value) as total_value,
                            CASE
                                WHEN SUM(raw.quantity_on_hand) > 0 THEN SUM(raw.total_value) / SUM(raw.quantity_on_hand)
                                ELSE 0
                            END as unit_cost
                        FROM (
                            SELECT
                                ci.item_name,
                                ci.sku,
                                ci.quantity_on_hand,
                                ci.total_value,
                                ci.storage_location_id
                            FROM current_inventory ci
                            LEFT JOIN product_sets ps ON ci.item_name = ps.set_name
                            WHERE ps.id IS NULL

                            UNION ALL

                            SELECT
                                ps.set_name as item_name,
                                CONCAT('SET-', ps.id) as sku,
                                ps.available_stock as quantity_on_hand,
                                (ps.available_stock * ps.selling_price) as total_value,
                                loc_finder.location_id as storage_location_id
                            FROM product_sets ps
                            LEFT JOIN (
                                SELECT DISTINCT product_set_id,
                                       CAST(SUBSTRING(action_details, LOCATE('storage_location_id:', action_details) + LENGTH('storage_location_id:'), 255) AS UNSIGNED) as location_id
                                FROM product_set_audit_log
                                WHERE action_type IN ('created', 'updated')
                                  AND action_details LIKE '%storage_location_id:%'
                            ) loc_finder ON ps.id = loc_finder.product_set_id
                            WHERE ps.is_active = 1
                        ) raw
                        GROUP BY raw.item_name, raw.sku, raw.storage_location_id
                    ) combined
                    LEFT JOIN (
                        SELECT
                            restored.item_name,
                            SUM(restored.return_quantity) as return_quantity
                        FROM (
                            SELECT
                                p.name as item_name,
                                SUM(oi.quantity) as return_quantity
                            FROM {$returnedOrdersSql} returned_orders
                            JOIN orders o ON o.order_code = returned_orders.inv
                            JOIN order_items oi ON oi.order_id = o.id
                            JOIN products p ON p.id = oi.product_id
                            WHERE DATE(returned_orders.return_date) >= ?
                              AND DATE(returned_orders.return_date) <= ?
                              AND COALESCE(p.product_type, 'normal') <> 'set'
                            GROUP BY p.name

                            UNION ALL

                            SELECT
                                p.name as item_name,
                                SUM(psi.quantity * oi.quantity) as return_quantity
                            FROM {$returnedOrdersSql} returned_orders
                            JOIN orders o ON o.order_code = returned_orders.inv
                            JOIN order_items oi ON oi.order_id = o.id
                            JOIN products set_product ON set_product.id = oi.product_id
                            JOIN product_sets ps ON ps.set_name = set_product.name
                            JOIN product_set_items psi ON psi.product_set_id = ps.id
                            JOIN products p ON p.id = psi.product_id
                            WHERE DATE(returned_orders.return_date) >= ?
                              AND DATE(returned_orders.return_date) <= ?
                              AND COALESCE(set_product.product_type, 'normal') = 'set'
                            GROUP BY p.name
                        ) restored
                        GROUP BY restored.item_name
                    ) returns ON returns.item_name = combined.item_name
                             AND combined.storage_location_id = (
                                 SELECT id
                                 FROM storage_locations
                                 WHERE is_default = 1
                                 LIMIT 1
                             )
                    ORDER BY combined.item_name, combined.sku
                ";

                $monthStart = $report_month . '-01';
                $monthEnd = date('Y-m-t', strtotime($monthStart));
                $inventory_stmt = $pdo->prepare($inventory_sql);
                $inventory_stmt->execute([$monthStart, $monthEnd, $monthStart, $monthEnd]);
                $inventory_data = $inventory_stmt->fetchAll();

                $total_items = 0;
                $total_quantity = 0;
                $total_value = 0;

                // Insert inventory details
                foreach ($inventory_data as $item) {
                    $stmt = $pdo->prepare("
                        INSERT INTO eom_stock_report_details
                        (eom_report_id, item_name, sku, storage_location_id, closing_quantity, unit_cost, closing_value, return_quantity)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $report_id,
                        $item['item_name'],
                        $item['sku'],
                        $item['storage_location_id'],
                        $item['closing_quantity'],
                        $item['unit_cost'],
                        $item['closing_value'],
                        $item['return_quantity']
                    ]);

                    $total_items++;
                    $total_quantity += $item['closing_quantity'];
                    $total_value += $item['closing_value'];
                }

                // Update main report with totals
                $stmt = $pdo->prepare("
                    UPDATE eom_stock_reports
                    SET total_items = ?, total_quantity = ?, total_value = ?
                    WHERE id = ?
                ");
                $stmt->execute([$total_items, $total_quantity, $total_value, $report_id]);

                $pdo->commit();
                $success = "EOM report generated: {$report_month}\n" .
                           "Items summarized: {$total_items}\n" .
                           "Snapshot: end-of-month inventory\n" .
                           "Next step: finalize the report and attach supporting documents.";
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error generating EOM report: " . $e->getMessage();
        }

    } elseif ($action === 'verify_integrity' && empty($errors)) {
        // Verify report integrity
        $report_date = $_POST['verify_date'] ?? date('Y-m-d');
        $verification_results = verifyReportIntegrity($pdo, $report_date);
        
        if (isset($verification_results['error'])) {
            $errors[] = "Verification error: " . $verification_results['error'];
        } else {
            $summary = $verification_results['summary'];
            $success = "Verification completed. Found {$summary['discrepancies_found']} discrepancies and {$summary['missing_items_found']} missing items.";
            
            // Store results for display
            $_SESSION['verification_results'] = $verification_results;
        }
    }

    if ($action === 'finalize_report') {
        $report_id = (int)$_POST['report_id'];
        $report_type = $_POST['report_type'];
        $notes = trim($_POST['notes'] ?? '');

        // Validate required fields
        if (empty($notes)) {
            $errors[] = "Notes are required to finalize the report";
        }

        if (!isset($_FILES['attachments']) || empty($_FILES['attachments']['name'][0])) {
            $errors[] = "At least one attachment is required to finalize the report";
        }

        if (empty($errors)) {
            try {
                // Handle multiple file uploads
                $uploaded_files = [];
                $total_original_size = 0;
                $total_compressed_size = 0;

                // Process each uploaded file
                foreach ($_FILES['attachments']['name'] as $key => $filename) {
                    if (empty($filename)) continue;

                    $file = [
                        'name' => $_FILES['attachments']['name'][$key],
                        'type' => $_FILES['attachments']['type'][$key],
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$key],
                        'error' => $_FILES['attachments']['error'][$key],
                        'size' => $_FILES['attachments']['size'][$key]
                    ];

                    // Validate file
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        $errors[] = "Upload error for file: " . $filename;
                        continue;
                    }

                    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'];
                    $image_extensions = ['jpg', 'jpeg', 'png', 'webp'];

                    if (!in_array($file_extension, $allowed_extensions)) {
                        $errors[] = "Invalid file type for {$filename}. Allowed types: PDF, Word, Excel, Images";
                        continue;
                    }

                    if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit per file
                        $errors[] = "File {$filename} is too large. Maximum size is 10MB per file";
                        continue;
                    }

                    // Generate unique filename
                    $unique_filename = uniqid('eod_eom_' . $report_type . '_', true) . '.' . $file_extension;
                    $telegram_temp_path = '';

                    // Compress images (25% quality for very low storage)
                    if (in_array($file_extension, $image_extensions)) {
                        $file_path = tempnam(sys_get_temp_dir(), 'eod_eom_');
                        $compressed = compressImage($file['tmp_name'], $file_path, 25);
                        if (!$compressed) {
                            @unlink($file_path);
                            $file_path = $file['tmp_name'];
                        }
                        $storedPath = upload_store_file_path($file_path, 'eod_eom_reports', $unique_filename, null, (string)$file['type'], false);
                        $final_size = filesize($file_path);
                        $telegram_temp_path = $file_path;
                        $total_original_size += $file['size'];
                        $total_compressed_size += $final_size;
                    } else {
                        $storedPath = upload_store_uploaded_file($file, 'eod_eom_reports', $unique_filename, null, (string)$file['type']);
                        $final_size = $file['size'];
                        $telegram_temp_path = $file['tmp_name'];
                        $total_original_size += $file['size'];
                        $total_compressed_size += $final_size;
                    }
                    $stored_filename = preg_replace('#^uploads/eod_eom_reports/#', '', $storedPath);

                    // Store file info for database insertion
                    $uploaded_files[] = [
                        'original_name' => $file['name'],
                        'file_path' => $stored_filename,
                        'file_size' => $final_size,
                        'mime_type' => $file['type'],
                        'temp_path' => $telegram_temp_path,
                    ];
                }

                if (empty($uploaded_files)) {
                    $errors[] = "No valid files were uploaded";
                } else {
                    // Update database
                    $pdo->beginTransaction();

                    $finalized_at = date('Y-m-d H:i:s');
                    $finalized_by = $_SESSION['user_id'] ?? null;

                    if ($report_type === 'eod') {
                        $stmt = $pdo->prepare("
                            UPDATE eod_stock_reports
                            SET status = 'finalized',
                                notes = ?,
                                finalized_at = ?,
                                finalized_by = ?
                            WHERE id = ?
                        ");
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE eom_stock_reports
                            SET status = 'finalized',
                                notes = ?,
                                finalized_at = ?,
                                finalized_by = ?
                            WHERE id = ?
                        ");
                    }

                    $stmt->execute([$notes, $finalized_at, $finalized_by, $report_id]);

                    // Insert attachments into database
                    $stmt = $pdo->prepare("
                        INSERT INTO eod_eom_report_attachments
                        (report_id, report_type, file_path, original_filename, file_size, mime_type, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($uploaded_files as $file_info) {
                        $stmt->execute([
                            $report_id,
                            $report_type,
                            $file_info['file_path'],
                            $file_info['original_name'],
                            $file_info['file_size'],
                            $file_info['mime_type'],
                            $finalized_by
                        ]);
                    }

                    $pdo->commit();

                    $compression_msg = "";
                    if ($total_original_size > $total_compressed_size && extension_loaded('gd')) {
                        $saved_bytes = $total_original_size - $total_compressed_size;
                        $saved_mb = round($saved_bytes / 1024 / 1024, 2);
                        $compression_msg = " (saved {$saved_mb}MB with compression)";
                    } elseif (!extension_loaded('gd') && count(array_filter($uploaded_files, function($f) {
                        return in_array(strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp']);
                    })) > 0) {
                        $compression_msg = " (Note: GD extension not available - images not compressed)";
                    }

                    $success = "Report finalized successfully with " . count($uploaded_files) . " attachment(s){$compression_msg}!";

                    // Generate and send Telegram notification if requested
                    $send_telegram = isset($_POST['send_telegram_notification']) && $_POST['send_telegram_notification'] == '1';
                    if ($send_telegram) {
                        $telegram_result = generateTelegramForm($pdo, $report_id, $report_type);
                        $telegram_file_result = eodEomBuildStockReportExcel($pdo, $report_id, $report_type, 'specific');

                        if ($telegram_result['success'] && $telegram_file_result['success']) {
                            $telegram_results = sendDocumentToTelegram(
                                $telegram_file_result['file_path'],
                                $telegram_file_result['file_name'],
                                $telegram_result['message'],
                                $report_type
                            );

                            $successful_sends = array_filter($telegram_results, function($result) {
                                return $result['success'];
                            });

                            if (count($successful_sends) > 0) {
                                $success .= "\n📱 Telegram Excel report sent to " . count($successful_sends) . " target(s)!";

                                $attachment_send_count = 0;
                                $attachment_failure_messages = [];

                                foreach ($uploaded_files as $file_info) {
                                    $attachmentRelativePath = ltrim(str_replace('\\', '/', (string)$file_info['file_path']), '/');
                                    $attachmentFullPath = !empty($file_info['temp_path'])
                                        ? (string)$file_info['temp_path']
                                        : __DIR__ . '/../uploads/eod_eom_reports/' . $attachmentRelativePath;
                                    $attachmentCaption = "📎 Report attachment: " . escapeTelegramHtml($file_info['original_name']);
                                    $isImageAttachment = !empty($file_info['mime_type']) && strpos($file_info['mime_type'], 'image/') === 0;

                                    if ($isImageAttachment) {
                                        $attachmentUrl = uploaded_file_url($attachmentRelativePath, 'eod_eom_reports');
                                        if ($attachmentUrl === '' || !upload_storage_is_remote_path($attachmentUrl)) {
                                            $attachmentResults = [[
                                                'success' => false,
                                                'error' => 'Public image URL unavailable: ' . $attachmentRelativePath,
                                            ]];
                                        } else {
                                            $attachmentMessage = $attachmentCaption . "\n"
                                                . 'Image: <a href="' . htmlspecialchars($attachmentUrl, ENT_QUOTES, 'UTF-8') . '">Open image</a>';
                                            $attachmentResults = sendToTelegram($attachmentMessage, $report_type);
                                        }
                                    } elseif (!is_file($attachmentFullPath)) {
                                        $attachmentResults = [[
                                            'success' => false,
                                            'error' => 'Attachment file not found: ' . $attachmentRelativePath,
                                        ]];
                                    } else {
                                        $attachmentResults = sendDocumentToTelegram(
                                            $attachmentFullPath,
                                            $file_info['original_name'],
                                            $attachmentCaption,
                                            $report_type,
                                            $file_info['mime_type'] ?: null
                                        );
                                    }

                                    $successfulAttachmentSends = array_filter($attachmentResults, function($result) {
                                        return $result['success'];
                                    });

                                    if (count($successfulAttachmentSends) > 0) {
                                        $attachment_send_count++;
                                    } else {
                                        $attachment_failure_messages[] = $file_info['original_name'] . ': ' . implode(' | ', array_unique(array_map(function($result) {
                                            if (!empty($result['error'])) {
                                                return $result['error'];
                                            }

                                            if (!empty($result['response']['description'])) {
                                                return $result['response']['description'];
                                            }

                                            return 'Unknown Telegram error';
                                        }, $attachmentResults)));
                                    }
                                }

                                if ($attachment_send_count > 0) {
                                    $success .= "\n📎 Telegram attachments sent: {$attachment_send_count}/" . count($uploaded_files) . " file(s).";
                                }

                                if (!empty($attachment_failure_messages)) {
                                    $success .= "\n⚠️ Some Telegram attachments failed: " . implode(' || ', $attachment_failure_messages);
                                    error_log("Telegram attachment send failed: " . json_encode($attachment_failure_messages));
                                }
                            } else {
                                $error_messages = array_map(function($result) {
                                    if (!empty($result['error'])) {
                                        return $result['error'];
                                    }

                                    if (!empty($result['response']['description'])) {
                                        return $result['response']['description'];
                                    }

                                    return 'Unknown Telegram error';
                                }, $telegram_results);

                                $success .= "\n⚠️ Failed to send Telegram Excel report: " . implode(' | ', array_unique($error_messages));
                                error_log("Telegram document send failed: " . json_encode($telegram_results));
                            }

                            foreach ($uploaded_files as $file_info) {
                                if (!empty($file_info['temp_path']) && is_file((string)$file_info['temp_path'])) {
                                    @unlink((string)$file_info['temp_path']);
                                }
                            }
                            @unlink($telegram_file_result['file_path']);
                        } elseif (!$telegram_file_result['success']) {
                            $success .= "\n⚠️ Failed to generate Telegram Excel file: " . $telegram_file_result['error'];
                            error_log("Telegram Excel file generation failed: " . $telegram_file_result['error']);
                        } else {
                            $success .= "\n⚠️ Failed to generate Telegram form: " . $telegram_result['error'];
                            error_log("Telegram form generation failed: " . $telegram_result['error']);
                        }
                    }
                    // Clear form token after successful finalization
                    unset($_SESSION['form_token']);
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "Error finalizing report: " . $e->getMessage();
            }
        }
    }
}

// Get storage locations for filter
try {
    $locationsStmt = $pdo->query('SELECT * FROM storage_locations WHERE is_active = 1 ORDER BY location_code');
    $locations = $locationsStmt->fetchAll();
} catch (PDOException $e) {
    $locations = [];
}

// Get existing reports based on type
if ($report_type === 'eod') {
    $reports_sql = "
        SELECT esr.*, u.name as created_by_name
        FROM eod_stock_reports esr
        LEFT JOIN users u ON esr.created_by = u.id
        ORDER BY esr.report_date DESC
        LIMIT 50
    ";
    $report_details_sql = "
        SELECT esrd.*, sl.location_code, sl.location_name, COALESCE(sl.is_default, 0) as location_is_default,
               CASE WHEN ps.id IS NOT NULL THEN 'set' ELSE COALESCE(p.product_type, 'normal') END AS product_type,
               COALESCE(b.name, '') AS brand_name
        FROM eod_stock_report_details esrd
        LEFT JOIN storage_locations sl ON esrd.storage_location_id = sl.id
        LEFT JOIN products p ON p.name = esrd.item_name
        LEFT JOIN product_sets ps ON p.name = ps.set_name
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE esrd.eod_report_id = ?
        ORDER BY COALESCE(sl.location_code, '') ASC, location_is_default DESC, esrd.item_name
    ";
} else {
    $reports_sql = "
        SELECT esr.*, u.name as created_by_name
        FROM eom_stock_reports esr
        LEFT JOIN users u ON esr.created_by = u.id
        ORDER BY esr.report_month DESC
        LIMIT 50
    ";
    $report_details_sql = "
        SELECT esrd.*, sl.location_code, sl.location_name, COALESCE(sl.is_default, 0) as location_is_default,
               CASE WHEN ps.id IS NOT NULL THEN 'set' ELSE COALESCE(p.product_type, 'normal') END AS product_type,
               COALESCE(b.name, '') AS brand_name
        FROM eom_stock_report_details esrd
        LEFT JOIN storage_locations sl ON esrd.storage_location_id = sl.id
        LEFT JOIN products p ON p.name = esrd.item_name
        LEFT JOIN product_sets ps ON p.name = ps.set_name
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE esrd.eom_report_id = ?
        ORDER BY COALESCE(sl.location_code, '') ASC, location_is_default DESC, esrd.item_name
    ";
}

try {
    $reports_stmt = $pdo->query($reports_sql);
    $reports = $reports_stmt->fetchAll();
} catch (PDOException $e) {
    $reports = [];
}

// Get report details if viewing a specific report
$view_report_id = $_GET['view_report'] ?? null;
$report_details = [];
if ($view_report_id) {
    try {
        $details_stmt = $pdo->prepare($report_details_sql);
        $details_stmt->execute([$view_report_id]);
        $report_details = eodEomKeepReturnsOnDefaultLocationOnly($details_stmt->fetchAll());
        if ($report_type === 'eod') {
            $reportDateForSold = '';
            foreach ($reports as $reportRow) {
                if ((int)($reportRow['id'] ?? 0) === (int)$view_report_id) {
                    $reportDateForSold = (string)($reportRow['report_date'] ?? '');
                    break;
                }
            }
            $report_details = eodEomAttachTotalSoldForEodDetails($pdo, $report_details, $reportDateForSold);
            $report_details = eodEomAttachMarketingForEodDetails($pdo, $report_details, $reportDateForSold);
            $report_details = eodEomAttachPurchaseReturnForEodDetails($pdo, $report_details, $reportDateForSold);
            $report_details = eodEomAttachOfflineMovementsForEodDetails($pdo, $report_details, $reportDateForSold);
        }
        $report_details = eodEomFilterDetailsRowsForPrintLike($report_details, $report_type);
    } catch (PDOException $e) {
        $report_details = [];
    }
}

include __DIR__ . '/../layout/header.php';
?>

<div class="container-fluid p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="bi bi-calendar-check me-2"></i>
                EOD & EOM Stock Reports
            </h2>
            <small class="text-muted">End of Day and End of Month stock snapshots</small>
        </div>
        <div class="d-flex gap-2">
            <a href="?report_type=eod" class="btn btn-outline-primary <?= $report_type === 'eod' ? 'active' : '' ?>">
                <i class="bi bi-sun me-1"></i>EOD Reports
            </a>
            <a href="?report_type=eom" class="btn btn-outline-success <?= $report_type === 'eom' ? 'active' : '' ?>">
                <i class="bi bi-calendar me-1"></i>EOM Reports
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="row">
        <!-- Report Generation Panel -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-plus-circle me-2"></i>
                        Generate <?= strtoupper($report_type) ?> Report
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>How it works:</strong> EOD reports capture your inventory levels at the end of each day. 
                        Opening stock comes from yesterday's closing stock, and closing stock is calculated as opening + received items.
                        Generate reports daily to maintain accurate inventory tracking.
                    </div>

                    <form method="post">
                        <input type="hidden" name="action" value="generate_<?= $report_type ?>_report">
                        <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">

                        <?php if ($report_type === 'eod'): ?>
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-calendar me-1"></i>Report Date
                                </label>
                                <input type="date" name="report_date" class="form-control" value="<?= htmlspecialchars($submittedReportDate) ?>" required>
                                <small class="text-muted">Select the date for this end-of-day inventory snapshot</small>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-calendar me-1"></i>Report Month
                                </label>
                                <input type="month" name="report_month" class="form-control" value="<?= htmlspecialchars($submittedReportMonth) ?>" required>
                                <small class="text-muted">Select the month for this end-of-month inventory summary</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-calendar-check me-1"></i>Report Generation Date
                                </label>
                                <input type="date" name="report_date" class="form-control" value="<?= htmlspecialchars($submittedReportDate) ?>" required>
                                <small class="text-muted">Date when this EOM report was created</small>
                            </div>
                        <?php endif; ?>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-play-circle me-2"></i>
                                Generate <?= strtoupper($report_type) ?> Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="card shadow mt-3">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">Current Stock Summary</h6>
                </div>
                <div class="card-body">
                    <?php
                    try {
                        $current_stats = $pdo->query("
                            SELECT
                                COUNT(*) as total_items,
                                SUM(quantity_on_hand) as total_quantity,
                                SUM(COALESCE(total_value, quantity_on_hand * unit_cost, 0)) as total_value
                            FROM (
                                SELECT item_name, sku, storage_location_id,
                                    SUM(quantity_on_hand) as quantity_on_hand,
                                    SUM(COALESCE(total_value, quantity_on_hand * unit_cost, 0)) as total_value
                                FROM current_inventory
                                WHERE quantity_on_hand > 0
                                GROUP BY item_name, sku, storage_location_id
                            ) agg
                        ")->fetch();
                    ?>
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="h4 text-primary mb-1"><?= number_format($current_stats['total_items'] ?? 0) ?></div>
                                <small class="text-muted">Items</small>
                            </div>
                            <div class="col-4">
                                <div class="h4 text-success mb-1"><?= number_format($current_stats['total_quantity'] ?? 0) ?></div>
                                <small class="text-muted">Quantity</small>
                            </div>
                            <div class="col-4">
                                <div class="h4 text-info mb-1">$<?= number_format($current_stats['total_value'] ?? 0, 2) ?></div>
                                <small class="text-muted">Value</small>
                            </div>
                        </div>
                    <?php } catch (Exception $e) { ?>
                        <div class="text-center text-muted">
                            <small>Unable to load current stats</small>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>

        <!-- Reports List -->
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <?= strtoupper($report_type) ?> Reports History
                    </h5>
                    <span class="badge bg-<?= $report_type === 'eod' ? 'primary' : 'success' ?>">
                        <?= count($reports) ?> Reports
                    </span>
                </div>
                <div class="card-body">
                    <?php if (empty($reports)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                            <h5 class="text-muted mt-3">No <?= strtoupper($report_type) ?> Reports Found</h5>
                            <p class="text-muted">Generate your first report to get started</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date/Month</th>
                                        <th>Items</th>
                                        <th>Quantity</th>
                                        <th>Value</th>
                                        <th>Status</th>
                                        <th>Verify Product</th>
                                        <th>Created By</th>
                                        <th>Attachment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reports as $report): ?>
                                        <tr>
                                            <td>
                                                <?php if ($report_type === 'eod'): ?>
                                                    <strong><?= date('M j, Y', strtotime($report['report_date'])) ?></strong>
                                                    <br><small class="text-muted"><?= date('l', strtotime($report['report_date'])) ?></small>
                                                <?php else: ?>
                                                    <strong><?= date('M Y', strtotime($report['report_month'] . '-01')) ?></strong>
                                                    <br><small class="text-muted">Generated: <?= date('M j, Y', strtotime($report['report_date'])) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary"><?= number_format($report['total_items']) ?></span>
                                            </td>
                                            <td class="text-end">
                                                <?= number_format($report['total_quantity'], 2) ?>
                                            </td>
                                            <td class="text-end">
                                                $<?= number_format($report['total_value'], 2) ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $report['status'] === 'finalized' ? 'success' : 'warning' ?>">
                                                    <?= ucfirst($report['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($report['status'] === 'finalized'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check-circle me-1"></i>
                                                        Verified (<?= number_format($report['total_items']) ?> items)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">
                                                        <i class="bi bi-clock me-1"></i>
                                                        Pending Verification
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($report['created_by_name'] ?? 'System') ?>
                                            </td>
                                            <td>
                                                <?php if ($report['status'] === 'finalized'): ?>
                                                    <?php
                                                    // Get attachments with preview info
                                                    $stmt = $pdo->prepare("
                                                        SELECT id, file_path, original_filename, mime_type 
                                                        FROM eod_eom_report_attachments 
                                                        WHERE report_id = ? AND report_type = ?
                                                        ORDER BY uploaded_at ASC
                                                        LIMIT 4
                                                    ");
                                                    $stmt->execute([$report['id'], $report_type]);
                                                    $attachments_preview = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                                    
                                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eod_eom_report_attachments WHERE report_id = ? AND report_type = ?");
                                                    $stmt->execute([$report['id'], $report_type]);
                                                    $attachment_count = $stmt->fetchColumn();
                                                    ?>
                                                    <?php if (!empty($attachments_preview)): ?>
                                                        <div class="d-flex align-items-center">
                                                            <div class="me-2">
                                                                <?php foreach ($attachments_preview as $att): ?>
                                                                    <?php 
                                                                    $is_image = strpos($att['mime_type'], 'image') !== false;
                                                                    $thumb_url = uploaded_file_url($att['file_path'], 'eod_eom_reports');
                                                                    $file_url = '?action=download_attachment&attachment_id=' . (int)$att['id'] . '&report_type=' . urlencode($report_type);
                                                                    ?>
                                                                    <?php if ($is_image): ?>
                                                                        <img src="<?= htmlspecialchars($thumb_url, ENT_QUOTES, 'UTF-8') ?>"
                                                                             class="img-thumbnail me-1" 
                                                                             style="width: 40px; height: 40px; object-fit: cover; cursor: pointer;"
                                                                             onclick="window.open(<?= htmlspecialchars(json_encode($thumb_url), ENT_QUOTES, 'UTF-8') ?>, '_blank')"
                                                                             title="<?= htmlspecialchars($att['original_filename']) ?>">
                                                                    <?php else: ?>
                                                                        <i class="bi bi-file-earmark-text text-secondary me-1" 
                                                                           style="font-size: 24px; cursor: pointer;"
                                                                           onclick="window.open(<?= htmlspecialchars(json_encode($file_url), ENT_QUOTES, 'UTF-8') ?>, '_blank')"
                                                                           title="<?= htmlspecialchars($att['original_filename']) ?>"></i>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <?php if ($attachment_count > 4): ?>
                                                                <small class="text-muted">+<?= $attachment_count - 4 ?> more</small>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif ($attachment_count > 0): ?>
                                                        <span class="text-muted small">
                                                            <i class="bi bi-paperclip me-1"></i><?= $attachment_count ?> file<?= $attachment_count > 1 ? 's' : '' ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">
                                                            <i class="bi bi-paperclip me-1"></i>No attachments
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="?report_type=<?= $report_type ?>&view_report=<?= $report['id'] ?>"
                                                       class="btn btn-outline-primary">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                    <?php if ($report['status'] === 'draft'): ?>
                                                        <button class="btn btn-outline-success"
                                                                onclick="openFinalizeModal(<?= $report['id'] ?>, '<?= $report_type ?>')">
                                                            <i class="bi bi-check-circle"></i> Finalize
                                                        </button>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this draft report?');">
                                                            <input type="hidden" name="action" value="delete_report">
                                                            <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">
                                                            <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
                                                            <input type="hidden" name="report_type" value="<?= htmlspecialchars($report_type) ?>">
                                                            <button type="submit" class="btn btn-outline-danger">
                                                                <i class="bi bi-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <button class="btn btn-outline-info"
                                                                onclick="openFinalizeModal(<?= $report['id'] ?>, '<?= $report_type ?>', true)">
                                                            <i class="bi bi-check-circle"></i> View Finalize
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Details Modal -->
    <?php if ($view_report_id && !empty($report_details)): ?>
        <?php
        $report_info = null;
        $print_report_label = '';
        if ($view_report_id) {
            try {
                if ($report_type === 'eod') {
                    $stmt = $pdo->prepare("
                        SELECT status, notes, finalized_at, finalized_by, report_date, created_at, u.name as finalized_by_name
                        FROM eod_stock_reports esr
                        LEFT JOIN users u ON esr.finalized_by = u.id
                        WHERE esr.id = ?
                    ");
                } else {
                    $stmt = $pdo->prepare("
                        SELECT status, notes, finalized_at, finalized_by, report_month, report_date, created_at, u.name as finalized_by_name
                        FROM eom_stock_reports esr
                        LEFT JOIN users u ON esr.finalized_by = u.id
                        WHERE esr.id = ?
                    ");
                }
                $stmt->execute([$view_report_id]);
                $report_info = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($report_info) {
                    if ($report_type === 'eod') {
                        $rawDate = (string)($report_info['report_date'] ?? '');
                        $print_report_label = $rawDate !== '' ? date('Y-m-d', strtotime($rawDate)) : '';
                    } else {
                        $print_report_label = (string)($report_info['report_month'] ?? '');
                    }
                }
            } catch (Exception $e) {
                // Ignore errors and use fallback below.
            }
        }

        if ($print_report_label === '' && !empty($reports)) {
            foreach ($reports as $r) {
                if ((int)($r['id'] ?? 0) === (int)$view_report_id) {
                    if ($report_type === 'eod') {
                        $fallbackDate = (string)($r['report_date'] ?? '');
                        $print_report_label = $fallbackDate !== '' ? date('Y-m-d', strtotime($fallbackDate)) : '';
                    } else {
                        $print_report_label = (string)($r['report_month'] ?? '');
                    }
                    break;
                }
            }
        }

        if ($print_report_label === '') {
            $print_report_label = 'N/A';
        }
        ?>
        <div class="modal fade show" id="reportDetailsModal" tabindex="-1" style="display: block;" aria-modal="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content report-details-modal-content">
                    <div class="report-generated-date d-none d-print-block text-center pt-3 pb-2" style="font-size: 1.2em; color: black;">
                        <?= $report_type === 'eod' ? 'EOD Report Date' : 'EOM Report Month' ?>: <span class="fw-bold"><?= htmlspecialchars($print_report_label) ?></span>
                    </div>
                    <div class="modal-header">
                        <h5 class="modal-title mb-0">
                            <i class="bi bi-calendar-event me-2"></i>
                            <?= $report_type === 'eod' ? 'EOD Report Date' : 'EOM Report Month' ?>: <?= htmlspecialchars($print_report_label) ?>
                        </h5>
                        <button type="button" class="btn-close" onclick="closeReportDetails()"></button>
                    </div>
                    <div class="modal-body report-details-modal-body">
                        <?php
                        $reportTotals = [
                            'opening_quantity' => 0,
                            'daily_received' => 0,
                            'purchase_return_vendor' => 0,
                            'total_sold' => 0,
                            'offline_sale' => 0,
                            'offline_purchase_back' => 0,
                            'cancelled_offline_sale' => 0,
                            'offline_cancelled_purchase_back' => 0,
                            'marketing_take_out' => 0,
                            'marketing_return' => 0,
                            'return_quantity' => 0,
                            'movements_in' => 0,
                            'movements_out' => 0,
                            'transfer_in' => 0,
                            'transfer_out' => 0,
                            'adjustments' => 0,
                            'quantity_on_hand' => 0,
                            'closing_quantity' => 0,
                            'average_quantity' => 0,
                            'closing_value' => 0,
                        ];
                        $rowNumber = 1;
                        $report_details_by_location = [];
                        foreach ($report_details as $detail) {
                            $locationCode = trim((string)($detail['location_code'] ?? ''));
                            $locationName = trim((string)($detail['location_name'] ?? ''));
                            $locationLabel = $locationCode !== '' ? $locationCode : 'Unknown';
                            if ($locationName !== '') {
                                $locationLabel .= ' - ' . $locationName;
                            }
                            if (!isset($report_details_by_location[$locationLabel])) {
                                $report_details_by_location[$locationLabel] = [
                                    'label' => $locationLabel,
                                    'items' => []
                                ];
                            }
                            $report_details_by_location[$locationLabel]['items'][] = $detail;

                            $reportTotals['opening_quantity'] += (float)($detail['opening_quantity'] ?? 0);
                            $reportTotals['daily_received'] += (float)($detail['daily_received'] ?? 0);
                            $reportTotals['purchase_return_vendor'] += (float)($detail['purchase_return_vendor'] ?? 0);
                            $reportTotals['total_sold'] += (float)($detail['total_sold'] ?? 0);
                            $reportTotals['offline_sale'] += (float)($detail['offline_sale'] ?? 0);
                            $reportTotals['offline_purchase_back'] += (float)($detail['offline_purchase_back'] ?? 0);
                            $reportTotals['cancelled_offline_sale'] += (float)($detail['cancelled_offline_sale'] ?? 0);
                            $reportTotals['offline_cancelled_purchase_back'] += (float)($detail['offline_cancelled_purchase_back'] ?? 0);
                            $reportTotals['marketing_take_out'] += (float)($detail['marketing_take_out'] ?? 0);
                            $reportTotals['marketing_return'] += (float)($detail['marketing_return'] ?? 0);
                            $reportTotals['return_quantity'] += (float)($detail['return_quantity'] ?? 0);
                            $reportTotals['movements_in'] += (float)($detail['movements_in'] ?? 0);
                            $reportTotals['movements_out'] += (float)($detail['movements_out'] ?? 0);
                            $reportTotals['transfer_in'] += (float)($detail['transfer_in'] ?? 0);
                            $reportTotals['transfer_out'] += (float)($detail['transfer_out'] ?? 0);
                            $reportTotals['adjustments'] += (float)($detail['adjustments'] ?? 0);
                            $reportTotals['quantity_on_hand'] += (float)($detail['quantity_on_hand'] ?? 0);
                            $reportTotals['closing_quantity'] += (float)($detail['closing_quantity'] ?? 0);
                            $reportTotals['average_quantity'] += (float)($detail['average_quantity'] ?? 0);
                            $reportTotals['closing_value'] += (float)($detail['closing_value'] ?? 0);
                        }
                        ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;">No.</th>
                                        <th>Item Name</th>
                                        <th>Type product</th>
                                        <th>Brand</th>
                                        <th>Location</th>
                                        <?php if ($report_type === 'eod'): ?>
                                            <th class="text-end">
                                                <i class="bi bi-box-arrow-in-down me-1"></i>Opening Stock
                                            </th>
                                            <th class="text-end">
                                                <i class="bi bi-plus-circle me-1"></i>Received
                                            </th>
                                            <th class="text-end">Return (to vendor)</th>
                                            <th class="text-end">
                                                <i class="bi bi-cart-check me-1"></i>Total Sold
                                            </th>
                                            <th class="text-end">Offline Sale</th>
                                            <th class="text-end">Offline Purchase Back</th>
                                            <th class="text-end">Cancelled Offline Sale</th>
                                            <th class="text-end">Offline Cancelled Purchase Back</th>
                                            <th class="text-end">Marketing Take Out</th>
                                            <th class="text-end">Marketing Return</th>
                                            <th class="text-end">
                                                <i class="bi bi-arrow-return-left me-1"></i>Return Qty
                                            </th>
                                            <th class="text-end">Move In</th>
                                            <th class="text-end">Move Out</th>
                                            <th class="text-end">Transfer In</th>
                                            <th class="text-end">Transfer Out</th>
                                            <th class="text-end">Adjustment</th>
                                            <th class="text-end">
                                                <i class="bi bi-box-arrow-up me-1"></i>Closing Stock
                                            </th>
                                            <th class="remark-column">Remark</th>
                                        <?php else: ?>
                                            <th class="text-end">Opening</th>
                                            <th class="text-end">Closing</th>
                                            <th class="text-end">Average</th>
                                            <th class="text-end">Movements In</th>
                                            <th class="text-end">Movements Out</th>
                                            <th class="text-end">Value</th>
                                            <th class="remark-column">Remark</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <?php foreach ($report_details_by_location as $locationGroup): ?>
                                    <tbody class="location-group" data-location="<?= htmlspecialchars($locationGroup['label']) ?>">
                                        <tr class="location-header">
                                            <td colspan="<?= $report_type === 'eod' ? 23 : 12 ?>" class="fw-bold bg-light">
                                                Location: <?= htmlspecialchars($locationGroup['label']) ?>
                                            </td>
                                        </tr>
                                        <?php foreach ($locationGroup['items'] as $detail): ?>
                                            <tr>
                                                <td class="text-center"><?= $rowNumber++ ?></td>
                                                <td><strong><?= htmlspecialchars($detail['item_name']) ?></strong></td>
                                                <td><?= htmlspecialchars(eodEomExcelProductTypeLabel($detail['product_type'] ?? 'normal')) ?></td>
                                                <td><?= htmlspecialchars($detail['brand_name'] ?? '') ?></td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?= htmlspecialchars($detail['location_code'] ?? 'Unknown') ?>
                                                    </span>
                                                </td>
                                                <?php if ($report_type === 'eod'): ?>
                                                <td class="text-end">
                                                    <span class="badge bg-info">
                                                        <?= eodEomReportQty($detail['opening_quantity'] ?? 0) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-success">
                                                        <?= eodEomReportQty($detail['daily_received'] ?? 0, '+') ?>
                                                    </span>
                                                </td>
                                                <td class="text-end text-danger"><?= eodEomReportQty($detail['purchase_return_vendor'] ?? 0, '-') ?></td>
                                                <td class="text-end">
                                                    <span class="badge bg-danger">
                                                        <?= eodEomReportQty($detail['total_sold'] ?? 0, '-') ?>
                                                    </span>
                                                </td>
                                                <td class="text-end text-danger"><?= eodEomReportQty($detail['offline_sale'] ?? 0, '-') ?></td>
                                                <td class="text-end text-success"><?= eodEomReportQty($detail['offline_purchase_back'] ?? 0, '+') ?></td>
                                                <td class="text-end text-success"><?= eodEomReportQty($detail['cancelled_offline_sale'] ?? 0, '+') ?></td>
                                                <td class="text-end text-danger"><?= eodEomReportQty($detail['offline_cancelled_purchase_back'] ?? 0, '-') ?></td>
                                                <td class="text-end text-danger"><?= eodEomReportQty($detail['marketing_take_out'] ?? 0, '-') ?></td>
                                                <td class="text-end text-success"><?= eodEomReportQty($detail['marketing_return'] ?? 0, '+') ?></td>
                                                <td class="text-end">
                                                    <span class="badge bg-warning text-dark">
                                                        <?= eodEomReportQty($detail['return_quantity'] ?? 0, '+') ?>
                                                    </span>
                                                </td>
                                                <td class="text-end text-success"><?= eodEomReportQty($detail['movements_in'] ?? 0, '+') ?></td>
                                                <td class="text-end text-danger"><?= eodEomReportQty($detail['movements_out'] ?? 0, '-') ?></td>
                                                <td class="text-end text-success"><?= eodEomReportQty($detail['transfer_in'] ?? 0, '+') ?></td>
                                                <td class="text-end text-danger"><?= eodEomReportQty($detail['transfer_out'] ?? 0, '-') ?></td>
                                                <td class="text-end"><?= eodEomReportQty($detail['adjustments'] ?? 0) ?></td>
                                                <td class="text-end">
                                                    <span class="badge bg-primary">
                                                        <?= eodEomReportQty($detail['quantity_on_hand']) ?>
                                                    </span>
                                                </td>
                                                <td class="remark-column"></td>
                                            <?php else: ?>
                                                <td class="text-end"><?= eodEomReportQty($detail['opening_quantity']) ?></td>
                                                <td class="text-end"><?= eodEomReportQty($detail['closing_quantity']) ?></td>
                                                <td class="text-end"><?= eodEomReportQty($detail['average_quantity']) ?></td>
                                                <td class="text-end text-success"><?= eodEomReportQty($detail['movements_in'], '+') ?></td>
                                                <td class="text-end text-danger"><?= eodEomReportQty($detail['movements_out'], '-') ?></td>
                                                <td class="text-end"><strong>$<?= eodEomReportQty($detail['closing_value']) ?></strong></td>
                                                <td class="remark-column"></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="location-subtotal fw-bold" style="background-color: #f0f0f0;">
                                        <td colspan="5" class="text-end">Subtotal - <?= htmlspecialchars($locationGroup['label']) ?></td>
                                        <?php if ($report_type === 'eod'): ?>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'opening_quantity'))) ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'daily_received')), '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'purchase_return_vendor')), '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'total_sold')), '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'offline_sale')), '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'offline_purchase_back')), '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'cancelled_offline_sale')), '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'offline_cancelled_purchase_back')), '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'marketing_take_out')), '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'marketing_return')), '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'return_quantity')), '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'movements_in')), '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'movements_out')), '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'transfer_in')), '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'transfer_out')), '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'adjustments'))) ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'quantity_on_hand'))) ?></td>
                                            <td class="remark-column"></td>
                                        <?php else: ?>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'opening_quantity'))) ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'closing_quantity'))) ?></td>
                                            <td class="text-end">-</td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'movements_in')), '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'movements_out')), '-') ?></td>
                                            <td class="text-end">$<?= eodEomReportQty(array_sum(array_column($locationGroup['items'], 'closing_value'))) ?></td>
                                            <td class="remark-column"></td>
                                        <?php endif; ?>
                                    </tr>
                                    </tbody>
                                <?php endforeach; ?>
                                <tfoot>
                                    <tr class="table-secondary fw-bold print-total-row">
                                        <td colspan="5" class="text-end">Total</td>
                                        <?php if ($report_type === 'eod'): ?>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['opening_quantity']) ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['daily_received'], '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['purchase_return_vendor'], '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['total_sold'], '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['offline_sale'], '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['offline_purchase_back'], '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['cancelled_offline_sale'], '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['offline_cancelled_purchase_back'], '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['marketing_take_out'], '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['marketing_return'], '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['return_quantity'], '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['movements_in'], '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['movements_out'], '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['transfer_in'], '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['transfer_out'], '-') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['adjustments']) ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['quantity_on_hand']) ?></td>
                                            <td class="remark-column"></td>
                                        <?php else: ?>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['opening_quantity']) ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['closing_quantity']) ?></td>
                                            <td class="text-end">-</td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['movements_in'], '+') ?></td>
                                            <td class="text-end"><?= eodEomReportQty($reportTotals['movements_out'], '-') ?></td>
                                            <td class="text-end">$<?= eodEomReportQty($reportTotals['closing_value']) ?></td>
                                            <td class="remark-column"></td>
                                        <?php endif; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Show Notes and Attachment for Finalized Reports -->
                        <?php if ($report_info && $report_info['status'] === 'finalized'): ?>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-primary">
                                        <i class="bi bi-sticky-note me-2"></i>Finalization Notes
                                    </h6>
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <p class="mb-2"><?= nl2br(htmlspecialchars($report_info['notes'] ?? 'No notes provided')) ?></p>
                                            <?php if (!empty($report_info['finalized_at'])): ?>
                                                <small class="text-muted">
                                                    <i class="bi bi-calendar-check me-1"></i>
                                                    Finalized on <?= date('M j, Y g:i A', strtotime($report_info['finalized_at'])) ?>
                                                    <?php if (!empty($report_info['finalized_by_name'])): ?>
                                                        by <?= htmlspecialchars($report_info['finalized_by_name']) ?>
                                                    <?php endif; ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-success">
                                        <i class="bi bi-paperclip me-2"></i>Attachments
                                    </h6>
                                    <div class="card bg-light" id="attachments">
                                        <div class="card-body">
                                            <?php
                                            // Get all attachments for this report
                                            $attachments = [];
                                            if ($report_info && $report_info['status'] === 'finalized') {
                                                $stmt = $pdo->prepare("
                                                    SELECT * FROM eod_eom_report_attachments
                                                    WHERE report_id = ? AND report_type = ?
                                                    ORDER BY uploaded_at ASC
                                                ");
                                                $stmt->execute([$view_report_id, $report_type]);
                                                $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            }

                                            if (!empty($attachments)): ?>
                                                <div class="row">
                                                    <?php foreach ($attachments as $attachment): ?>
                                                        <?php
                                                        $file_extension = strtolower(pathinfo($attachment['original_filename'], PATHINFO_EXTENSION));
                                                        $is_image = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                                        ?>
                                                        <div class="col-md-6 col-lg-4 mb-3">
                                                            <div class="card h-100">
                                                                <div class="card-body text-center">
                                                                    <?php if ($is_image): ?>
                                                                        <!-- Image thumbnail -->
                                                                        <div class="mb-2">
                                                                            <img src="?action=get_thumbnail&attachment_id=<?= $attachment['id'] ?>&report_type=<?= $report_type ?>"
                                                                                 alt="<?= htmlspecialchars($attachment['original_filename']) ?>"
                                                                                 class="img-thumbnail"
                                                                                 style="max-width: 100%; max-height: 120px; cursor: pointer;"
                                                                                 onclick="previewImage('<?= $attachment['id'] ?>', '<?= htmlspecialchars($attachment['original_filename']) ?>')"
                                                                                 title="Click to preview full size">
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <!-- Document icon -->
                                                                        <div class="mb-2">
                                                                            <i class="bi bi-file-earmark-text text-secondary fs-1"></i>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <!-- File info -->
                                                                    <h6 class="card-title text-truncate" title="<?= htmlspecialchars($attachment['original_filename']) ?>">
                                                                        <?= htmlspecialchars($attachment['original_filename']) ?>
                                                                    </h6>
                                                                    <small class="text-muted d-block">
                                                                        <?= round($attachment['file_size'] / 1024 / 1024, 2) ?> MB
                                                                        <br>
                                                                        Uploaded <?= date('M j, Y H:i', strtotime($attachment['uploaded_at'])) ?>
                                                                    </small>

                                                                    <!-- Action buttons -->
                                                                    <div class="mt-3">
                                                                        <div class="btn-group btn-group-sm w-100">
                                                                            <?php if ($is_image): ?>
                                                                                <button type="button"
                                                                                        class="btn btn-outline-info"
                                                                                        onclick="previewImage('<?= $attachment['id'] ?>', '<?= htmlspecialchars($attachment['original_filename']) ?>')"
                                                                                        title="Preview Image">
                                                                                    <i class="bi bi-eye"></i>
                                                                                </button>
                                                                            <?php endif; ?>
                                                                            <a href="?action=download_attachment&attachment_id=<?= $attachment['id'] ?>&report_type=<?= $report_type ?>"
                                                                               class="btn btn-outline-primary"
                                                                               title="Download File">
                                                                                <i class="bi bi-download"></i>
                                                                            </a>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="mt-2">
                                                    <small class="text-muted">
                                                        <i class="bi bi-info-circle me-1"></i>
                                                        Total: <?= count($attachments) ?> file<?= count($attachments) > 1 ? 's' : '' ?>
                                                    </small>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-muted">
                                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                                    No images uploaded
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer report-details-footer">
                        <div class="report-details-print-note">
                            <i class="bi bi-printer me-1"></i>Print view shows report content only
                        </div>
                        <div class="report-details-actions">
                            <button type="button" class="btn btn-light report-details-close-btn" onclick="closeReportDetails()">
                                <i class="bi bi-x-circle me-1"></i>Close
                            </button>
                            <a href="?action=export_report&amp;report_id=<?= (int)$view_report_id ?>&amp;report_type=<?= htmlspecialchars($report_type) ?>"
                               class="btn btn-outline-primary report-details-export-btn"
                               title="Download Excel (.xlsx)">
                                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel
                            </a>
                            <button type="button" class="btn btn-primary report-details-print-btn" onclick="showLocationSelectionModal()">
                                <i class="bi bi-printer me-1"></i>Print Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show" onclick="closeReportDetails()"></div>
    <?php endif; ?>
</div>

<style>
.report-details-modal-content {
    border: 0;
    overflow: hidden;
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18);
}

.report-details-modal-body {
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

#reportDetailsModal .table th:last-child,
#reportDetailsModal .table td:last-child {
    position: sticky;
    right: 0;
    z-index: 1;
    background: #ffffff;
    box-shadow: -1px 0 0 #dbe3f0;
}

#reportDetailsModal .table thead th:last-child {
    z-index: 3;
    background: #f8fafc;
}

#reportDetailsModal .location-header td:last-child,
#reportDetailsModal .location-subtotal td:last-child,
#reportDetailsModal .print-total-row td:last-child {
    background: inherit;
}

.report-details-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.25rem 1.25rem;
    border-top: 1px solid #dbe3f0;
    background: linear-gradient(180deg, #f8fafc 0%, #eef4ff 100%);
}

.report-details-print-note {
    color: #64748b;
    font-size: 0.92rem;
    font-weight: 500;
}

.report-details-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.75rem;
}

.report-details-footer .btn {
    min-width: 140px;
    padding: 0.7rem 1.1rem;
    border-radius: 999px;
    font-weight: 600;
}

.report-details-close-btn {
    border-color: #d0d7e2;
    color: #334155;
}

.report-details-close-btn:hover,
.report-details-close-btn:focus {
    background: #e2e8f0;
    border-color: #cbd5e1;
    color: #0f172a;
}

.report-details-print-btn {
    border-color: #1d4ed8;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 10px 22px rgba(37, 99, 235, 0.22);
}

.report-details-print-btn:hover,
.report-details-print-btn:focus {
    border-color: #1e40af;
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
}

.report-summary-panel {
    margin-bottom: 1.25rem;
    padding: 1.25rem 1.4rem;
    border: 1px solid #bfdbfe;
    border-radius: 20px;
    background: linear-gradient(180deg, #f8fbff 0%, #eef6ff 100%);
    box-shadow: 0 14px 30px rgba(37, 99, 235, 0.08);
}

.report-summary-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}

.report-summary-kicker {
    margin-bottom: 0.3rem;
    color: #2563eb;
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.report-summary-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.35rem;
    font-weight: 800;
}

.report-summary-description {
    margin: 0.45rem 0 0;
    color: #475569;
    font-size: 0.95rem;
    line-height: 1.55;
}

.report-summary-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 110px;
    padding: 0.45rem 0.9rem;
    border-radius: 999px;
    font-size: 0.86rem;
    font-weight: 700;
    border: 1px solid #86efac;
    background: #dcfce7;
    color: #166534;
}

.report-summary-status.is-draft {
    border-color: #fcd34d;
    background: #fef3c7;
    color: #92400e;
}

.report-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.85rem;
    margin-top: 1rem;
}

.report-summary-item {
    padding: 0.85rem 0.95rem;
    border: 1px solid #dbeafe;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.92);
}

.report-summary-label {
    display: block;
    margin-bottom: 0.25rem;
    color: #64748b;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.report-summary-value {
    display: block;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 700;
    line-height: 1.35;
}

.report-summary-guide {
    margin-top: 1rem;
    padding-top: 0.9rem;
    border-top: 1px dashed #93c5fd;
}

.report-summary-guide-title {
    display: block;
    margin-bottom: 0.5rem;
    color: #1d4ed8;
    font-size: 0.84rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.report-summary-guide-text {
    margin: 0;
    color: #334155;
    font-size: 0.92rem;
    line-height: 1.6;
}

.report-summary-print-scope {
    margin-top: 0.85rem;
    color: #475569;
    font-size: 0.9rem;
    font-weight: 600;
}

.report-column-title {
    display: block;
    font-weight: 700;
    line-height: 1.2;
}

.report-column-hint {
    display: block;
    margin-top: 0.18rem;
    color: #64748b;
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.25;
}

.location-select-card {
    width: 100%;
    min-height: 112px;
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding: 1rem 1.1rem;
    border: 1px solid #dbe3f0;
    border-radius: 18px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    color: #0f172a;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
    text-align: left;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}

.location-select-card:hover,
.location-select-card:focus {
    transform: translateY(-2px);
    border-color: #93c5fd;
    box-shadow: 0 18px 36px rgba(37, 99, 235, 0.14);
    outline: none;
}

.location-select-card.is-selected {
    border-color: #2563eb;
    background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
    box-shadow: 0 20px 40px rgba(37, 99, 235, 0.18);
}

.location-select-card__icon {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 46px;
    background: #e0f2fe;
    color: #0369a1;
    font-size: 1.25rem;
}

.location-select-card.is-selected .location-select-card__icon {
    background: #2563eb;
    color: #ffffff;
}

.location-select-card__content {
    flex: 1 1 auto;
    min-width: 0;
}

.location-select-card__title {
    display: block;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
    word-break: break-word;
}

.location-select-card__meta {
    display: block;
    margin-top: 0.2rem;
    font-size: 0.9rem;
    color: #64748b;
}

.location-select-card__check {
    flex: 0 0 auto;
    font-size: 1.2rem;
    color: #cbd5e1;
}

.location-select-card.is-selected .location-select-card__check {
    color: #1d4ed8;
}

.print-option-toggle {
    border: 1px solid #cbd5e1;
    border-radius: 0.65rem;
    overflow: hidden;
}

.print-option-toggle .btn {
    border: 0;
    border-radius: 0;
    background: #fff;
    color: #334155;
    font-weight: 600;
    padding: 0.8rem 1rem;
}

.print-option-toggle .btn + .btn {
    border-left: 1px solid #cbd5e1;
}

.print-option-toggle .btn-check:checked + .btn {
    background: #0d6efd;
    color: #fff;
}

.print-option-toggle .btn:hover {
    background: #eef4ff;
    color: #1d4ed8;
}

.print-option-toggle .btn-check:checked + .btn:hover {
    background: #0b5ed7;
    color: #fff;
}

/* Hide Remark column in view mode, show only in print */
.remark-column {
    display: none;
}

@media print {
    @page {
        margin: 7mm;
    }

    .remark-column {
        display: table-cell !important;
        min-width: 72px !important;
        border: 1px solid #000 !important;
        empty-cells: show !important;
    }

    td.remark-column:empty::after {
        content: "\00a0";
    }
    
    .report-generated-date {
        display: block !important;
        font-size: 15px;
        color: #111827 !important;
        margin: 0 0 7px !important;
        padding: 0 0 6px !important;
        border-bottom: 1px solid #9ca3af;
    }
    
    .report-generated-date .fw-bold {
        color: #1e293b !important;
    }
}

@media (max-width: 767.98px) {
    .report-details-footer {
        align-items: stretch;
    }

    .report-details-actions {
        width: 100%;
    }

    .report-details-footer .btn {
        flex: 1 1 100%;
        min-width: 0;
    }
}

@media print {
    /* Hide everything by default */
    body * {
        visibility: hidden;
    }

    /* Only show the report details modal content when printing */
    #reportDetailsModal, #reportDetailsModal * {
        visibility: visible;
    }

    /* Make the modal take the full page width */
    #reportDetailsModal {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        max-width: none !important;
        box-shadow: none !important;
    }

    #reportDetailsModal .modal-header {
        display: none !important;
    }

    #reportDetailsModal .modal-dialog {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
    }

    #reportDetailsModal .modal-content {
        border: 0 !important;
        box-shadow: none !important;
    }

    #reportDetailsModal .modal-body {
        padding: 0 !important;
        background: #fff !important;
    }

    #reportDetailsModal .table-responsive {
        overflow: visible !important;
    }

    #reportDetailsModal .btn,
    #reportDetailsModal .btn-group,
    #reportDetailsModal .modal-footer,
    #reportDetailsModal .btn-close {
        display: none !important;
    }

    /* Simplify the print table for readability */
    #reportDetailsModal .table {
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;
        font-size: 9.6px !important;
        line-height: 1.2 !important;
    }

    #reportDetailsModal thead {
        display: table-header-group !important;
        background: #f2f2f2 !important;
    }

    #reportDetailsModal th,
    #reportDetailsModal td {
        border: 1px solid #000 !important;
        padding: 3.5px 4px !important;
        vertical-align: top !important;
        font-size: 9.6px !important;
        overflow-wrap: anywhere !important;
        word-break: normal !important;
    }

    #reportDetailsModal tbody.location-group {
        break-inside: auto !important;
        page-break-inside: auto !important;
    }

    #reportDetailsModal tr.location-header.location-page-break {
        break-before: page !important;
        page-break-before: always !important;
    }

    #reportDetailsModal tr.location-header {
        background: #e0e0e0 !important;
        font-weight: bold !important;
        page-break-after: avoid !important;
    }

    #reportDetailsModal tr.location-subtotal {
        background-color: #e8e8e8 !important;
        font-weight: bold !important;
    }

    #reportDetailsModal tr.location-subtotal td {
        border-top: 2px solid #000 !important;
        border-bottom: 2px solid #000 !important;
    }

    #reportDetailsModal .text-success,
    #reportDetailsModal .text-danger,
    #reportDetailsModal .badge {
        font-size: 9.6px !important;
    }

    #reportDetailsModal th {
        background: #f2f2f2 !important;
        color: #000 !important;
        font-weight: 700;
        text-align: center !important;
    }

    #reportDetailsModal th:nth-child(1),
    #reportDetailsModal td:nth-child(1) {
        width: 3.6% !important;
        text-align: center !important;
    }

    #reportDetailsModal th:nth-child(2),
    #reportDetailsModal td:nth-child(2) {
        width: 13% !important;
    }

    #reportDetailsModal th:nth-child(3),
    #reportDetailsModal td:nth-child(3) {
        width: 6.2% !important;
    }

    #reportDetailsModal th:nth-child(4),
    #reportDetailsModal td:nth-child(4) {
        width: 7% !important;
    }

    #reportDetailsModal th:nth-child(5),
    #reportDetailsModal td:nth-child(5) {
        width: 5.8% !important;
    }

    #reportDetailsModal th:nth-child(n+6),
    #reportDetailsModal td:nth-child(n+6) {
        width: 5.1% !important;
    }

    #reportDetailsModal .remark-column {
        width: 5.4% !important;
    }

    #reportDetailsModal .badge {
        display: inline !important;
        background: transparent !important;
        color: #000 !important;
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
    }

    #reportDetailsModal .bi {
        display: none !important;
    }

    #reportDetailsModal .card,
    #reportDetailsModal .card-body,
    #reportDetailsModal .row,
    #reportDetailsModal .col-md-6 {
        background: transparent !important;
        box-shadow: none !important;
    }

    #reportDetailsModal #attachments {
        display: none !important;
    }

    #reportDetailsModal.specific-print-mode .report-generated-date {
        font-size: 22px !important;
        padding-bottom: 10px !important;
        margin-bottom: 10px !important;
    }

    #reportDetailsModal.specific-print-mode .table {
        font-size: 13px !important;
        line-height: 1.28 !important;
    }

    #reportDetailsModal.specific-print-mode th,
    #reportDetailsModal.specific-print-mode td,
    #reportDetailsModal.specific-print-mode .text-success,
    #reportDetailsModal.specific-print-mode .text-danger,
    #reportDetailsModal.specific-print-mode .badge {
        font-size: 13px !important;
    }

    #reportDetailsModal.specific-print-mode th,
    #reportDetailsModal.specific-print-mode td {
        padding: 5px 6px !important;
    }

    /* Hide the dark backdrop in print */
    .modal-backdrop {
        display: none !important;
    }
}
</style>

<!-- Finalize Report Modal -->
<div class="modal fade" id="finalizeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle me-2"></i>Finalize Report
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="finalizeForm" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="finalize_report">
                    <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">
                    <input type="hidden" name="report_id" id="finalizeReportId">
                    <input type="hidden" name="report_type" id="finalizeReportType">

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Finalizing this report will lock it permanently.</strong> Please add any supporting documentation before proceeding.
                    </div>

                    <!-- Notes Section -->
                    <div class="mb-4">
                        <label for="finalizeNotes" class="form-label">
                            <i class="bi bi-sticky-note me-2"></i>
                            <strong>Notes</strong> <span class="text-danger">*</span>
                        </label>
                        <textarea
                            class="form-control"
                            id="finalizeNotes"
                            name="notes"
                            rows="4"
                            placeholder="Enter any additional notes, observations, or explanations for this report..."
                            required
                        ></textarea>
                        <div class="form-text">
                            These notes will be permanently saved with the finalized report.
                        </div>
                    </div>

                    <!-- Telegram Notification Section -->
                    <div class="mb-4">
                        <div class="form-check">
                            <input 
                                class="form-check-input" 
                                type="checkbox" 
                                id="sendTelegramNotification" 
                                name="send_telegram_notification" 
                                value="1"
                                checked
                            >
                            <label class="form-check-label" for="sendTelegramNotification">
                                <i class="bi bi-telegram me-2"></i>
                                <strong>Send Telegram Notification</strong>
                            </label>
                        </div>
                        <div class="form-text">
                            Sends an Excel file to Telegram with zero-only movement columns hidden.
                        </div>
                        <div class="mt-2 d-flex flex-column flex-lg-row align-items-lg-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="testTelegramButton">
                                <i class="bi bi-send-check me-1"></i>
                                Send Test to Telegram
                            </button>
                            <div id="telegramTestStatus" class="form-text mb-0"></div>
                        </div>
                    </div>

                    <!-- Image Section -->
                    <div class="mb-3">
                        <label for="finalizeAttachments" class="form-label">
                            <i class="bi bi-images me-2"></i>
                            <strong>Images</strong> <span class="text-danger">*</span>
                        </label>
                        <div class="border border-2 border-dashed border-primary rounded p-4 text-center" id="dropZone">
                            <i class="bi bi-cloud-upload text-primary fs-1 mb-3"></i>
                            <p class="mb-2">Drag & drop files here or click to browse</p>
                            <small class="text-muted">Supported formats: PDF, Word, Excel, Images (JPG, PNG, WebP)</small>
                            <br>
                            <small class="text-muted">Maximum 10MB per file</small>
                        </div>
                        <input
                            type="file"
                            class="d-none"
                            id="finalizeAttachments"
                            name="attachments[]"
                            multiple
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp"
                            required
                        >
                        <div id="fileList" class="mt-3"></div>
                    </div>

                    <!-- Report Summary -->
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="bi bi-file-earmark-text me-2"></i>Report Summary
                            </h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Report Type:</strong> <span id="summaryReportType"></span></p>
                                    <p class="mb-1"><strong>Report Date:</strong> <span id="summaryReportDate"></span></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Total Items:</strong> <span id="summaryTotalItems"></span></p>
                                    <p class="mb-1"><strong>Total Value:</strong> <span id="summaryTotalValue"></span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-2"></i>Finalize Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Location Selection Modal for Print -->
<div class="modal fade" id="locationSelectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-funnel me-2"></i>Select Locations and Brands to Print
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Choose filters:</strong> Select one or more locations and brands to include in the printed report.
                </div>

                <div class="mb-4">
                    <h6 class="mb-2">Print Option</h6>
                    <div class="btn-group w-100 print-option-toggle" role="group" aria-label="Print option">
                        <input type="radio" class="btn-check" name="print_option" id="printOptionDetail" value="detail" autocomplete="off" checked>
                        <label class="btn" for="printOptionDetail">
                            <i class="bi bi-table me-1"></i>Detail
                        </label>

                        <input type="radio" class="btn-check" name="print_option" id="printOptionSpecific" value="specific" autocomplete="off">
                        <label class="btn" for="printOptionSpecific">
                            <i class="bi bi-funnel me-1"></i>Specific
                        </label>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="mb-1">Available Locations</h6>
                            <small class="text-muted" id="locationSelectionSummary">Loading locations...</small>
                        </div>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary me-2" id="selectAllLocations">
                                <i class="bi bi-check-all me-1"></i>Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllLocations">
                                <i class="bi bi-x me-1"></i>Clear All
                            </button>
                        </div>
                    </div>

                    <div id="locationCards" class="row g-3">
                        <!-- Location cards will be populated by JavaScript -->
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="mb-1">Available Brands</h6>
                            <small class="text-muted" id="brandSelectionSummary">Loading brands...</small>
                        </div>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary me-2" id="selectAllBrands">
                                <i class="bi bi-check-all me-1"></i>Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllBrands">
                                <i class="bi bi-x me-1"></i>Clear All
                            </button>
                        </div>
                    </div>

                    <div id="brandCards" class="row g-3">
                        <!-- Brand cards will be populated by JavaScript -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmPrintLocations">
                    <i class="bi bi-printer me-2"></i>Print Selected Locations
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Preview image function
function previewImage(attachmentId, filename) {
    // Get image URL
    const imageUrl = `?action=download_attachment&attachment_id=${attachmentId}&report_type=<?= $report_type ?>`;

    // Set modal content
    document.getElementById('previewModalTitle').textContent = filename;
    document.getElementById('previewImage').src = imageUrl;
    document.getElementById('previewImage').alt = filename;

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
    modal.show();

    // Clear image source when modal is hidden to prevent caching issues
    document.getElementById('imagePreviewModal').addEventListener('hidden.bs.modal', function() {
        document.getElementById('previewImage').src = '';
    });
}
// Open finalize modal
function openFinalizeModal(reportId, reportType, viewMode = false) {
    // Set form values
    document.getElementById('finalizeReportId').value = reportId;
    document.getElementById('finalizeReportType').value = reportType;

    // Clear previous data
    document.getElementById('finalizeNotes').value = '';
    document.getElementById('fileList').innerHTML = '';
    document.getElementById('finalizeAttachments').value = '';

    // Handle view mode for finalized reports
    if (viewMode) {
        // Change modal title and hide form elements
        document.querySelector('#finalizeModal .modal-title').innerHTML = '<i class="bi bi-check-circle me-2"></i>View Finalized Report';
        
        // Hide form controls and show read-only view
        document.getElementById('finalizeForm').style.display = 'none';
        
        // Create read-only view
        const readonlyView = document.createElement('div');
        readonlyView.id = 'readonlyFinalizeView';
        readonlyView.innerHTML = `
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <strong>This report has been finalized.</strong> Below are the details that were verified and saved.
            </div>
            <div id="readonlyContent">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="sr-only">Loading...</span>
                    </div>
                    <p class="mt-2">Loading finalized report details...</p>
                </div>
            </div>
        `;
        document.querySelector('#finalizeModal .modal-body').appendChild(readonlyView);
        
        // Load finalized report data
        loadFinalizedReportDetails(reportId, reportType);
    } else {
        // Normal finalize mode
        document.querySelector('#finalizeModal .modal-title').innerHTML = '<i class="bi bi-check-circle me-2"></i>Finalize Report';
        document.getElementById('finalizeForm').style.display = 'block';
        
        // Remove readonly view if exists
        const readonlyView = document.getElementById('readonlyFinalizeView');
        if (readonlyView) {
            readonlyView.remove();
        }
    }

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('finalizeModal'));
    modal.show();
}

// Load finalized report details for read-only view
function loadFinalizedReportDetails(reportId, reportType) {
    // Make AJAX request to get finalized report data
    fetch(`?action=get_finalized_details&report_id=${reportId}&report_type=${reportType}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayFinalizedReportDetails(data);
            } else {
                document.getElementById('readonlyContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error loading finalized report details: ${data.error || 'Unknown error'}
                    </div>
                `;
            }
        })
        .catch(error => {
            document.getElementById('readonlyContent').innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Failed to load report details. Please try again.
                </div>
            `;
        });
}

// Display finalized report details in read-only format
function formatProductTypeLabel(productType) {
    const normalized = String(productType || 'normal').toLowerCase();
    if (normalized === 'set') return 'Set';
    if (normalized === 'normal' || normalized === 'general') return 'Item';
    return productType || 'Item';
}

function displayFinalizedReportDetails(data) {
    const content = document.getElementById('readonlyContent');
    const isEodReport = data.report && data.report.report_type === 'eod';
    
    let html = `
        <!-- Notes Section -->
        <div class="mb-4">
            <label class="form-label">
                <i class="bi bi-sticky-note me-2"></i>
                <strong>Finalization Notes</strong>
            </label>
            <div class="card bg-light">
                <div class="card-body">
                    <p class="mb-2">${data.report.notes ? data.report.notes.replace(/\n/g, '<br>') : 'No notes provided'}</p>
                    <small class="text-muted">
                        <i class="bi bi-calendar-check me-1"></i>
                        Finalized on ${new Date(data.report.finalized_at).toLocaleString()}
                        ${data.report.finalized_by_name ? ` by ${data.report.finalized_by_name}` : ''}
                    </small>
                </div>
            </div>
        </div>

        <!-- Verified Products Section -->
        <div class="mb-4">
            <label class="form-label">
                <i class="bi bi-checklist me-2"></i>
                <strong>Verified Product Stock (${data.products.length} products)</strong>
            </label>
            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-sm table-striped">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th>Product Name</th>
                            <th>Type product</th>
                            <th>Brand</th>
                            <th>SKU</th>
                            <th>Location</th>
                            ${isEodReport ? '<th class="text-end">Total Sold</th>' : ''}
                            <th class="text-end">Verified Amount</th>
                        </tr>
                    </thead>
                    <tbody>`;
    
    data.products.forEach(product => {
        html += `
                        <tr>
                            <td><strong>${product.item_name}</strong></td>
                            <td>${formatProductTypeLabel(product.product_type)}</td>
                            <td>${product.brand_name || ''}</td>
                            <td><code>${product.sku || 'N/A'}</code></td>
                            <td>
                                <span class="badge bg-secondary">${product.location_name || 'Unknown'}</span>
                            </td>
                            ${isEodReport ? `<td class="text-end"><span class="badge bg-danger">${parseFloat(product.total_sold || 0).toFixed(2)}</span></td>` : ''}
                            <td class="text-end">
                                <span class="badge bg-success">${parseFloat(product.verified_amount || 0).toFixed(2)}</span>
                            </td>
                        </tr>`;
    });
    
    html += `
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Attachments Section -->
        <div class="mb-3">
            <label class="form-label">
                <i class="bi bi-paperclip me-2"></i>
                <strong>Attachments (${data.attachments.length} files)</strong>
            </label>
            <div class="row g-3">`;
    
    if (data.attachments.length > 0) {
        data.attachments.forEach(attachment => {
            const isImage = attachment.mime_type && attachment.mime_type.startsWith('image/');
            const fileUrl = `?action=download_attachment&attachment_id=${attachment.id}&report_type=${data.report.report_type}`;
            
            html += `
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            ${isImage ? 
                                `<div class="mb-2">
                                    <img src="${fileUrl}" class="img-fluid rounded shadow-sm" style="max-height: 100px; cursor: pointer;" 
                                         onclick="window.open('${fileUrl}', '_blank')" title="Click to view full size">
                                </div>` :
                                `<div class="mb-2">
                                    <i class="bi bi-file-earmark-text text-secondary fs-1"></i>
                                </div>`
                            }
                            <h6 class="card-title text-truncate" title="${attachment.original_filename}">
                                ${attachment.original_filename}
                            </h6>
                            <small class="text-muted d-block">
                                ${(attachment.file_size / 1024 / 1024).toFixed(2)} MB<br>
                                Uploaded ${new Date(attachment.uploaded_at).toLocaleString()}
                            </small>
                            <div class="mt-2">
                                <a href="${fileUrl}" class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="bi bi-download"></i> View
                                </a>
                            </div>
                        </div>
                    </div>
                </div>`;
        });
    } else {
        html += `
                <div class="col-12">
                    <div class="text-center text-muted">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        No attachments were uploaded during finalization
                    </div>
                </div>`;
    }
    
    html += `
            </div>
        </div>
    `;
    
    content.innerHTML = html;
}

// Handle form submission with multiple file upload
document.getElementById('finalizeForm').addEventListener('submit', function(e) {
    const notes = document.getElementById('finalizeNotes').value.trim();
    const attachments = document.getElementById('finalizeAttachments').files;

    if (!notes) {
        e.preventDefault();
        alert('Please enter notes before finalizing the report.');
        return;
    }

    if (!attachments || attachments.length === 0) {
        e.preventDefault();
        alert('Please upload at least one attachment before finalizing the report.');
        return;
    }

    // Check file sizes and types
    const maxSize = 10 * 1024 * 1024; // 10MB
    const allowedTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'];

    for (let file of attachments) {
        if (file.size > maxSize) {
            e.preventDefault();
            alert(`File "${file.name}" is too large. Maximum size is 10MB per file.`);
            return;
        }

        const extension = file.name.toLowerCase().split('.').pop();
        if (!allowedTypes.includes(extension)) {
            e.preventDefault();
            alert(`File "${file.name}" has invalid type. Allowed types: PDF, Word, Excel, Images`);
            return;
        }
    }

    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Finalizing...';
    submitBtn.disabled = true;
});

// Telegram test button handler
const telegramTestButton = document.getElementById('testTelegramButton');
const telegramTestStatus = document.getElementById('telegramTestStatus');

if (telegramTestButton && telegramTestStatus) {
    telegramTestButton.addEventListener('click', async () => {
        const originalLabel = telegramTestButton.innerHTML;
        telegramTestButton.disabled = true;
        telegramTestButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
        telegramTestStatus.classList.remove('text-success', 'text-danger');
        telegramTestStatus.textContent = 'Sending test message...';

        try {
            const formTokenInput = document.querySelector('#finalizeForm input[name="form_token"]');
            const formToken = formTokenInput ? formTokenInput.value : '';

            const response = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({
                    action: 'send_telegram_test',
                    form_token: formToken,
                }),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.success) {
                telegramTestStatus.classList.add('text-success');
                telegramTestStatus.textContent = `Sent successfully to ${result.sent_count} target(s).`;
            } else {
                telegramTestStatus.classList.add('text-danger');
                const errorDetail = (result.targets || []).map(t => `Chat ${t.chat_id}${t.thread_id ? ` (topic ${t.thread_id})` : ''}`).join(', ');
                telegramTestStatus.textContent = errorDetail ? `Failed to send: ${errorDetail}` : 'Failed to send test message.';
            }
        } catch (error) {
            telegramTestStatus.classList.add('text-danger');
            telegramTestStatus.textContent = `Error: ${error.message}`;
        } finally {
            telegramTestButton.disabled = false;
            telegramTestButton.innerHTML = originalLabel;
        }
    });
}

// Drag and drop functionality
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('finalizeAttachments');
const fileList = document.getElementById('fileList');

if (dropZone && fileInput && fileList) {
    // Click to open file dialog
    dropZone.addEventListener('click', () => {
        fileInput.click();
    });

    // Drag over
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('bg-light');
    });

    // Drag leave
    dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dropZone.classList.remove('bg-light');
    });

    // Drop files
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('bg-light');

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            // Create a new DataTransfer object to simulate file input
            const dt = new DataTransfer();
            for (let file of files) {
                dt.items.add(file);
            }
            fileInput.files = dt.files;
            updateFileList();
        }
    });

    // File input change
    fileInput.addEventListener('change', updateFileList);

    function updateFileList() {
        const files = fileInput.files;
        fileList.innerHTML = '';

        if (files.length > 0) {
            // Create responsive grid layout for image previews
            const gridContainer = document.createElement('div');
            gridContainer.className = 'row g-3';

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileExtension = file.name.toLowerCase().split('.').pop();
                const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension);

                // Create card for each file
                const colDiv = document.createElement('div');
                colDiv.className = 'col-md-6 col-lg-4';

                const cardDiv = document.createElement('div');
                cardDiv.className = 'card h-100 position-relative';

                const cardBody = document.createElement('div');
                cardBody.className = 'card-body text-center p-3';

                if (isImage) {
                    // Image preview
                    const imgContainer = document.createElement('div');
                    imgContainer.className = 'mb-2';

                    const img = document.createElement('img');
                    img.className = 'img-fluid rounded shadow-sm';
                    img.style.cssText = 'max-width: 100%; max-height: 120px; object-fit: cover; cursor: pointer;';
                    img.title = 'Click to preview';

                    // Create object URL for image preview
                    const objectUrl = URL.createObjectURL(file);
                    img.src = objectUrl;

                    // Add click handler for image preview
                    img.onclick = function() {
                        showImagePreview(objectUrl, file.name);
                    };

                    imgContainer.appendChild(img);
                    cardBody.appendChild(imgContainer);
                } else {
                    // Document icon
                    const iconDiv = document.createElement('div');
                    iconDiv.className = 'mb-2';
                    iconDiv.innerHTML = '<i class="bi bi-file-earmark-text text-secondary fs-1"></i>';
                    cardBody.appendChild(iconDiv);
                }

                // File name
                const fileName = document.createElement('h6');
                fileName.className = 'card-title text-truncate mb-1';
                fileName.title = file.name;
                fileName.textContent = file.name;
                cardBody.appendChild(fileName);

                // File size
                const fileSize = document.createElement('small');
                fileSize.className = 'text-muted d-block mb-2';
                const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                fileSize.textContent = `${sizeMB} MB`;
                cardBody.appendChild(fileSize);

                // Remove button
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-sm btn-outline-danger position-absolute';
                removeBtn.style.cssText = 'top: 5px; right: 5px;';
                removeBtn.innerHTML = '<i class="bi bi-x"></i>';
                removeBtn.onclick = function() { removeFile(i); };

                cardDiv.appendChild(cardBody);
                cardDiv.appendChild(removeBtn);
                colDiv.appendChild(cardDiv);
                gridContainer.appendChild(colDiv);
            }

            fileList.appendChild(gridContainer);
        }
    }

    // Image preview function for upload modal
    function showImagePreview(imageSrc, imageName) {
        // Create or update image preview modal
        let previewModal = document.getElementById('uploadImagePreviewModal');
        if (!previewModal) {
            previewModal = document.createElement('div');
            previewModal.className = 'modal fade';
            previewModal.id = 'uploadImagePreviewModal';
            previewModal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-image me-2"></i><span id="uploadPreviewTitle">Image Preview</span>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-center">
                            <img id="uploadPreviewImage" src="" alt="Preview" class="img-fluid rounded shadow" style="max-height: 70vh;">
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(previewModal);
        }

        document.getElementById('uploadPreviewTitle').textContent = imageName;
        document.getElementById('uploadPreviewImage').src = imageSrc;

        const modal = new bootstrap.Modal(previewModal);
        modal.show();
    }

    // Remove file function
    window.removeFile = function(index) {
        const dt = new DataTransfer();
        const files = fileInput.files;

        for (let i = 0; i < files.length; i++) {
            if (i !== index) {
                dt.items.add(files[i]);
            }
        }

        fileInput.files = dt.files;
        updateFileList();
    };
}

// Finalize report function (updated for new modal)
function finalizeReport(reportId, reportType) {
    if (confirm('Are you sure you want to finalize this report? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="finalize_report">
            <input type="hidden" name="report_id" value="${reportId}">
            <input type="hidden" name="report_type" value="${reportType}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close report details modal
function closeReportDetails() {
    window.location.href = window.location.pathname + '?report_type=<?= $report_type ?>';
}

function formatPrintTotalValue(value, label) {
    const number = Math.abs(value) < 0.005 ? 0 : value;
    if (number === 0) {
        return '0.00';
    }

    if (/return \(to vendor\)/.test(label)) {
        return '-' + Math.abs(number).toFixed(2);
    }

    if (/received|marketing return|return|move in|transfer in|movements in/.test(label)) {
        return '+' + Math.abs(number).toFixed(2);
    }

    if (/total sold|marketing take out|move out|transfer out|movements out/.test(label)) {
        return '-' + Math.abs(number).toFixed(2);
    }

    return number.toFixed(2);
}

function parseReportNumber(text) {
    const value = parseFloat(String(text || '').trim().replace(/[^0-9+\-.]/g, ''));
    return isNaN(value) ? 0 : value;
}

function setPrintColumnDisplay(modal, columnIndex, displayValue) {
    const headerCell = modal.querySelector(`#reportDetailsModal table thead th:nth-child(${columnIndex + 1})`);
    if (headerCell) {
        headerCell.style.display = displayValue;
    }

    modal.querySelectorAll('#reportDetailsModal table tbody.location-group tr').forEach((row) => {
        const cells = row.querySelectorAll('td');
        if (row.classList.contains('location-header')) {
            return;
        }

        if (row.classList.contains('location-subtotal')) {
            const subtotalCell = cells[columnIndex - 4];
            if (subtotalCell) {
                subtotalCell.style.display = displayValue;
            }
            return;
        }

        const cell = cells[columnIndex];
        if (cell) {
            cell.style.display = displayValue;
        }
    });

    const footerCells = modal.querySelectorAll('#reportDetailsModal .print-total-row td');
    const footerCell = footerCells[columnIndex - 4];
    if (footerCell) {
        footerCell.style.display = displayValue;
    }
}

function updatePrintHeaderColspan(modal) {
    const visibleHeaderCount = Array.from(modal.querySelectorAll('#reportDetailsModal table thead th'))
        .filter((cell) => cell.style.display !== 'none').length;

    modal.querySelectorAll('#reportDetailsModal tr.location-header td').forEach((cell) => {
        cell.colSpan = visibleHeaderCount;
    });
}

function resetPrintColumnVisibility(modal) {
    if (!modal) return;
    const cells = modal.querySelectorAll('#reportDetailsModal table th, #reportDetailsModal table td');
    cells.forEach((cell) => {
        cell.style.display = '';
    });
    modal.classList.remove('specific-print-mode');

    const headerCount = modal.querySelectorAll('#reportDetailsModal table thead th').length;
    modal.querySelectorAll('#reportDetailsModal tr.location-header td').forEach((cell) => {
        cell.colSpan = headerCount;
    });
}

function hideZeroOnlyPrintColumns(modal, headerCells, locationBodies) {
    if (!modal || !headerCells.length) return;

    const hideIndexes = [];
    headerCells.forEach((header, index) => {
        const label = header.textContent.toLowerCase();
        if (index < 5 || /opening|closing|remark/.test(label)) {
            return;
        }

        const values = [];
        locationBodies.forEach((tbody) => {
            if (tbody.style.display === 'none') {
                return;
            }

            tbody.querySelectorAll('tr').forEach((row) => {
                if (
                    row.style.display === 'none' ||
                    row.classList.contains('location-header') ||
                    row.classList.contains('location-subtotal')
                ) {
                    return;
                }

                const cell = row.querySelectorAll('td')[index];
                if (cell) {
                    values.push(parseReportNumber(cell.textContent));
                }
            });
        });

        if (values.length > 0 && values.every((value) => Math.abs(value) < 0.005)) {
            hideIndexes.push(index);
        }
    });

    hideIndexes.forEach((index) => setPrintColumnDisplay(modal, index, 'none'));

    updatePrintHeaderColspan(modal);
}

function hideZeroOnlyViewColumns() {
    const modal = document.querySelector('#reportDetailsModal');
    if (!modal) return;

    const headerCells = Array.from(modal.querySelectorAll('#reportDetailsModal table thead th'));
    const locationBodies = modal.querySelectorAll('#reportDetailsModal table tbody.location-group');
    hideZeroOnlyPrintColumns(modal, headerCells, locationBodies);
}

// Hide rows only when stock and every movement quantity are zero.
function filterPrintRowsByOpeningAndClosingStock() {
    const modal = document.querySelector('#reportDetailsModal');
    if (!modal) return;

    resetPrintColumnVisibility(modal);

    const headerCells = Array.from(modal.querySelectorAll('#reportDetailsModal table thead th'));
    const openingIndex = headerCells.findIndex((th) => /opening/i.test(th.textContent));
    const closingIndex = headerCells.findIndex((th) => /closing/i.test(th.textContent));
    const locationBodies = modal.querySelectorAll('#reportDetailsModal table tbody.location-group');
    if (openingIndex === -1 || closingIndex === -1) {
        updateLocationPageBreaks(locationBodies);
        return;
    }

    const columnTotals = [];
    const dataStartIndex = 5; // first 5 cols are No, Item Name, Type product, Brand, Location

    locationBodies.forEach((tbody) => {
        if (tbody.style.display === 'none') {
            return;
        }

        const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => !row.classList.contains('location-header'));
        const locationTotals = [];
        let locationVisible = false;
        let visibleIndex = 1;

        rows.forEach((row) => {
            if (row.classList.contains('print-total-row') || row.classList.contains('location-subtotal')) return;
            if (row.dataset.brandFiltered === 'hidden') return;

            const cells = row.querySelectorAll('td');
            const openingCell = cells[openingIndex];
            const closingCell = cells[closingIndex];
            if (!openingCell || !closingCell) return;

            const openingValue = parseFloat(openingCell.textContent.trim().replace(/[^0-9+\-.]/g, ''));
            const closingValue = parseFloat(closingCell.textContent.trim().replace(/[^0-9+\-.]/g, ''));
            const hasOpening = !isNaN(openingValue) && openingValue !== 0;
            const hasClosing = !isNaN(closingValue) && closingValue !== 0;
            const hasMovement = Array.from(cells).some((cell, index) => {
                if (index <= openingIndex || index >= closingIndex) return false;
                const value = parseFloat(cell.textContent.trim().replace(/[^0-9+\-.]/g, ''));
                return !isNaN(value) && Math.abs(value) >= 0.005;
            });
            const shouldShow = hasOpening || hasClosing || hasMovement;

            if (!shouldShow) {
                row.style.display = 'none';
                return;
            }

            locationVisible = true;
            row.style.display = '';
            const numberCell = row.querySelector('td:first-child');
            if (numberCell) {
                numberCell.textContent = visibleIndex++;
            }

            cells.forEach((cell, index) => {
                const cellValue = parseFloat(cell.textContent.trim().replace(/[^0-9+\-.]/g, ''));
                if (!isNaN(cellValue)) {
                    columnTotals[index] = (columnTotals[index] || 0) + cellValue;
                    locationTotals[index] = (locationTotals[index] || 0) + cellValue;
                }
            });
        });

        tbody.style.display = locationVisible ? '' : 'none';
        const subtotalRow = tbody.querySelector('tr.location-subtotal');
        if (subtotalRow) {
            subtotalRow.style.display = locationVisible ? '' : 'none';
            subtotalRow.querySelectorAll('td').forEach((cell, subtotalIndex) => {
                if (subtotalIndex === 0) return;

                const headerIndex = dataStartIndex + subtotalIndex - 1;
                const label = headerCells[headerIndex]?.textContent.toLowerCase() ?? '';
                const totalValue = locationTotals[headerIndex];
                if (totalValue === undefined) return;

                cell.textContent = formatPrintTotalValue(totalValue, label);
            });
        }
    });

    updateLocationPageBreaks(locationBodies);

    const footerRow = modal.querySelector('.print-total-row');
    if (footerRow) {
        const footerCells = footerRow.querySelectorAll('td');
        footerCells.forEach((cell, footerIndex) => {
            if (footerIndex === 0) return; // first cell is the label colspan cell

            const headerIndex = dataStartIndex + footerIndex - 1;
            const label = headerCells[headerIndex]?.textContent.toLowerCase() ?? '';
            const totalValue = columnTotals[headerIndex];
            if (totalValue === undefined) return;

            cell.textContent = formatPrintTotalValue(totalValue, label);
        });
    }

    const selectedPrintOption = document.querySelector('input[name="print_option"]:checked')?.value || 'detail';
    modal.classList.toggle('specific-print-mode', selectedPrintOption === 'specific');
    if (selectedPrintOption === 'specific') {
        hideZeroOnlyPrintColumns(modal, headerCells, locationBodies);
    }
    updatePrintHeaderColspan(modal);
}

function updateLocationPageBreaks(locationBodies) {
    let visibleLocationIndex = 0;

    locationBodies.forEach((tbody) => {
        const locationHeader = tbody.querySelector('tr.location-header');
        if (!locationHeader) {
            return;
        }

        locationHeader.classList.remove('location-page-break');

        if (tbody.style.display === 'none') {
            return;
        }

        if (visibleLocationIndex > 0) {
            locationHeader.classList.add('location-page-break');
        }

        visibleLocationIndex += 1;
    });
}

// Print the report and then close/clear the details view
function printAndCloseReportDetails() {
    filterPrintRowsByOpeningAndClosingStock();

    let alreadyClosed = false;
    const finishPrint = function() {
        if (alreadyClosed) {
            return;
        }
        alreadyClosed = true;
        window.removeEventListener('afterprint', finishPrint);
        closeReportDetails();
    };

    window.addEventListener('afterprint', finishPrint);
    window.print();
    setTimeout(finishPrint, 0);
}

// Show location selection modal for print filtering
function showLocationSelectionModal() {
    const modal = new bootstrap.Modal(document.getElementById('locationSelectionModal'));
    populateLocationCards();
    populateBrandCards();
    modal.show();
}

const selectedPrintLocations = new Set();
const selectedPrintBrands = new Set();

function escapeHtml(value) {
    const text = document.createElement('div');
    text.textContent = value;
    return text.innerHTML;
}

function getReportLocationData() {
    const locationBodies = document.querySelectorAll('#reportDetailsModal table tbody.location-group');
    const locations = [];

    locationBodies.forEach((tbody) => {
        const locationKey = (tbody.dataset.location || '').trim();
        if (locationKey === '') {
            return;
        }

        locations.push({
            key: locationKey,
            label: locationKey,
            itemCount: tbody.querySelectorAll('tr:not(.location-header):not(.location-subtotal)').length
        });
    });

    return locations;
}

function getReportBrandData() {
    const rows = document.querySelectorAll('#reportDetailsModal table tbody.location-group tr:not(.location-header):not(.location-subtotal)');
    const brandMap = new Map();

    rows.forEach((row) => {
        const brand = (row.children[3]?.textContent || '').trim() || 'No Brand';
        const existing = brandMap.get(brand) || { key: brand, label: brand, itemCount: 0 };
        existing.itemCount += 1;
        brandMap.set(brand, existing);
    });

    return Array.from(brandMap.values()).sort((a, b) => a.label.localeCompare(b.label));
}

function syncSelectedPrintSet(selectedSet, items, forceSelectAll = false) {
    const validKeys = new Set(items.map((item) => item.key));

    Array.from(selectedSet).forEach((key) => {
        if (!validKeys.has(key)) {
            selectedSet.delete(key);
        }
    });

    if (forceSelectAll || selectedSet.size === 0) {
        selectedSet.clear();
        items.forEach((item) => selectedSet.add(item.key));
    }
}

function syncSelectedPrintLocations(locations, forceSelectAll = false) {
    syncSelectedPrintSet(selectedPrintLocations, locations, forceSelectAll);
}

function syncSelectedPrintBrands(brands, forceSelectAll = false) {
    syncSelectedPrintSet(selectedPrintBrands, brands, forceSelectAll);
}

function updatePrintConfirmButton() {
    const confirmBtn = document.getElementById('confirmPrintLocations');
    if (!confirmBtn) {
        return;
    }

    const locations = getReportLocationData();
    const brands = getReportBrandData();
    const allLocationsSelected = locations.length > 0 && selectedPrintLocations.size === locations.length;
    const allBrandsSelected = brands.length > 0 && selectedPrintBrands.size === brands.length;
    const hasRequiredSelection = selectedPrintLocations.size > 0 && selectedPrintBrands.size > 0;

    confirmBtn.disabled = !hasRequiredSelection;
    if (!hasRequiredSelection) {
        confirmBtn.innerHTML = '<i class="bi bi-printer me-2"></i>Print Selected';
    } else if (allLocationsSelected && allBrandsSelected) {
        confirmBtn.innerHTML = '<i class="bi bi-printer me-2"></i>Print All';
    } else {
        confirmBtn.innerHTML = '<i class="bi bi-printer me-2"></i>Print Selected';
    }
}

function updateLocationSelectionSummary(locations) {
    const summaryEl = document.getElementById('locationSelectionSummary');
    if (!summaryEl) {
        return;
    }

    const totalCount = locations.length;
    const selectedCount = selectedPrintLocations.size;

    if (totalCount === 0) {
        summaryEl.textContent = 'No locations available in this report.';
        updatePrintConfirmButton();
        return;
    }

    if (selectedCount === 0) {
        summaryEl.textContent = 'No location selected yet.';
        updatePrintConfirmButton();
        return;
    }

    if (selectedCount === totalCount) {
        summaryEl.textContent = `All ${totalCount} locations selected for print.`;
        updatePrintConfirmButton();
        return;
    }

    summaryEl.textContent = `${selectedCount} of ${totalCount} locations selected for print.`;
    updatePrintConfirmButton();
}

function updateBrandSelectionSummary(brands) {
    const summaryEl = document.getElementById('brandSelectionSummary');
    if (!summaryEl) {
        return;
    }

    const totalCount = brands.length;
    const selectedCount = selectedPrintBrands.size;

    if (totalCount === 0) {
        summaryEl.textContent = 'No brands available in this report.';
        updatePrintConfirmButton();
        return;
    }

    if (selectedCount === 0) {
        summaryEl.textContent = 'No brand selected yet.';
        updatePrintConfirmButton();
        return;
    }

    if (selectedCount === totalCount) {
        summaryEl.textContent = `All ${totalCount} brands selected for print.`;
        updatePrintConfirmButton();
        return;
    }

    summaryEl.textContent = `${selectedCount} of ${totalCount} brands selected for print.`;
    updatePrintConfirmButton();
}

function renderLocationCardStates() {
    const cards = document.querySelectorAll('#locationCards .location-select-card');
    cards.forEach((card) => {
        const isSelected = selectedPrintLocations.has(card.dataset.locationKey);
        card.classList.toggle('is-selected', isSelected);
        card.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    });
}

function renderBrandCardStates() {
    const cards = document.querySelectorAll('#brandCards .location-select-card');
    cards.forEach((card) => {
        const isSelected = selectedPrintBrands.has(card.dataset.brandKey);
        card.classList.toggle('is-selected', isSelected);
        card.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    });
}

function populateLocationCards(forceSelectAll = false) {
    const locationCards = document.getElementById('locationCards');
    if (!locationCards) {
        return;
    }

    const locations = getReportLocationData();
    locationCards.innerHTML = '';

    if (locations.length === 0) {
        locationCards.innerHTML = '<div class="col-12"><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>No locations found in this report.</div></div>';
        updateLocationSelectionSummary([]);
        return;
    }

    syncSelectedPrintLocations(locations, forceSelectAll);

    locations.forEach((location) => {
        const colDiv = document.createElement('div');
        colDiv.className = 'col-md-6 col-xl-4';

        const cardBtn = document.createElement('button');
        cardBtn.type = 'button';
        cardBtn.className = 'location-select-card';
        cardBtn.dataset.locationKey = location.key;
        cardBtn.innerHTML = `
            <span class="location-select-card__icon">
                <i class="bi bi-geo-alt"></i>
            </span>
            <span class="location-select-card__content">
                <span class="location-select-card__title">${escapeHtml(location.label)}</span>
                <span class="location-select-card__meta">${location.itemCount} item${location.itemCount === 1 ? '' : 's'} in this report</span>
            </span>
            <span class="location-select-card__check">
                <i class="bi bi-check-circle-fill"></i>
            </span>
        `;

        cardBtn.addEventListener('click', function() {
            const locationKey = this.dataset.locationKey;
            if (selectedPrintLocations.has(locationKey)) {
                selectedPrintLocations.delete(locationKey);
            } else {
                selectedPrintLocations.add(locationKey);
            }

            renderLocationCardStates();
            updateLocationSelectionSummary(locations);
        });

        colDiv.appendChild(cardBtn);
        locationCards.appendChild(colDiv);
    });

    renderLocationCardStates();
    updateLocationSelectionSummary(locations);
}

function populateBrandCards(forceSelectAll = false) {
    const brandCards = document.getElementById('brandCards');
    if (!brandCards) {
        return;
    }

    const brands = getReportBrandData();
    brandCards.innerHTML = '';

    if (brands.length === 0) {
        brandCards.innerHTML = '<div class="col-12"><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>No brands found in this report.</div></div>';
        updateBrandSelectionSummary([]);
        return;
    }

    syncSelectedPrintBrands(brands, forceSelectAll);

    brands.forEach((brand) => {
        const colDiv = document.createElement('div');
        colDiv.className = 'col-md-6 col-xl-4';

        const cardBtn = document.createElement('button');
        cardBtn.type = 'button';
        cardBtn.className = 'location-select-card';
        cardBtn.dataset.brandKey = brand.key;
        cardBtn.innerHTML = `
            <span class="location-select-card__icon">
                <i class="bi bi-tags"></i>
            </span>
            <span class="location-select-card__content">
                <span class="location-select-card__title">${escapeHtml(brand.label)}</span>
                <span class="location-select-card__meta">${brand.itemCount} item${brand.itemCount === 1 ? '' : 's'} in this report</span>
            </span>
            <span class="location-select-card__check">
                <i class="bi bi-check-circle-fill"></i>
            </span>
        `;

        cardBtn.addEventListener('click', function() {
            const brandKey = this.dataset.brandKey;
            if (selectedPrintBrands.has(brandKey)) {
                selectedPrintBrands.delete(brandKey);
            } else {
                selectedPrintBrands.add(brandKey);
            }

            renderBrandCardStates();
            updateBrandSelectionSummary(brands);
        });

        colDiv.appendChild(cardBtn);
        brandCards.appendChild(colDiv);
    });

    renderBrandCardStates();
    updateBrandSelectionSummary(brands);
}

document.addEventListener('DOMContentLoaded', function() {
    hideZeroOnlyViewColumns();

    // Handle select all locations
    document.getElementById('selectAllLocations')?.addEventListener('click', function() {
        populateLocationCards(true);
    });

    document.getElementById('selectAllBrands')?.addEventListener('click', function() {
        populateBrandCards(true);
    });

    // Handle clear all locations
    document.getElementById('clearAllLocations')?.addEventListener('click', function() {
        selectedPrintLocations.clear();
        renderLocationCardStates();
        updateLocationSelectionSummary(getReportLocationData());
    });

    document.getElementById('clearAllBrands')?.addEventListener('click', function() {
        selectedPrintBrands.clear();
        renderBrandCardStates();
        updateBrandSelectionSummary(getReportBrandData());
    });

    // Handle confirm print locations
    document.getElementById('confirmPrintLocations')?.addEventListener('click', function() {
        const selectedLocations = Array.from(selectedPrintLocations);
        const selectedBrands = Array.from(selectedPrintBrands);
        
        if (selectedLocations.length === 0 || selectedBrands.length === 0) {
            alert('Please select at least one location and one brand to print.');
            return;
        }
        
        // Hide modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('locationSelectionModal'));
        modal.hide();
        
        // Filter and print
        filterAndPrintReport(selectedLocations, selectedBrands);
    });
});

// Filter report by selected locations and brands and print
function filterAndPrintReport(selectedLocations, selectedBrands) {
    const locationBodies = document.querySelectorAll('#reportDetailsModal table tbody.location-group');
    const allRows = document.querySelectorAll('#reportDetailsModal table tbody.location-group tr:not(.location-header):not(.location-subtotal)');
    
    locationBodies.forEach((tbody) => {
        const locationKey = (tbody.dataset.location || '').trim();
        if (locationKey !== '') {
            tbody.style.display = selectedLocations.includes(locationKey) ? '' : 'none';
        }
    });

    allRows.forEach((row) => {
        const brandKey = (row.children[3]?.textContent || '').trim() || 'No Brand';
        const brandSelected = selectedBrands.includes(brandKey);
        row.style.display = brandSelected ? '' : 'none';
        if (brandSelected) {
            delete row.dataset.brandFiltered;
        } else {
            row.dataset.brandFiltered = 'hidden';
        }
    });
    
    // Apply standard print filtering
    filterPrintRowsByOpeningAndClosingStock();
    
    // Print
    let alreadyClosed = false;
    const finishPrint = function() {
        if (alreadyClosed) {
            return;
        }
            alreadyClosed = true;
            window.removeEventListener('afterprint', finishPrint);
            // Reset display after printing
            locationBodies.forEach(tbody => tbody.style.display = '');
            allRows.forEach((row) => {
                row.style.display = '';
                delete row.dataset.brandFiltered;
            });
            resetPrintColumnVisibility(document.querySelector('#reportDetailsModal'));
            closeReportDetails();
        };

    window.addEventListener('afterprint', finishPrint);
    window.print();
    setTimeout(finishPrint, 0);
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>

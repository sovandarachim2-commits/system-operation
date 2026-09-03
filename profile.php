<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/upload_paths.php';
require_login();
$user = current_user();

$success = '';
$error = '';

$uploadDirFs = __DIR__ . '/uploads/profile_images';
$uploadDirWeb = 'uploads/profile_images';

/** Max upload file size (bytes). */
const PROFILE_IMAGE_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
/** Reject images larger than this edge (pixels); avoids huge uploads / memory issues. */
const PROFILE_IMAGE_MAX_DIMENSION = 8192;
/** Target max size after server compression (100 KB). */
const PROFILE_IMAGE_TARGET_BYTES = 100 * 1024;
/** Longest side (px) before quality compression; keeps detail while aiming for target size. */
const PROFILE_IMAGE_COMPRESS_MAX_EDGE = 1200;

/**
 * Resize and re-encode to WebP (preferred) or JPEG, aiming for <= PROFILE_IMAGE_TARGET_BYTES.
 *
 * @return string|null Saved filename (e.g. user_1_abcd.webp) or null if GD cannot process
 */
function profile_image_compress_and_save(string $sourceTmp, string $destDir, string $baseName): ?string
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $bic = defined('IMG_BICUBIC_FIXED') ? IMG_BICUBIC_FIXED : IMG_BICUBIC;
    $data = @file_get_contents($sourceTmp);
    if ($data === false || $data === '') {
        return null;
    }

    $src = @imagecreatefromstring($data);
    if ($src === false) {
        return null;
    }

    if (function_exists('imagepalettetotruecolor')) {
        @imagepalettetotruecolor($src);
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return null;
    }

    $maxEdge = (int) PROFILE_IMAGE_COMPRESS_MAX_EDGE;
    if (max($w, $h) > $maxEdge) {
        $scale = $maxEdge / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $scaled = imagescale($src, $nw, $nh, $bic);
        imagedestroy($src);
        if ($scaled === false) {
            return null;
        }
        $src = $scaled;
        $w = imagesx($src);
        $h = imagesy($src);
    }

    $flat = imagecreatetruecolor($w, $h);
    if ($flat === false) {
        imagedestroy($src);
        return null;
    }
    $white = imagecolorallocate($flat, 255, 255, 255);
    imagefill($flat, 0, 0, $white);
    imagealphablending($flat, true);
    imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
    imagedestroy($src);

    $master = $flat;
    $masterW = $w;
    $masterH = $h;

    $target = (int) PROFILE_IMAGE_TARGET_BYTES;
    $pathWebp = $destDir . DIRECTORY_SEPARATOR . $baseName . '.webp';
    $pathJpg = $destDir . DIRECTORY_SEPARATOR . $baseName . '.jpg';

    $encodeUnder = static function ($image, string $path, bool $asWebp) use ($target): bool {
        for ($q = 94; $q >= 46; $q -= 2) {
            if ($asWebp) {
                if (!imagewebp($image, $path, $q)) {
                    return false;
                }
            } elseif (!imagejpeg($image, $path, $q)) {
                return false;
            }
            clearstatcache(true, $path);
            $sz = @filesize($path);
            if ($sz !== false && $sz > 0 && $sz <= $target) {
                return true;
            }
        }

        return false;
    };

    $factors = [1.0, 0.88, 0.76, 0.65, 0.54, 0.44];

    foreach ($factors as $f) {
        if ($f >= 1.0 - 1e-9) {
            $work = $master;
            $freeWork = false;
        } else {
            $nw = max(40, (int) round($masterW * $f));
            $nh = max(40, (int) round($masterH * $f));
            $work = imagescale($master, $nw, $nh, $bic);
            if ($work === false) {
                continue;
            }
            $freeWork = true;
        }

        $webpOk = function_exists('imagewebp');
        if ($webpOk && $encodeUnder($work, $pathWebp, true)) {
            @unlink($pathJpg);
            if ($freeWork) {
                imagedestroy($work);
            }
            imagedestroy($master);

            return $baseName . '.webp';
        }
        @unlink($pathWebp);

        if ($encodeUnder($work, $pathJpg, false)) {
            @unlink($pathWebp);
            if ($freeWork) {
                imagedestroy($work);
            }
            imagedestroy($master);

            return $baseName . '.jpg';
        }
        @unlink($pathJpg);

        if ($freeWork) {
            imagedestroy($work);
        }
    }

    $nw = max(40, (int) round($masterW * 0.38));
    $nh = max(40, (int) round($masterH * 0.38));
    $work = imagescale($master, $nw, $nh, $bic);
    imagedestroy($master);
    if ($work === false) {
        return null;
    }

    if (function_exists('imagewebp') && imagewebp($work, $pathWebp, 80)) {
        imagedestroy($work);
        @unlink($pathJpg);

        return $baseName . '.webp';
    }
    if (imagejpeg($work, $pathJpg, 85)) {
        imagedestroy($work);
        @unlink($pathWebp);

        return $baseName . '.jpg';
    }
    imagedestroy($work);

    return null;
}

function ensure_users_profile_image_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
        if ($check && !$check->fetch()) {
            $pdo->exec('ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // Column may already exist; save may fail later if schema mismatch
    }
}

function delete_stored_profile_image(?string $relativePath): void
{
    upload_delete_local_file($relativePath, 'profile_images');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($name === '') {
        $error = 'Name is required.';
    } else {
        $removePhoto = !empty($_POST['remove_profile_image']);
        $finalImage = (string)($user['profile_image'] ?? '');
        $photoError = '';

        $hasFile = isset($_FILES['profile_image']) && (int)($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasFile) {
            $file = $_FILES['profile_image'];
            if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                $photoError = 'Could not upload image. Please try again.';
            } elseif ($file['size'] > PROFILE_IMAGE_MAX_BYTES) {
                $mb = (int)ceil(PROFILE_IMAGE_MAX_BYTES / (1024 * 1024));
                $photoError = "Image file must be {$mb} MB or smaller (your file is too large).";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']) ?: '';
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                ];
                if (!isset($allowed[$mime])) {
                    $photoError = 'Please upload a JPG, PNG, GIF, or WebP image.';
                } else {
                    $dims = @getimagesize($file['tmp_name']);
                    if (is_array($dims) && isset($dims[0], $dims[1])) {
                        $w = (int)$dims[0];
                        $h = (int)$dims[1];
                        if ($w > PROFILE_IMAGE_MAX_DIMENSION || $h > PROFILE_IMAGE_MAX_DIMENSION) {
                            $photoError = 'Image is too large: max ' . PROFILE_IMAGE_MAX_DIMENSION . '×' . PROFILE_IMAGE_MAX_DIMENSION . ' pixels.';
                        }
                    }
                    }
                }
                if ($photoError === '') {
                    try {
                        $uploadTarget = upload_dated_dir('profile_images');
                        $uploadDirFs = $uploadTarget['absolute'];
                        $uploadDirWeb = $uploadTarget['relative'];
                    } catch (Throwable $e) {
                        $photoError = 'Failed to create profile image folder.';
                    }
                    if ($photoError === '') {
                    $baseName = 'user_' . (int)$user['id'] . '_' . bin2hex(random_bytes(8));
                    $savedName = profile_image_compress_and_save($file['tmp_name'], $uploadDirFs, $baseName);
                    if ($savedName !== null) {
                        delete_stored_profile_image($finalImage);
                        $compressedPath = $uploadDirFs . DIRECTORY_SEPARATOR . $savedName;
                        if (upload_storage_is_r2()) {
                            $finalImage = upload_store_file_path($compressedPath, 'profile_images', $savedName, null, mime_content_type($compressedPath) ?: $mime, false);
                            @unlink($compressedPath);
                        } else {
                            $finalImage = $uploadDirWeb . '/' . $savedName;
                        }
                    } else {
                        $ext = $allowed[$mime];
                        $filename = $baseName . '.' . $ext;
                        $storedPath = upload_store_uploaded_file($file, 'profile_images', $filename, null, $mime);
                        if ($storedPath === '') {
                            $photoError = 'Failed to save image (compression unavailable on server - check PHP GD).';
                        } else {
                            delete_stored_profile_image($finalImage);
                            $finalImage = $storedPath;
                        }
                    }
                }
            }
        } elseif ($removePhoto) {
            delete_stored_profile_image($finalImage);
            $finalImage = '';
        }

        $pdo = get_db_connection();
        ensure_users_profile_image_column($pdo);
        $hasCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'")->fetch();
        if ($hasCol) {
            $stmt = $pdo->prepare('UPDATE users SET name = ?, phone = ?, profile_image = ? WHERE id = ?');
            $stmt->execute([$name, $phone, $finalImage !== '' ? $finalImage : null, $user['id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?');
            $stmt->execute([$name, $phone, $user['id']]);
        }
        if ($photoError !== '') {
            $error = $photoError;
            $success = 'Your name and phone were saved.';
        } else {
            $success = 'Profile updated.';
        }
        $user = current_user(true);
    }
}

$avatarUrl = user_profile_image_url($user);
$profileImageMaxMb = (int) ceil(PROFILE_IMAGE_MAX_BYTES / (1024 * 1024));
$profileTargetKb = (int) round(PROFILE_IMAGE_TARGET_BYTES / 1024);

include __DIR__ . '/layout/header.php';
?>
<div class="row justify-content-center flex-grow-1">
    <div class="col-12 col-md-8 col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h1 class="h4 mb-4">My Profile</h1>
                <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="d-flex flex-column gap-3">
                    <div class="d-flex align-items-start gap-3 flex-wrap">
                        <?php if ($avatarUrl !== ''): ?>
                            <div class="flex-shrink-0 text-center" style="max-width:100%">
                                <button type="button" class="btn btn-link p-0 border-0 text-decoration-none text-body profile-photo-preview-btn" data-bs-toggle="modal" data-bs-target="#profilePhotoFullModal" title="View full size">
                                    <span class="d-block rounded-3 border bg-light p-2 mx-auto" style="max-width:min(100%,280px)">
                                        <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Profile photo preview" class="d-block mx-auto rounded-2" style="max-width:100%;max-height:220px;width:auto;height:auto;object-fit:contain">
                                    </span>
                                    <small class="text-muted d-block mt-1">Tap to see full image</small>
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white border flex-shrink-0" style="width:96px;height:96px" aria-hidden="true">
                                <i class="bi bi-person-fill fs-1"></i>
                            </div>
                        <?php endif; ?>
                        <div class="flex-grow-1" style="min-width:200px">
                            <label class="form-label" for="profile_image">Profile photo</label>
                            <input type="file" id="profile_image" name="profile_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                            <?php if ($avatarUrl !== ''): ?>
                                <div class="form-check mt-2">
                                    <input type="checkbox" class="form-check-input" name="remove_profile_image" value="1" id="remove_profile_image">
                                    <label class="form-check-label" for="remove_profile_image">Remove current photo</label>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                    </div>
                    <div>
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($user['role']) ?>" disabled>
                    </div>
                    <div>
                        <label class="form-label" for="name">Name</label>
                        <input type="text" id="name" name="name" class="form-control form-control-lg" value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>
                    <div>
                        <label class="form-label" for="phone">Phone</label>
                        <input type="text" id="phone" name="phone" class="form-control form-control-lg" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg mt-2">Save Profile</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php if ($avatarUrl !== ''): ?>
<div class="modal fade" id="profilePhotoFullModal" tabindex="-1" aria-labelledby="profilePhotoFullModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary py-2">
                <h2 class="modal-title h5 mb-0" id="profilePhotoFullModalLabel">Profile photo</h2>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-2 p-md-3 text-center">
                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Profile photo full size" class="img-fluid rounded" style="max-height:min(88vh,900px);width:auto;object-fit:contain">
            </div>
            <div class="modal-footer border-secondary py-2">
                <a href="<?= htmlspecialchars($avatarUrl) ?>" class="btn btn-outline-light btn-sm" target="_blank" rel="noopener">Open in new tab</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/layout/footer.php'; ?>

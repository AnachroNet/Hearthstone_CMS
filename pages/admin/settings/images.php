<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'], true)) {
    header("Location: {$base_path}login");
    exit;
}

$page_class = 'images';
$page_title = translate('page_title_images_settings', 'Images Settings');
$page_meta_description = translate('page_description_images_settings', 'Manage site page background images');
$page_meta_robots = 'noindex';
$page_body_class = 'min-h-screen text-[#d8d8d8] bg-[#05070b] bg-fixed';

$backgroundCatalog = sahtout_page_backgrounds_catalog();
$saveAction = $base_path . 'pages/admin/settings/save_page_background.php';
$maxUploadBytes = sahtout_page_background_max_upload_bytes();

$savedBgKey = isset($_GET['bg']) ? (string) $_GET['bg'] : '';
$savedBgLabel = '';
if ($savedBgKey !== '' && isset($backgroundCatalog[$savedBgKey])) {
    $entry = $backgroundCatalog[$savedBgKey];
    $savedBgLabel = translate($entry['label_key'], $entry['label_default']);
}

ob_start();
?>
    <style>
        body {
            background-image:
                radial-gradient(1000px 700px at -10% 35%, rgba(59,130,246,.14), transparent 65%),
                radial-gradient(800px 600px at -5% 85%, rgba(124,58,237,.10), transparent 70%),
                linear-gradient(180deg, #0a0e16 0%, #060810 45%, #03040a 100%);
        }

        * { font-family: 'Inter', sans-serif; }
        .wow-title, .section-title, .form-label { font-family: 'Cinzel', serif; }

        .panel-gold-corners { position: relative; }
        .panel-gold-corners::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(#e8c552,#e8c552) left top / 18px 2px,
                linear-gradient(#e8c552,#e8c552) left top / 2px 18px,
                linear-gradient(#e8c552,#e8c552) right top / 18px 2px,
                linear-gradient(#e8c552,#e8c552) right top / 2px 18px,
                linear-gradient(#e8c552,#e8c552) left bottom / 18px 2px,
                linear-gradient(#e8c552,#e8c552) left bottom / 2px 18px,
                linear-gradient(#e8c552,#e8c552) right bottom / 18px 2px,
                linear-gradient(#e8c552,#e8c552) right bottom / 2px 18px;
            background-repeat: no-repeat;
        }
        .panel-gold-corners::before {
            content: '';
            position: absolute;
            inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
        }

        .btn-clip {
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
        }

        .bg-upload-card .upload-area {
            border: 2px dashed rgba(201,162,39,.25);
            background: rgba(10, 14, 22, 0.55);
            transition: all 0.25s ease;
            cursor: pointer;
            padding: 0.75rem;
            text-align: center;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .bg-upload-card .upload-area:hover,
        .bg-upload-card .upload-area.dragover {
            border-color: rgba(201,162,39,.45);
            background: rgba(15, 20, 30, 0.75);
        }

        .bg-upload-card .upload-area.dragover {
            border-color: #f2cf5b;
        }

        .bg-upload-card .bg-preview {
            width: 100%;
            max-width: 220px;
            max-height: 80px;
            object-fit: cover;
            border: 1px solid rgba(201,162,39,.25);
            display: none;
        }

        .bg-upload-card .bg-preview.visible {
            display: block;
        }

        .bg-upload-card .upload-hint {
            font-size: 0.75rem;
            color: rgba(216, 216, 216, 0.75);
        }

        .bg-upload-card .card-actions button:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none !important;
        }
    </style>
<?php
$page_head = ob_get_clean();

include $project_root . 'includes/header.php';
?>

    <div class="flex relative min-h-screen">
        <?php include $project_root . 'includes/admin_sidebar.php'; ?>

        <main class="main-content-area flex-1 p-3 sm:p-4 md:p-6 lg:p-8 transition-all duration-300 lg:ml-70">
            <div class="max-w-350 mx-auto px-1 sm:px-4 md:px-6 lg:px-8 xl:px-10">
                <div class="space-y-4 md:space-y-6 lg:space-y-8">

                    <h1 class="wow-title text-2xl md:text-3xl lg:text-4xl font-black
                               bg-[linear-gradient(180deg,#fff7d6_0%,#f2cf5b_35%,#c9a227_62%,#8a6a14_100%)]
                               bg-clip-text text-transparent drop-shadow-[0_3px_6px_rgba(0,0,0,.85)]">
                        <?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?>
                    </h1>

                    <?php include $project_root . 'pages/admin/settings/settings_navbar.php'; ?>

                    <?php if (isset($_GET['status']) && $_GET['status'] === 'success'): ?>
                        <div class="bg-wow-success/15 border border-wow-success/40 text-wow-success
                                    p-4 rounded-sm flex items-center gap-3">
                            <i class="fas fa-check-circle text-xl"></i>
                            <span>
                                <?php
                                if ($savedBgLabel !== '') {
                                    echo htmlspecialchars(
                                        sprintf(
                                            translate('msg_background_saved_named', '%s background saved successfully.'),
                                            $savedBgLabel
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                } else {
                                    echo translate('msg_background_saved', 'Background saved successfully.');
                                }
                                ?>
                            </span>
                        </div>
                    <?php elseif (isset($_GET['status']) && $_GET['status'] === 'error'): ?>
                        <div class="bg-wow-error/15 border border-wow-error/40 text-wow-error
                                    p-4 rounded-sm flex items-center gap-3">
                            <i class="fas fa-exclamation-circle text-xl"></i>
                            <span><?php echo htmlspecialchars(urldecode((string) ($_GET['message'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="relative bg-linear-to-b from-[#161920]/92 to-[#080a0e]/90
                                border border-[#c9a227]/22
                                shadow-[0_12px_32px_rgba(0,0,0,.55),inset_0_0_60px_rgba(0,0,0,.45)]
                                p-4 md:p-6 lg:p-8 panel-gold-corners">

                        <h2 class="section-title text-lg md:text-xl mb-2 md:mb-3 flex items-center gap-3
                                   text-[#f2cf5b] font-bold drop-shadow-[0_0_12px_rgba(201,162,39,.35),0_2px_4px_rgba(0,0,0,.8)]">
                            <i class="fas fa-panorama text-[#f2cf5b]"></i>
                            <?php echo translate('settings_page_backgrounds', 'Page Backgrounds'); ?>
                        </h2>
                        <p class="text-sm text-gray-400 mb-6 md:mb-8 max-w-3xl">
                            <?php echo translate('help_page_backgrounds', 'Upload a new image for each page. JPG or PNG, up to 5 MB. Changes apply after you save.'); ?>
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8">
                            <?php foreach ($backgroundCatalog as $bgKey => $entry):
                                $label = translate($entry['label_key'], $entry['label_default']);
                                $filePath = sahtout_page_background_file_path($bgKey);
                                $hasFile = is_string($filePath) && is_file($filePath);
                                $previewUrl = sahtout_page_background_url($bgKey);
                                ?>
                                <div class="bg-upload-card flex flex-col items-stretch gap-3" data-bg-key="<?php echo htmlspecialchars($bgKey, ENT_QUOTES, 'UTF-8'); ?>">
                                    <h3 class="text-center text-sm font-semibold text-[#f2cf5b] tracking-wide">
                                        <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                    </h3>

                                    <form method="POST"
                                          action="<?php echo htmlspecialchars($saveAction, ENT_QUOTES, 'UTF-8'); ?>"
                                          enctype="multipart/form-data"
                                          class="flex flex-col gap-3 flex-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="background_key" value="<?php echo htmlspecialchars($bgKey, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int) $maxUploadBytes; ?>">

                                        <div class="upload-area" role="button" tabindex="0" aria-label="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="file"
                                                   name="background_image"
                                                   class="sr-only bg-file-input"
                                                   accept="image/jpeg,image/png"
                                                   aria-hidden="true">
                                            <img class="bg-preview<?php echo $hasFile ? ' visible' : ''; ?>"
                                                 src="<?php echo $hasFile ? htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') : ''; ?>"
                                                 alt=""
                                                 data-original-src="<?php echo $hasFile ? htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') : ''; ?>">
                                            <span class="upload-hint bg-upload-placeholder<?php echo $hasFile ? ' hidden' : ''; ?>">
                                                <?php echo $hasFile
                                                    ? translate('bg_upload_replace_hint', 'Click to replace')
                                                    : translate('bg_no_image_yet', 'No image file yet') . ' — ' . translate('bg_upload_hint', 'Click or drop an image'); ?>
                                            </span>
                                        </div>

                                        <div class="card-actions flex flex-wrap justify-center gap-2 mt-auto">
                                            <button type="submit"
                                                    class="btn-clip inline-flex items-center gap-2 px-4 py-2.5 font-extrabold text-xs uppercase tracking-wider
                                                           bg-gradient-to-b from-[#f6d478] via-[#c9a227] to-[#8a6a14]
                                                           text-[#1a1200] shadow-[inset_0_0_0_1px_rgba(255,255,255,.28),inset_0_-8px_14px_rgba(0,0,0,.25)]
                                                           hover:scale-105 transition-transform duration-200 bg-save-btn"
                                                    disabled>
                                                <i class="fas fa-save"></i>
                                                <?php echo translate('btn_save_background', 'Save'); ?>
                                            </button>
                                            <button type="button"
                                                    class="btn-clip inline-flex items-center gap-2 px-4 py-2.5 font-extrabold text-xs uppercase tracking-wider
                                                           border border-[rgba(201,162,39,0.35)] text-[#f2cf5b] bg-black/30
                                                           hover:bg-[rgba(201,162,39,0.12)] transition-colors duration-200 bg-cancel-btn"
                                                    disabled>
                                                <i class="fas fa-undo"></i>
                                                <?php echo translate('btn_cancel_background', 'Cancel'); ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.bg-upload-card').forEach(function (card) {
            const uploadArea = card.querySelector('.upload-area');
            const fileInput = card.querySelector('.bg-file-input');
            const preview = card.querySelector('.bg-preview');
            const placeholder = card.querySelector('.bg-upload-placeholder');
            const saveBtn = card.querySelector('.bg-save-btn');
            const cancelBtn = card.querySelector('.bg-cancel-btn');
            const originalSrc = preview ? (preview.getAttribute('data-original-src') || '') : '';
            let pendingObjectUrl = null;

            function clearPendingUrl() {
                if (pendingObjectUrl) {
                    URL.revokeObjectURL(pendingObjectUrl);
                    pendingObjectUrl = null;
                }
            }

            function setDirty(dirty) {
                if (saveBtn) saveBtn.disabled = !dirty;
                if (cancelBtn) cancelBtn.disabled = !dirty;
            }

            function resetPreview() {
                clearPendingUrl();
                if (fileInput) fileInput.value = '';
                if (preview) {
                    if (originalSrc) {
                        preview.src = originalSrc;
                        preview.classList.add('visible');
                    } else {
                        preview.removeAttribute('src');
                        preview.classList.remove('visible');
                    }
                }
                if (placeholder) {
                    placeholder.classList.toggle('hidden', !!originalSrc);
                }
                setDirty(false);
            }

            function showFile(file) {
                if (!file || !preview) return;
                clearPendingUrl();
                pendingObjectUrl = URL.createObjectURL(file);
                preview.src = pendingObjectUrl;
                preview.classList.add('visible');
                if (placeholder) placeholder.classList.add('hidden');
                setDirty(true);
            }

            if (uploadArea && fileInput) {
                uploadArea.addEventListener('click', function () {
                    fileInput.click();
                });
                uploadArea.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        fileInput.click();
                    }
                });
                uploadArea.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    uploadArea.classList.add('dragover');
                });
                uploadArea.addEventListener('dragleave', function () {
                    uploadArea.classList.remove('dragover');
                });
                uploadArea.addEventListener('drop', function (e) {
                    e.preventDefault();
                    uploadArea.classList.remove('dragover');
                    const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                    if (file) {
                        fileInput.files = e.dataTransfer.files;
                        showFile(file);
                    }
                });
                fileInput.addEventListener('change', function () {
                    const file = fileInput.files && fileInput.files[0];
                    if (file) showFile(file);
                });
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', resetPreview);
            }
        });
    });
    </script>
</body>
</html>

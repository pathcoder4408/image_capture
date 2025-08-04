<?php
/**
 * Encounter form for capturing multiple images via webcam.
 * (EDIT CAPABILITY: Shows, removes, and saves existing images)
 */

require_once("../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$row = array();

if (!$encounter) {
    die("Internal error: we do not seem to be in an encounter!");
}

if (!empty($_POST['csrf_token_form']) && !CsrfUtils::verifyCsrfToken($_POST['csrf_token_form']) === false) {
    die("Invalid CSRF token");
}

// Gracefully handle form exit submission
if (($_POST['delete'] ?? null) == 'delete' || ($_POST['back'] ?? null) == 'back') {
    formHeader("Redirecting....");
    formJump();
    formFooter();
    exit;
}

// ImageMagick Checks...
$extensionLoaded = extension_loaded('imagick');
$isMagickInstalled = false;
$isMagickExtensionInstalled = false;
$magickVersion = $magickExtensionVersion = "";
exec("magick -version", $output, $execReturnCode);
if ($execReturnCode === 0) {
    $magickVersion .= "System-level ImageMagick is installed.\n";
    $magickVersion .= "Version details:\n" . implode("\n", $output) . "\n";
    $isMagickInstalled = true;
} else {
    $magickVersion .= "System-level ImageMagick is not installed or not accessible.\n";
}
if ($extensionLoaded) {
    $imagick = new Imagick();
    $version = $imagick->getVersion();
    $magickExtensionVersion .= "PHP Imagick extension is installed.\n";
    $magickExtensionVersion .= "Version: " . $version['versionString'] . "\n";
    $isMagickExtensionInstalled = true;
} else {
    $magickVersion .= "PHP Imagick extension is not installed or not enabled.\n";
}

$formid = $_GET['id'] ?? '0';
$imagedir = $GLOBALS['OE_SITE_DIR'] . "/documents/" . check_file_dir_name($pid) . "/encounters";
$capturedImages = [];

// Load previously saved images initially
$savedImages = [];
if ($formid && is_dir($imagedir)) {
    $pattern = $imagedir . "/" . check_file_dir_name($encounter) . "_" . check_file_dir_name($formid) . "_*.jpg";
    foreach (glob($pattern) as $filename) {
        $basename = basename($filename);
        $savedImages[] = $basename;
    }
    sort($savedImages);
}

// Handle webcam image capture posted data
if (!empty($_POST['capturedImages'])) {
    $capturedImages = json_decode($_POST['capturedImages'], true);
}

// Handle form save submission
if ($_POST['bn_save'] ?? null) {
    if ($formid) {
        $query = "UPDATE form_image_capture SET notes = ? WHERE id = ?";
        sqlStatement($query, array($_POST['form_notes'], $formid));
    } else {
        $query = "INSERT INTO form_image_capture (notes) VALUES (?)";
        $formid = sqlInsert($query, array($_POST['form_notes']));
        addForm($encounter, "Image Capture", $formid, "image_capture", $pid, $userauthorized);
    }

    // Save new webcam images
    if (!empty($_POST['selectedImages'])) {
        $selectedImages = $_POST['selectedImages'];

        if (!is_dir($imagedir)) {
            if (!mkdir($imagedir, 0755, true)) {
                die(xlt('Failed to create directory for images.'));
            }
        }

        // Determine next available index for new images
        $existingIndices = [];
        if ($formid) {
            $pattern = $imagedir . "/" . check_file_dir_name($encounter) . "_" . check_file_dir_name($formid) . "_*.jpg";
            foreach (glob($pattern) as $filename) {
                if (preg_match('/_(\d+)\.jpg$/', $filename, $m)) {
                    $existingIndices[] = (int)$m[1];
                }
            }
        }
        $nextIndex = $existingIndices ? (max($existingIndices) + 1) : 0;

        foreach ($selectedImages as $index => $imageData) {
            if (strpos($imageData, 'data:image/') === 0) {
                if (preg_match('/^data:image\/(png|jpg|jpeg);base64,/', $imageData, $type)) {
                    $imageData = substr($imageData, strpos($imageData, ',') + 1);
                    $imageData = base64_decode($imageData);
                    if ($imageData === false) {
                        echo xlt('Base64 decode failed');
                        continue;
                    }
                    $imagePath = $imagedir . "/" . check_file_dir_name($encounter) . "_" . check_file_dir_name($formid) . "_" . $nextIndex . ".jpg";
                    if (!file_put_contents($imagePath, $imageData)) {
                        echo xlt('Failed to save the image');
                    }
                    $nextIndex++;
                }
            }
        }
    }

    // Remove unchecked existing images (existing filenames only)
    $selectedImages = $_POST['selectedImages'] ?? [];
    $selectedImagesFilenames = array_filter($selectedImages, function($img) {
        return strpos($img, 'data:image/') !== 0;
    });
    foreach ($savedImages as $basename) {
        if (!in_array($basename, $selectedImagesFilenames)) {
            @unlink($imagedir . "/" . $basename);
        }
    }

    // Re-load saved images after deletion so display is accurate
    $savedImages = [];
    if (is_dir($imagedir)) {
        $pattern = $imagedir . "/" . check_file_dir_name($encounter) . "_" . check_file_dir_name($formid) . "_*.jpg";
        foreach (glob($pattern) as $filename) {
            $basename = basename($filename);
            $savedImages[] = $basename;
        }
        sort($savedImages);
    }
}

// Load form and patient data for display
if ($formid) {
    $row = sqlQuery("SELECT * FROM form_image_capture WHERE id = ? AND activity = '1'", array($formid));
    $formrow = sqlQuery("SELECT id FROM forms WHERE form_id = ? AND formdir = 'image_capture'", array($formid));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo xlt('Image Capture'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
      .dehead { font-family: sans-serif; font-weight: bold; }
      hr { box-sizing: content-box; height: 0; overflow: visible; margin-top: 1rem; border: 0; border-top: 1px solid #ffffff40; }
      video, canvas { display: block; margin: 10px auto; }
      .image-preview { display: inline-block; margin: 10px; position: relative; }
      .image-preview img { max-width: 150px; max-height: 150px; }
      .image-preview input[type="checkbox"] { position: absolute; top: 5px; left: 5px; }
      #camera-controls { margin-bottom: 1em; text-align: center; }
      #camera-controls button { margin: 0.5em 0.2em; padding: 0.6em 1.4em; border-radius: 10px; font-size: 1.05em; border: 1px solid #888; }
    </style>
    <script>
        function newEvt() {
            dlgopen('../../main/calendar/add_edit_event.php?patientid=' + <?php echo js_url($pid); ?>, '_blank', 775, 500);
            return false;
        }
        function deleteme(event) {
            event.stopPropagation();
            dlgopen('../../patient_file/deleter.php?formid=' + <?php echo js_url($formrow['id']); ?> +'&csrf_token_form=' + <?php echo js_url(CsrfUtils::collectCsrfToken()); ?>, '_blank', 500, 450, '', '', {
                resolvePromiseOn: 'close'
            }).then(function (data) {
                top.restoreSession();
            }).catch(function (error) {
                console.error("Dialog operation failed:", error);
                alert("Operation was canceled or failed.");
            });
            return false;
        }
        function imdeleted() {
            top.restoreSession();
            $("#delete").val("delete");
            $("#image-capture-form").submit();
        }
        function goBack() {
            top.restoreSession();
            $("#back").val("back");
            $("#image-capture-form").submit();
        }
    </script>
    <script>
        // --- Camera/Flash logic ---
        window.addEventListener('DOMContentLoaded', () => {
            const webcamElement = document.getElementById('webcam');
            const canvasElement = document.getElementById('canvas');
            const captureBtn = document.getElementById('capture-btn');
            const webPanel = document.getElementById('webcam-container');
            const webHide = document.getElementById('webcam-hide');
            const capturedImagesInput = document.getElementById('capturedImages');
            const toggleWebcamButton = document.getElementById('toggleWebcamButton');
            const errorMessage = document.getElementById('webcamErrorMessage');
            const previewContainer = document.getElementById('preview-container');
            const cameraControls = document.getElementById('camera-controls');
            const switchCameraBtn = document.getElementById('switchCameraBtn');
            const flashBtn = document.getElementById('toggleFlashBtn');
            let webcamStream = null;
            let webcamEnabled = false;
            let capturedImages = [];
            let facingMode = "environment"; // Default to back camera
            let torchOn = false;
            let currentTrack = null;
            let imageCapture = null;

            if (toggleWebcamButton) {
                toggleWebcamButton.addEventListener('click', () => {
                    if (webcamEnabled) {
                        stopWebcam();
                    } else {
                        startWebcam();
                    }
                });
            }
            if (switchCameraBtn) {
                switchCameraBtn.addEventListener('click', async () => {
                    facingMode = (facingMode === "environment") ? "user" : "environment";
                    stopWebcam();
                    torchOn = false;
                    await startWebcam();
                });
            }
            if (flashBtn) {
                flashBtn.addEventListener('click', async () => {
                    if (!currentTrack) return;
                    const capabilities = currentTrack.getCapabilities ? currentTrack.getCapabilities() : {};
                    if (!capabilities.torch) {
                        if (errorMessage) errorMessage.textContent = 'Flash/Torch not supported on this camera.';
                        return;
                    }
                    await setTorch(!torchOn);
                });
            }
            if (captureBtn) {
                captureBtn.addEventListener('click', () => {
                    const context = canvasElement.getContext('2d');
                    canvasElement.width = webcamElement.videoWidth;
                    canvasElement.height = webcamElement.videoHeight;
                    context.drawImage(webcamElement, 0, 0, canvasElement.width, canvasElement.height);
                    const picture = canvasElement.toDataURL('image/jpeg');
                    capturedImages.push(picture);
                    updatePreview(picture);
                    capturedImagesInput.value = JSON.stringify(capturedImages);
                });
            }

            async function startWebcam() {
                try {
                    const constraints = {
                        video: {
                            facingMode: facingMode
                        }
                    };
                    const stream = await navigator.mediaDevices.getUserMedia(constraints);
                    webcamElement.srcObject = stream;
                    webcamStream = stream;
                    webPanel.style.display = 'block';
                    webHide.style.display = 'block';
                    toggleWebcamButton.style.color = 'black';
                    toggleWebcamButton.textContent = 'Disable Webcam';
                    toggleWebcamButton.classList.remove('btn-success');
                    toggleWebcamButton.classList.add('btn-warning');
                    captureBtn.style.display = 'block';
                    cameraControls.style.display = 'block';
                    webcamEnabled = true;

                    const [videoTrack] = stream.getVideoTracks();
                    currentTrack = videoTrack;
                    if ("ImageCapture" in window && videoTrack) {
                        imageCapture = new ImageCapture(videoTrack);
                    } else {
                        imageCapture = null;
                    }
                    videoTrack.onended = () => { alert('Webcam stream ended.'); };

                    if (errorMessage) errorMessage.textContent = '';
                    webPanel.scrollIntoView({behavior: 'smooth', block: 'center'});

                    if (torchOn) { setTorch(true); }
                } catch (err) {
                    webHide.style.display = 'none';
                    webPanel.style.display = 'none';
                    if (captureBtn) captureBtn.style.display = 'none';
                    if (cameraControls) cameraControls.style.display = 'none';
                    handleWebcamError(err);
                }
            }

            function stopWebcam() {
                if (webcamStream) {
                    webcamStream.getTracks().forEach(track => track.stop());
                    webcamElement.srcObject = null;
                    webcamStream = null;
                }
                webPanel.style.display = 'none';
                webHide.style.display = 'none';
                if (captureBtn) captureBtn.style.display = 'none';
                if (cameraControls) cameraControls.style.display = 'none';
                if (toggleWebcamButton) {
                    toggleWebcamButton.style.color = 'white';
                    toggleWebcamButton.textContent = 'Enable Webcam';
                    toggleWebcamButton.classList.remove('btn-warning');
                    toggleWebcamButton.classList.add('btn-success');
                }
                webcamEnabled = false;
                currentTrack = null;
                imageCapture = null;
            }

            function handleWebcamError(err) {
                if (!errorMessage) return;
                if (err.name === 'NotAllowedError') {
                    errorMessage.textContent = 'User denied access to the webcam. Please check your browser settings and allow camera access.';
                } else if (err.name === 'NotFoundError') {
                    errorMessage.textContent = 'No webcam found on this device. Please ensure a webcam is connected.';
                } else if (err.name === 'NotReadableError') {
                    errorMessage.textContent = 'Webcam is already in use by another application or browser. Please close other apps and try again.';
                } else {
                    errorMessage.textContent = `Unexpected error: ${err.name + err.message}. Try refreshing page or Webcam is already in use by another application or browser.`;
                }
                errorMessage.scrollIntoView({behavior: 'smooth', block: 'center'});
            }

            async function setTorch(on) {
                if (!currentTrack) return;
                const capabilities = currentTrack.getCapabilities ? currentTrack.getCapabilities() : {};
                if (!capabilities.torch) {
                    if (flashBtn) {
                        flashBtn.textContent = 'No Flash (Unsupported)';
                        flashBtn.disabled = true;
                    }
                    return;
                }
                try {
                    await currentTrack.applyConstraints({ advanced: [{ torch: on }] });
                    torchOn = on;
                    if (flashBtn) {
                        flashBtn.textContent = torchOn ? 'Flash ON' : 'Flash OFF';
                        flashBtn.disabled = false;
                    }
                } catch (e) {
                    if (errorMessage) errorMessage.textContent = 'Failed to toggle flash/torch: ' + e;
                }
            }

            function updatePreview(imageData) {
                if (!previewContainer) return;
                const previewDiv = document.createElement('div');
                previewDiv.className = 'image-preview';
                previewDiv.innerHTML = `
                    <input type="checkbox" name="selectedImages[]" value="${imageData}" checked>
                    <img src="${imageData}" alt="Captured Image">
                `;
                previewContainer.appendChild(previewDiv);
            }
        });
    </script>
</head>
<body class="body_top">
    <div class="container">
        <form method="post" enctype="multipart/form-data" id="image-capture-form" class="mt-4" action="<?php echo $rootdir ?>/forms/image_capture/new.php?id=<?php echo attr_url($formid); ?>">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input type="hidden" id="capturedImages" name="capturedImages" value="">
            <!-- Notes Section -->
            <div class="card">
                <div class="card-header text-center bg-light">
                    <h4 class="m-0"><?php echo xlt('Image Capture'); ?></h4>
                </div>
                <div class="card-body">
                    <div class="form-group row">
                        <label for="form_notes" class="col-sm-2 col-form-label dehead"><?php echo xlt('Comments'); ?></label>
                        <div class="col-sm-10">
                            <textarea id="form_notes" name="form_notes" rows="4" class="form-control"><?php echo text($row['notes']); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Webcam Capture Option -->
            <div id="webcam-hide" class="card mt-2 mb-5" style="display: none;">
                <div class="card-header text-center bg-light">
                    <h4 class="m-0"><?php echo xlt('Webcam Preview'); ?></h4>
                </div>
                <div class="card-body">
                    <div id="camera-controls">
                        <button type="button" id="switchCameraBtn" class="btn btn-info">Switch Camera</button>
                        <button type="button" id="toggleFlashBtn" class="btn btn-warning">Flash OFF</button>
                    </div>
                    <div class="form-group row text-center">
                        <label for="webcam" class="col-sm-2 col-form-label"><?php echo xlt('Preview'); ?></label>
                        <div id="webcam-container" class="col-sm-10 text-center" style="display: block;">
                            <video id="webcam" autoplay playsinline style="width: 100%; max-width: 640px;"></video>
                            <canvas id="canvas" style="display: none;"></canvas>
                        </div>
                    </div>
                    <button type="button" id="capture-btn" class="btn btn-success mx-1 float-right" style="display: block;"><?php echo xlt('Capture Frame'); ?></button>
                </div>
            </div>
            <!-- Preview Images Section -->
            <div class="card">
                <div class="card-header text-center bg-light">
                    <h4 class="m-0"><?php echo xlt('Captured Images'); ?></h4>
                </div>
                <div class="card-body">
                    <div class="form-group row">
                        <label for="fileUpload" class="col-sm-2 col-form-label dehead"><?php echo xlt('Document'); ?></label>
                        <div class="col-sm-10">
                            <input type="hidden" name="MAX_FILE_SIZE" value="12000000" />
                            <div id="preview-container" class="text-center">
<?php
$baseurl = $GLOBALS['webroot'] . "/sites/default/documents/" . check_file_dir_name($pid) . "/encounters/";

if ($formid && !empty($savedImages)) {
    foreach ($savedImages as $basename) {
        if (preg_match('/^' . preg_quote(check_file_dir_name($encounter), '/') . '_' . preg_quote(check_file_dir_name($formid), '/') . '_\d+\.jpg$/', $basename)) {
            $imgUrl = $baseurl . $basename;
            echo '<div class="image-preview">';
            echo '<input type="checkbox" name="selectedImages[]" value="' . attr($basename) . '" checked>';
            echo '<img src="' . attr($imgUrl) . '" alt="Captured Image" />';
            echo '</div>';
        }
    }
}
?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Buttons Section -->
            <div class="mt-3 text-center">
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary" name='bn_save' value="save"><?php echo xlt('Save'); ?></button>
                    <button type="button" class="btn btn-primary" onclick="newEvt()"><?php echo xlt('Add Appointment'); ?></button>
                    <button type="button" id="toggleWebcamButton" class="btn btn-success"><?php echo xlt('Enable Webcam'); ?></button>
                    <?php if ($formrow['id'] && AclMain::aclCheckCore('admin', 'super')) { ?>
                        <input type="hidden" id="delete" name="delete" value="">
                        <button type="button" class="btn btn-danger" onclick="return deleteme(event);"><?php echo xlt('Delete'); ?></button>
                    <?php } ?>
                    <input type="hidden" id="back" name="back" value="">
                    <button type="button" class="btn btn-secondary" onclick="return goBack()"><?php echo xlt('Back'); ?></button>
                </div>
                <p class="text-danger m-1" id="webcamErrorMessage" style="font-size: 1.1rem"></p>
            </div>
        </form>
    </div>
</body>
</html>

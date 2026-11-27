<?php
/* ======================================================================
 * Central validation, security and error-handling helpers.
 * Loaded automatically by config/db.php, so every page can use them.
 *
 *  1. Error handling  - safe user messages, technical details only in the log
 *  2. Responses       - JSON helpers + HTTP status codes for AJAX requests
 *  3. Flash messages  - success / error notices that survive a redirect
 *  4. CSRF protection - token on every POST form (checked automatically)
 *  5. Validator       - field-by-field rules with standard error messages
 *  6. File uploads    - MIME/content checks, size limit, safe file names
 *  7. Abuse limits    - failed-login throttling, message rate limits
 * ====================================================================== */

/* ----------------------------------------------------------------------
 * Standard user-facing messages (kept in one place for consistency)
 * -------------------------------------------------------------------- */
const MSG_REQUIRED          = 'This field is required.';
const MSG_EMPTY             = 'This field cannot be empty.';
const MSG_EMAIL             = 'Please enter a valid email address.';
const MSG_EMAIL_TAKEN       = 'This email address is already registered.';
const MSG_PHONE             = 'Please enter a valid phone number using numbers only.';
const MSG_PASSWORD_SHORT    = 'Password must be at least 8 characters long.';
const MSG_PASSWORD_LONG     = 'Password must not exceed 72 characters.';
const MSG_PASSWORD_WEAK     = 'Password must contain at least one letter and one number.';
const MSG_PASSWORD_MISMATCH = 'Passwords do not match. Please try again.';
const MSG_LOGIN_FAILED      = 'Invalid email or password.';
const MSG_QUANTITY          = 'Please enter a valid quantity greater than zero.';
const MSG_TOO_LONG_MESSAGE  = 'Your message exceeds the maximum allowed character limit.';
const MSG_FILE_TYPE         = 'Invalid file format. Please upload an accepted file type.';
const MSG_FILE_SIZE         = 'File size exceeds the maximum allowed limit.';
const MSG_DUPLICATE         = 'This request has already been submitted.';
const MSG_UNAUTHORIZED      = 'You are not authorized to perform this action.';
const MSG_INVALID_OPERATION = 'This operation cannot be completed. Please check your input.';
const MSG_SERVER_ERROR      = 'Something went wrong. Please try again later.';
const MSG_SESSION_EXPIRED   = 'Your session has expired or this form is no longer valid. Please refresh the page and try again.';
const MSG_NOT_FOUND         = 'The requested record could not be found.';
const MSG_FORM_ERRORS       = 'Please correct the highlighted fields and try again.';
const MSG_INVALID_CHOICE    = 'Please select a valid option.';
const MSG_NAME              = 'Please enter a valid name using letters, spaces, periods, apostrophes or hyphens only.';
const MSG_RATE_LIMITED      = 'You are sending messages too quickly. Please wait a few minutes and try again.';

function msgInsufficientStock($available) {
    return 'Insufficient stock. Only ' . max(0, (int)$available) . ' item(s) available.';
}

/* ----------------------------------------------------------------------
 * 1. Error handling
 * -------------------------------------------------------------------- */
error_reporting(E_ALL);
ini_set('display_errors', '0');      // never show PHP errors / stack traces to users
ini_set('log_errors', '1');          // technical details go to the server error log

/** Writes a technical error to the server log (never shown to users). */
function logAppError($context, $error = null) {
    $detail = '';
    if ($error instanceof Throwable) {
        $detail = get_class($error) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine();
    } elseif ($error !== null) {
        $detail = (string)$error;
    }
    $where = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? '');
    error_log('[SMISKI] ' . $context . ' | ' . $where . ($detail !== '' ? ' | ' . $detail : ''));
}

/** Exception whose message is safe to show to the user (business-rule errors). */
class UserFacingException extends RuntimeException {
    private $httpStatus;
    public function __construct($message, $httpStatus = 422) {
        parent::__construct($message);
        $this->httpStatus = (int)$httpStatus;
    }
    public function getHttpStatus() { return $this->httpStatus; }
}

/** Message to show for any exception: its own text if user-facing, otherwise generic (and logged). */
function safeErrorMessage(Throwable $e, $context = 'Unexpected error') {
    if ($e instanceof UserFacingException) {
        return $e->getMessage();
    }
    logAppError($context, $e);
    return MSG_SERVER_ERROR;
}

function renderErrorPage($status, $message) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Error</title>'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;font-family:Outfit,"Segoe UI",sans-serif;background:linear-gradient(135deg,#14532d 0%,#166534 50%,#052e16 100%);}'
       . '.box{background:rgba(255,255,255,.85);border-radius:24px;padding:2.5rem;max-width:440px;width:100%;text-align:center;box-shadow:0 20px 40px rgba(0,0,0,.35);color:#064e3b}'
       . 'h1{font-size:1.3rem;margin:0 0 .75rem}p{color:#166534;margin:0 0 1.5rem}a{display:inline-block;background:#15803d;color:#fff;text-decoration:none;padding:.75rem 1.5rem;border-radius:12px;font-weight:600}</style></head>'
       . '<body><div class="box"><h1>' . ($status >= 500 ? 'Something went wrong' : 'Request could not be completed') . '</h1><p>' . $safe . '</p>'
       . '<a href="javascript:history.back()">Go Back</a></div></body></html>';
}

set_exception_handler(function ($e) {
    logAppError('Uncaught exception', $e);
    if (isAjaxRequest()) {
        jsonResponse(500, ['success' => false, 'message' => MSG_SERVER_ERROR]);
    }
    renderErrorPage(500, MSG_SERVER_ERROR);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        logAppError('Fatal error', $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            if (isAjaxRequest()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'message' => MSG_SERVER_ERROR]);
                return;
            }
            renderErrorPage(500, MSG_SERVER_ERROR);
        }
    }
});

/* ----------------------------------------------------------------------
 * 2. Responses
 * -------------------------------------------------------------------- */
function isAjaxRequest() {
    $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    return $requestedWith === 'xmlhttprequest'
        || strpos($accept, 'application/json') !== false
        || isset($_GET['ajax']);
}

/**
 * Sends a structured JSON response and stops.
 * Format: { "success": bool, "message": string, "errors": {field: message}, ...extra data }
 */
function jsonResponse($status, array $payload) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload);
    exit;
}

/** Same-host URL to send the user back to (falls back to $default). */
function safeRedirectTarget($default) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) {
        return $referer;
    }
    return $default;
}

/** 303 redirect (the browser follows with GET, so refreshing never re-posts). */
function redirectTo($url) {
    header('Location: ' . $url, true, 303);
    exit;
}

/**
 * Stops the request with an error: JSON for AJAX, otherwise a flash message
 * and a redirect (or an error page when there is nowhere sensible to go back to).
 */
function abortRequest($status, $message, $redirect = null, array $errors = []) {
    if (isAjaxRequest()) {
        $payload = ['success' => false, 'message' => $message];
        if ($errors) {
            $payload['errors'] = $errors;
        }
        jsonResponse($status, $payload);
    }
    if ($redirect !== null) {
        setFlash('error', $message);
        redirectTo($redirect);
    }
    renderErrorPage($status, $message);
    exit;
}

/* ----------------------------------------------------------------------
 * 3. Flash messages and form state (Post/Redirect/Get)
 * -------------------------------------------------------------------- */
function setFlash($type, $message) {
    $_SESSION['flash'][$type] = $message;
}

function getFlash($type) {
    $message = $_SESSION['flash'][$type] ?? '';
    unset($_SESSION['flash'][$type]);
    return $message;
}

/** Keeps validation errors + entered values across a redirect (secrets are never kept). */
function setFormState($form, array $errors, array $old = []) {
    foreach (['password', 'password_confirm', 'card_number', 'card_cvv', 'card_expiry', 'csrf_token', 'form_token'] as $secret) {
        unset($old[$secret]);
    }
    $_SESSION['form_state'][$form] = ['errors' => $errors, 'old' => $old];
}

function takeFormState($form) {
    $state = $_SESSION['form_state'][$form] ?? ['errors' => [], 'old' => []];
    unset($_SESSION['form_state'][$form]);
    return $state;
}

/** Escaped HTML for a field's error message (empty string when the field is valid). */
function fieldError(array $errors, $field) {
    if (empty($errors[$field])) {
        return '';
    }
    return '<div class="field-error" data-server-error="1"><i class="fa-solid fa-circle-exclamation"></i> <span>'
        . htmlspecialchars($errors[$field], ENT_QUOTES, 'UTF-8') . '</span></div>';
}

/** CSS class for an invalid field. */
function invalidClass(array $errors, $field) {
    return empty($errors[$field]) ? '' : ' is-invalid';
}

/** aria-invalid attribute for an invalid field. */
function invalidAttr(array $errors, $field) {
    return empty($errors[$field]) ? '' : ' aria-invalid="true"';
}

/** Page-level notice. $type: success | error | info. */
function alertBox($type, $message) {
    if ($message === '' || $message === null) {
        return '';
    }
    $icons = ['success' => 'fa-circle-check', 'error' => 'fa-circle-exclamation', 'info' => 'fa-circle-info'];
    $icon = $icons[$type] ?? 'fa-circle-info';
    return '<div class="form-alert form-alert-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">'
        . '<i class="fa-solid ' . $icon . '"></i> <span>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</span></div>';
}

/** Escaped value of a previously entered field. */
function oldValue(array $old, $field, $default = '') {
    $value = $old[$field] ?? $default;
    return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, 'UTF-8');
}

/** Escaped JSON for use inside an HTML attribute (e.g. onclick='fn(...)'). */
function jsonAttr($value) {
    return htmlspecialchars(json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
}

/* ----------------------------------------------------------------------
 * 4. CSRF protection
 * -------------------------------------------------------------------- */
function csrfToken() {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Query-string fragment for state-changing links (e.g. cart buttons). */
function csrfQuery() {
    return 'csrf_token=' . urlencode(csrfToken());
}

function isValidCsrfToken($token) {
    return is_string($token) && $token !== '' && !empty($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** For state-changing GET links: checks the token in the URL. */
function requireCsrfFromQuery($redirect) {
    if (!isValidCsrfToken($_GET['csrf_token'] ?? '')) {
        abortRequest(403, MSG_SESSION_EXPIRED, $redirect);
    }
}

/** Runs on every request: every POST must carry a valid CSRF token. */
function enforceCsrfOnPost() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $backTo = strtok($_SERVER['REQUEST_URI'] ?? '/', '#');

    // A body bigger than post_max_size makes PHP drop all POST data and files
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
        abortRequest(413, MSG_FILE_SIZE . ' (Maximum 5 MB.)', $backTo);
    }

    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!isValidCsrfToken($token)) {
        abortRequest(403, MSG_SESSION_EXPIRED, $backTo);
    }
}

/* ----------------------------------------------------------------------
 * 5. Validator
 *    $v = new Validator($_POST);
 *    $email = $v->email('email');
 *    if ($v->fails()) { $errors = $v->errors(); }
 * -------------------------------------------------------------------- */
class Validator {
    private $data;
    private $errors = [];

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function fails()  { return !empty($this->errors); }
    public function errors() { return $this->errors; }
    public function hasError($field) { return isset($this->errors[$field]); }

    public function addError($field, $message) {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    /** Raw string value, or null when the field was sent as an array / unexpected type. */
    private function raw($field) {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null) {
            return '';
        }
        $value = $this->data[$field];
        if (is_array($value) || is_object($value)) {
            return null;
        }
        return (string)$value;
    }

    /**
     * Text field. Options: required, min, max, multiline, pattern, patternMessage,
     * emptyMessage, minMessage, maxMessage.
     * Returns the trimmed value. Characters are kept as typed (no HTML-encoding on input);
     * pages escape values when they display them.
     */
    public function text($field, array $o = []) {
        $value = $this->raw($field);
        if ($value === null || !mb_check_encoding($value, 'UTF-8')) {
            $this->addError($field, MSG_INVALID_OPERATION);
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value); // control characters
        if (empty($o['multiline'])) {
            $value = preg_replace('/[\n\t]+/', ' ', $value);
        }
        $value = trim($value);

        if ($value === '') {
            if (!empty($o['required'])) {
                $this->addError($field, $o['emptyMessage'] ?? MSG_REQUIRED);
            }
            return '';
        }
        $len = mb_strlen($value, 'UTF-8');
        if (isset($o['min']) && $len < $o['min']) {
            $this->addError($field, $o['minMessage'] ?? ('This field must be at least ' . $o['min'] . ' characters long.'));
        } elseif (isset($o['max']) && $len > $o['max']) {
            $this->addError($field, $o['maxMessage'] ?? ('This field must not exceed ' . $o['max'] . ' characters.'));
        } elseif (isset($o['pattern']) && !preg_match($o['pattern'], $value)) {
            $this->addError($field, $o['patternMessage'] ?? MSG_INVALID_OPERATION);
        }
        return $value;
    }

    /** Long text (messages, replies, descriptions). */
    public function message($field, array $o = []) {
        $max = $o['max'] ?? 2000;
        return $this->text($field, $o + [
            'multiline'    => true,
            'emptyMessage' => MSG_EMPTY,
            'max'          => $max,
            'maxMessage'   => MSG_TOO_LONG_MESSAGE . ' (Maximum ' . $max . ' characters.)',
        ]);
    }

    /** Person name: letters (any language), spaces, periods, apostrophes, hyphens. */
    public function personName($field, array $o = []) {
        return $this->text($field, $o + [
            'required'       => true,
            'min'            => 2,
            'max'            => 100,
            'pattern'        => "/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\x{2019}\\-]*$/u",
            'patternMessage' => MSG_NAME,
        ]);
    }

    /** Email address (returned lower-cased). */
    public function email($field, array $o = []) {
        $value = $this->text($field, $o + ['required' => true, 'max' => 100, 'maxMessage' => 'Email address must not exceed 100 characters.']);
        if ($value === '' || $this->hasError($field)) {
            return $value;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($field, MSG_EMAIL);
        }
        return strtolower($value);
    }

    /** Phone number: digits only, 7-15 digits (same rule everywhere). */
    public function phone($field, array $o = []) {
        $value = $this->text($field, $o + ['required' => true]);
        if ($value === '' || $this->hasError($field)) {
            return $value;
        }
        if (!isValidPhone($value)) {
            $this->addError($field, MSG_PHONE . ' (7 to 15 digits)');
        }
        return $value;
    }

    /** New password: 8-72 characters with at least one letter and one number. Never trimmed. */
    public function newPassword($field) {
        $value = $this->raw($field);
        if ($value === null) {
            $this->addError($field, MSG_INVALID_OPERATION);
            return '';
        }
        if (trim($value) === '') {
            $this->addError($field, MSG_REQUIRED);
        } elseif (strlen($value) < 8) {
            $this->addError($field, MSG_PASSWORD_SHORT);
        } elseif (strlen($value) > 72) {
            $this->addError($field, MSG_PASSWORD_LONG);
        } elseif (!preg_match('/[A-Za-z]/', $value) || !preg_match('/[0-9]/', $value)) {
            $this->addError($field, MSG_PASSWORD_WEAK);
        }
        return $value;
    }

    /** Password typed at login: only required (existing accounts keep working). */
    public function loginPassword($field) {
        $value = $this->raw($field);
        if ($value === null || trim($value) === '') {
            $this->addError($field, MSG_REQUIRED);
            return '';
        }
        return $value;
    }

    /** Confirmation field must equal $expected. */
    public function confirmation($field, $expected) {
        $value = $this->raw($field);
        if ($value === null || $value === '') {
            $this->addError($field, MSG_REQUIRED);
        } elseif (!hash_equals((string)$expected, $value)) {
            $this->addError($field, MSG_PASSWORD_MISMATCH);
        }
        return $value;
    }

    /** Whole number. Options: required (default true), min, max, message, minMessage, maxMessage. */
    public function int($field, array $o = []) {
        $value = $this->raw($field);
        $message = $o['message'] ?? 'Please enter a whole number.';
        if ($value === null) {
            $this->addError($field, MSG_INVALID_OPERATION);
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            if ($o['required'] ?? true) {
                $this->addError($field, MSG_REQUIRED);
            }
            return null;
        }
        if (!preg_match('/^-?\d{1,10}$/', $value)) {
            $this->addError($field, $message);
            return null;
        }
        $int = (int)$value;
        if (isset($o['min']) && $int < $o['min']) {
            $this->addError($field, $o['minMessage'] ?? $message);
            return null;
        }
        if (isset($o['max']) && $int > $o['max']) {
            $this->addError($field, $o['maxMessage'] ?? ('Value must not be greater than ' . number_format($o['max']) . '.'));
            return null;
        }
        return $int;
    }

    /** Record identifier: positive whole number. */
    public function id($field) {
        return $this->int($field, ['min' => 1, 'max' => 2147483647, 'message' => MSG_INVALID_OPERATION, 'minMessage' => MSG_INVALID_OPERATION, 'maxMessage' => MSG_INVALID_OPERATION]);
    }

    /** Money amount with up to 2 decimals. Options: required, min, max, minMessage, maxMessage. */
    public function money($field, array $o = []) {
        $value = $this->raw($field);
        if ($value === null) {
            $this->addError($field, MSG_INVALID_OPERATION);
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            if ($o['required'] ?? true) {
                $this->addError($field, MSG_REQUIRED);
            }
            return null;
        }
        if ($value[0] === '-') {
            $this->addError($field, $o['negativeMessage'] ?? 'Amount cannot be negative.');
            return null;
        }
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value)) {
            $this->addError($field, 'Please enter a valid amount with up to 2 decimal places.');
            return null;
        }
        $amount = round((float)$value, 2);
        if (isset($o['min']) && $amount < $o['min']) {
            $this->addError($field, $o['minMessage'] ?? ('Amount must be at least ' . number_format($o['min'], 2) . '.'));
            return null;
        }
        if (isset($o['max']) && $amount > $o['max']) {
            $this->addError($field, $o['maxMessage'] ?? ('Amount must not exceed ' . number_format($o['max'], 2) . '.'));
            return null;
        }
        return $amount;
    }

    /** Value must be one of $allowed. Options: required (default true), message, requiredMessage. */
    public function choice($field, array $allowed, array $o = []) {
        $value = $this->raw($field);
        if ($value === null) {
            $this->addError($field, MSG_INVALID_OPERATION);
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            if ($o['required'] ?? true) {
                $this->addError($field, $o['requiredMessage'] ?? MSG_REQUIRED);
            }
            return null;
        }
        if (!in_array($value, array_map('strval', $allowed), true)) {
            $this->addError($field, $o['message'] ?? MSG_INVALID_CHOICE);
            return null;
        }
        return $value;
    }
}

/* ----------------------------------------------------------------------
 * 6. File uploads (images)
 * -------------------------------------------------------------------- */
const UPLOAD_MAX_BYTES = 5242880; // 5 MB

/** MIME type => file extension for accepted image formats. */
function allowedImageTypes($includeGif = true) {
    $types = [
        'image/jpeg'     => 'jpg',
        'image/png'      => 'png',
        'image/webp'     => 'webp',
        'image/heic'     => 'heic',
        'image/heif'     => 'heif',
        'image/avif'     => 'avif',
        'image/bmp'      => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/tiff'     => 'tiff',
    ];
    if ($includeGif) {
        $types['image/gif'] = 'gif';
    }
    return $types;
}

/** MIME type from the file's content (not its name). */
function detectMimeType($path) {
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if ($mime) return strtolower($mime);
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if ($mime) return strtolower($mime);
    }
    $info = @getimagesize($path);
    return $info ? strtolower($info['mime']) : '';
}

/**
 * Validates an uploaded image WITHOUT saving it.
 * Returns ['present' => bool, 'error' => string|null, 'tmp' => path, 'ext' => ext].
 */
function checkImageUpload($field, array $allowedTypes, $maxBytes = UPLOAD_MAX_BYTES, $required = false) {
    $acceptedList = strtoupper(implode(', ', array_unique(array_values($allowedTypes))));
    $typeError = MSG_FILE_TYPE . ' (Accepted: ' . $acceptedList . '.)';
    $result = ['present' => false, 'error' => null, 'tmp' => null, 'ext' => null];
    $file = $_FILES[$field] ?? null;

    if (!$file || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            $result['error'] = MSG_REQUIRED;
        }
        return $result;
    }
    $result['present'] = true;

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || (int)$file['size'] > $maxBytes) {
        $result['error'] = MSG_FILE_SIZE . ' (Maximum ' . round($maxBytes / 1048576) . ' MB.)';
        return $result;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        logAppError('Upload failed', 'PHP upload error code ' . $file['error']);
        $result['error'] = 'The file could not be uploaded. Please try again.';
        return $result;
    }
    if ((int)$file['size'] <= 0) {
        $result['error'] = $typeError;
        return $result;
    }

    $mime = detectMimeType($file['tmp_name']);
    if (!isset($allowedTypes[$mime])) {
        $result['error'] = $typeError;
        return $result;
    }
    // Formats PHP can read must also parse as a real image (not just a fake header)
    $parsable = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-ms-bmp', 'image/tiff'];
    if (in_array($mime, $parsable, true)) {
        $size = @getimagesize($file['tmp_name']);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            $result['error'] = $typeError;
            return $result;
        }
        // With the GD extension, fully decode the image to be sure it is not disguised data
        $gdTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-ms-bmp'];
        if (in_array($mime, $gdTypes, true) && function_exists('imagecreatefromstring') && (int)$file['size'] <= $maxBytes) {
            $image = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
            if ($image === false) {
                $result['error'] = $typeError;
                return $result;
            }
            imagedestroy($image);
        }
    }

    $result['tmp'] = $file['tmp_name'];
    $result['ext'] = $allowedTypes[$mime];
    return $result;
}

/**
 * Saves a file checked by checkImageUpload() under a random, safe file name.
 * Returns the relative path (e.g. assets/uploads/products/x.png).
 * Throws UserFacingException if it cannot be saved.
 */
function storeCheckedUpload(array $checked, $subDir, $prefix) {
    $relativeDir = 'assets/uploads/' . $subDir . '/';
    $absoluteDir = __DIR__ . '/../' . $relativeDir;
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true)) {
        logAppError('Upload directory not writable', $absoluteDir);
        throw new UserFacingException('The file could not be saved. Please try again later.', 500);
    }
    $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $checked['ext'];
    if (!move_uploaded_file($checked['tmp'], $absoluteDir . $filename)) {
        logAppError('move_uploaded_file failed', $absoluteDir . $filename);
        throw new UserFacingException('The file could not be saved. Please try again later.', 500);
    }
    @chmod($absoluteDir . $filename, 0644);
    return $relativeDir . $filename;
}

/** Removes a file saved by storeCheckedUpload() (used when the related database save fails). */
function deleteStoredUpload($relativePath) {
    if ($relativePath && strpos($relativePath, 'assets/uploads/') === 0 && strpos($relativePath, '..') === false) {
        $absolute = __DIR__ . '/../' . $relativePath;
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}

/* ----------------------------------------------------------------------
 * 7. Abuse limits (use the existing activity_logs / contact_messages tables)
 * -------------------------------------------------------------------- */
const LOGIN_MAX_ATTEMPTS   = 5;   // failed attempts ...
const LOGIN_WINDOW_MINUTES = 15;  // ... within this many minutes (per IP address)

function clientIp() {
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function isLoginThrottled() {
    try {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'LOGIN_FAILED' AND ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([clientIp()]);
        return (int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
    } catch (Exception $e) {
        logAppError('Login throttle check failed', $e);
        return false;
    }
}

function recordFailedLogin($email, $portal) {
    logActivity(null, 'LOGIN_FAILED', 'Failed ' . $portal . ' login attempt for: ' . mb_substr((string)$email, 0, 100));
}

function msgLoginThrottled() {
    return 'Too many failed login attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
}

/** True when this email already sent $max messages in the last 10 minutes. */
function isMessageRateLimited($email, $max = 5) {
    try {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM contact_messages WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn() >= $max;
    } catch (Exception $e) {
        logAppError('Message rate-limit check failed', $e);
        return false;
    }
}

/* ----------------------------------------------------------------------
 * 8. Shared business rules
 * -------------------------------------------------------------------- */

/**
 * Rules for support / contact messages (My Messages page and the Contact Us form).
 * When $accountEmail is given (logged-in customer) it is always used, so a message
 * can't be filed under someone else's email.
 * Returns [values, errors].
 */
function validateSupportMessage(array $input, $accountEmail = null) {
    $v = new Validator($input);
    $values = [
        'name'    => $v->personName('name'),
        'email'   => $accountEmail !== null ? strtolower((string)$accountEmail) : $v->email('email'),
        'subject' => $v->text('subject', ['required' => true, 'min' => 3, 'max' => 200]),
        'message' => $v->message('message', ['required' => true, 'min' => 10, 'max' => 2000,
                        'minMessage' => 'Please describe your concern in at least 10 characters.']),
    ];
    return [$values, $v->errors()];
}

/** Saves a validated support message. */
function saveSupportMessage(array $values) {
    $stmt = getDB()->prepare("INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$values['name'], $values['email'], $values['subject'], $values['message']]);
}

/** Starts a fresh session ID after login (prevents session fixation) while keeping the cart. */
function regenerateSessionAfterLogin() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    unset($_SESSION['csrf_token']); // new token for the new session
}

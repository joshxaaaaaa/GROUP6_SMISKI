<?php
require_once __DIR__ . '/../config/db.php';

$rawId = $_GET['id'] ?? '';
if (!is_string($rawId) || !ctype_digit($rawId) || (int)$rawId < 1) {
    setFlash('error', 'Invalid product link. ' . MSG_INVALID_OPERATION);
    header("Location: index.php", true, 303);
    exit;
}
$id = (int)$rawId;
$db = getDB();

$stmt = $db->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ? AND p.status = 'active'");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    setFlash('error', 'Sorry, this product could not be found or is no longer available.');
    header("Location: index.php", true, 303);
    exit;
}

$isBundle = (
    isset($product['category_name']) && (
        stripos($product['category_name'], 'Bundle') !== false || 
        stripos($product['category_name'], 'Set') !== false
    )
) || (
    isset($product['name']) && (
        stripos($product['name'], 'Bundle') !== false || 
        stripos($product['name'], 'Set') !== false
    )
);

$imgContainerShape = $isBundle ? 'border-radius: 16px;' : 'border-radius: 50%;';

$isReviewCustomer = isCustomerLoggedIn() && !isAdminLoggedIn();
$reviewCustomerId = $isReviewCustomer ? (int)$_SESSION['user_id'] : 0;
$reviewErrors = [];
$reviewOld = [];
$reviewFormError = '';
$productUrl = 'product.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_review') {
    if (isAdminLoggedIn()) {
        abortRequest(403, MSG_UNAUTHORIZED . ' Reviews can only be written from customer accounts.', $productUrl);
    }
    if (!$isReviewCustomer) {
        abortRequest(401, 'Please log in to write a review.', $productUrl);
    }
    ensureStoreSchema();
    if (getCustomerReview($reviewCustomerId, $id)) {
        abortRequest(409, 'You have already submitted a review for this product.', $productUrl . '#reviews');
    }
    if (!customerReceivedProduct($reviewCustomerId, $id)) {
        abortRequest(403, 'You can review this product once an order containing it has been delivered to you.', $productUrl . '#reviews');
    }

    $v = new Validator($_POST);
    $rating = $v->choice('rating', ['1', '2', '3', '4', '5'], ['requiredMessage' => MSG_RATING, 'message' => MSG_RATING]);
    $reviewText = $v->message('review_text', [
        'required' => true,
        'min' => REVIEW_MIN_LENGTH,
        'max' => REVIEW_MAX_LENGTH,
        'emptyMessage' => MSG_REVIEW_EMPTY,
        'minMessage' => 'Your review must be at least ' . REVIEW_MIN_LENGTH . ' characters long.',
        'maxMessage' => 'Your review exceeds the maximum allowed character limit. (Maximum ' . REVIEW_MAX_LENGTH . ' characters.)',
    ]);
    $reviewErrors = $v->errors();
    $reviewOld = ['rating' => $rating, 'review_text' => $reviewText];

    if ($reviewErrors) {
        $reviewFormError = 'Your review was not submitted. ' . MSG_FORM_ERRORS;
        http_response_code(422);
    } elseif (!consumeFormToken('review_' . $id, $_POST['form_token'] ?? '')) {
        abortRequest(409, MSG_DUPLICATE, $productUrl . '#reviews');
    } else {
        try {
            $insert = $db->prepare("INSERT INTO product_reviews (product_id, customer_id, rating, review_text, status) VALUES (?, ?, ?, ?, 'pending')");
            $insert->execute([$id, $reviewCustomerId, (int)$rating, $reviewText]);
            logActivity($reviewCustomerId, 'REVIEW_SUBMITTED', 'Review submitted for product ID ' . $id . ' (' . (int)$rating . ' stars)');
            setFlash('success', 'Thank you! Your review was submitted and will appear here once it is approved.');
            header("Location: " . $productUrl . "#reviews", true, 303);
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                abortRequest(409, 'You have already submitted a review for this product.', $productUrl . '#reviews');
            }
            $reviewFormError = 'Your review was not submitted. ' . safeErrorMessage($e, 'Review insert failed');
            http_response_code(500);
        }
    }
}

$reviewStats = getProductReviewStats($id);
$approvedReviews = getApprovedReviews($id, 20);
$myReview = null;
$canReview = false;
$reviewToken = '';
if ($isReviewCustomer) {
    try {
        $myReview = getCustomerReview($reviewCustomerId, $id);
        $canReview = !$myReview && customerReceivedProduct($reviewCustomerId, $id);
        if ($canReview) {
            $reviewToken = generateFormToken('review_' . $id);
        }
    } catch (Exception $e) {
        logAppError('Loading customer review failed', $e);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        min-height: 100vh;
    }

    .smiski-card {
        background: rgba(255, 255, 255, 0.78) !important;
        backdrop-filter: blur(30px) saturate(180%);
        -webkit-backdrop-filter: blur(30px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.5) !important;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
    }
    .smiski-card:hover {
        transform: translateY(-4px);
        border-color: #86efac !important;
        background: rgba(255, 255, 255, 0.88) !important;
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.45), 0 0 35px rgba(134, 239, 172, 0.6), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
    }

    .hover-scale {
        transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .hover-scale:hover {
        transform: translateY(-3px);
        border-color: #86efac !important;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2), 0 0 20px rgba(74, 222, 128, 0.5) !important;
    }

    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }
    .rating-input {
        display: inline-flex;
        flex-direction: row-reverse;
        gap: 0.3rem;
        position: relative;
    }
    .rating-input input {
        position: absolute;
        opacity: 0;
        width: 1px;
        height: 1px;
    }
    .rating-input label {
        font-size: 1.6rem;
        color: #d1d5db;
        cursor: pointer;
        transition: color 0.15s ease;
    }
    .rating-input label:hover,
    .rating-input label:hover ~ label,
    .rating-input input:checked ~ label {
        color: #f59e0b;
    }
    .rating-input input:focus-visible + label {
        outline: 2px solid #15803d;
        border-radius: 4px;
    }
    .review-textarea {
        width: 100%;
        padding: 0.75rem 1rem;
        background: rgba(255, 255, 255, 0.85);
        border: 1px solid rgba(74, 222, 128, 0.5);
        border-radius: 12px;
        color: #1f2937;
        font-size: 0.95rem;
        font-family: inherit;
        resize: vertical;
        outline: none;
    }
    .review-textarea:focus {
        border-color: #15803d;
        background: #ffffff;
        box-shadow: 0 0 15px rgba(74, 222, 128, 0.4);
    }
</style>

<div class="container" style="padding-top: 1.5rem; padding-bottom: 3rem;">
    
    <div style="margin-bottom: 1.5rem;">
        <a href="index.php" class="hover-scale" style="background: rgba(255, 255, 255, 0.65); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); color: #064e3b; border: 1px solid rgba(74, 222, 128, 0.6); font-size: 0.85rem; padding: 0.55rem 1.1rem; border-radius: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; box-shadow: 0 4px 15px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.8);">
            <i class="fa-solid fa-arrow-left"></i> Back to Products
        </a>
    </div>

    <div class="smiski-card" style="border-radius: 24px; padding: 2.5rem; display: flex; gap: 3rem; flex-wrap: wrap;">

        <div style="flex: 1; min-width: 280px; display: flex; align-items: center; justify-content: center; min-height: 320px;">
            <div style="width: 280px; height: 280px; background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); <?= $imgContainerShape ?> display: flex; align-items: center; justify-content: center; overflow: hidden; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: inset 0 2px 5px rgba(255, 255, 255, 0.9), 0 10px 25px rgba(0, 0, 0, 0.1), 0 0 15px rgba(74, 222, 128, 0.3); padding: 1rem;">
                <?php if (!empty($product['image'])): ?>
                    <img src="../<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" style="width: 100%; height: 100%; object-fit: contain; transition: transform 0.35s ease;" onmouseenter="this.style.transform='scale(1.08)'" onmouseleave="this.style.transform='scale(1)'">
                <?php else: ?>
                    <i class="fa-solid fa-box-open" style="font-size: 5rem; color: #16a34a; opacity: 0.6;"></i>
                <?php endif; ?>
            </div>
        </div>

        <div style="flex: 2; min-width: 300px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <span style="background: rgba(22, 163, 74, 0.15); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); color: #15803d; font-size: 0.85rem; font-weight: 700; padding: 0.35rem 0.9rem; border-radius: 999px; display: inline-block; margin-bottom: 0.75rem; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: 0 0 8px rgba(74, 222, 128, 0.3);">
                    <?= htmlspecialchars($product['category_name'] ?? 'General') ?>
                </span>

                <h1 class="neon-glow-text" style="font-size: 2.1rem; font-weight: 700; margin-bottom: 0.75rem; line-height: 1.2;">
                    <?= htmlspecialchars($product['name']) ?>
                </h1>

                <a href="#reviews" style="display: inline-flex; align-items: center; gap: 0.45rem; margin-bottom: 0.75rem; text-decoration: none; font-size: 0.9rem; font-weight: 600; color: #166534;">
                    <?= renderStars($reviewStats['average']) ?>
                    <?= $reviewStats['count'] > 0 ? number_format($reviewStats['average'], 1) . ' (' . $reviewStats['count'] . ' review' . ($reviewStats['count'] === 1 ? '' : 's') . ')' : 'No reviews yet' ?>
                </a>
                <div style="font-size: 1.85rem; font-weight: 700; color: #15803d; margin-bottom: 1.25rem; text-shadow: 0 0 8px rgba(74, 222, 128, 0.6);">
                    ₱<?= number_format($product['price'], 2) ?>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <h4 class="neon-glow-text" style="font-size: 0.95rem; font-weight: 700; margin-bottom: 0.4rem;">Description</h4>
                    <p style="color: #1f2937; line-height: 1.6; font-size: 0.95rem; font-weight: 500;">
                        <?= nl2br(htmlspecialchars($product['description'] ?? '')) ?>
                    </p>
                </div>

                <div style="margin-bottom: 2rem;">
                    <h4 class="neon-glow-text" style="font-size: 0.95rem; font-weight: 700; margin-bottom: 0.4rem;">Stock Availability</h4>
                    <span style="font-weight: 700; font-size: 1.05rem; color: <?= $product['stock_quantity'] > 0 ? '#15803d' : '#dc2626' ?>; text-shadow: <?= $product['stock_quantity'] > 0 ? '0 0 6px rgba(74, 222, 128, 0.6)' : 'none' ?>;">
                        <?= $product['stock_quantity'] > 0 ? $product['stock_quantity'] . ' units available' : 'Out of Stock' ?>
                    </span>
                </div>
            </div>

            <?php if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()): ?>
                <button disabled class="btn" style="background: rgba(100, 116, 139, 0.15); backdrop-filter: blur(12px); color: #334155; border: 1px solid rgba(100, 116, 139, 0.3); cursor: not-allowed; padding: 0.85rem; width: 100%; border-radius: 14px; font-weight: 600;" title="Purchasing is disabled in Admin mode">
                    <i class="fa-solid fa-eye"></i> Admin Store Preview Mode (Purchasing Disabled)
                </button>
            <?php elseif ($product['stock_quantity'] > 0): ?>
                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                    <button type="button" onclick="addToCart(<?= $product['id'] ?>, '<?= htmlspecialchars(addslashes($product['name'])) ?>')" class="hover-scale btn" style="flex: 1; min-width: 160px; padding: 0.85rem; background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); color: #064e3b; border: 1px solid rgba(74, 222, 128, 0.7); border-radius: 14px; font-weight: 600; cursor: pointer; box-shadow: 0 6px 15px rgba(0,0,0,0.1), 0 0 10px rgba(74, 222, 128, 0.4);">
                        <i class="fa-solid fa-cart-plus"></i> Add To Cart
                    </button>
                    <a href="cart.php?action=buy_now&id=<?= (int)$product['id'] ?>&<?= csrfQuery() ?>" class="hover-scale btn" style="flex: 1; min-width: 160px; padding: 0.85rem; background: #16a34a; backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); color: white; border: 1px solid rgba(74, 222, 128, 0.8); border-radius: 14px; font-weight: 600; text-align: center; text-decoration: none; box-shadow: 0 6px 20px rgba(22, 163, 74, 0.4), 0 0 15px rgba(74, 222, 128, 0.5);">
                        <i class="fa-solid fa-bolt"></i> BUY NOW
                    </a>
                </div>
            <?php else: ?>
                <button disabled class="btn" style="background: rgba(239, 68, 68, 0.15); backdrop-filter: blur(12px); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.3); cursor: not-allowed; width: 100%; padding: 0.85rem; border-radius: 14px; font-weight: 600;">
                    Currently Out of Stock
                </button>
            <?php endif; ?>
        </div>

    </div>

    <div class="smiski-card" id="reviews" style="border-radius: 24px; padding: 2rem 2.5rem; margin-top: 2rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem; border-bottom: 1px solid rgba(74, 222, 128, 0.3); padding-bottom: 1rem;">
            <h2 class="neon-glow-text" style="font-size: 1.5rem; font-weight: 700;"><i class="fa-solid fa-star" style="color: #f59e0b;"></i> Customer Reviews</h2>
            <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                <?php if ($reviewStats['count'] > 0): ?>
                    <?= renderStars($reviewStats['average'], '1.15rem') ?>
                    <strong style="color: #064e3b; font-size: 1.05rem;"><?= number_format($reviewStats['average'], 1) ?> out of 5</strong>
                    <span style="color: #166534; font-size: 0.9rem; font-weight: 600;">(<?= $reviewStats['count'] ?> review<?= $reviewStats['count'] === 1 ? '' : 's' ?>)</span>
                <?php else: ?>
                    <span style="color: #166534; font-size: 0.95rem; font-weight: 600;">No reviews yet</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isReviewCustomer): ?>
            <?php if ($myReview): ?>
                <?php
                    $myStatus = !empty($myReview['deleted_at']) ? 'removed' : $myReview['status'];
                    $myStatusText = [
                        'pending' => 'Your review is awaiting approval and will appear here once it is approved.',
                        'approved' => 'Your review is published.',
                        'rejected' => 'Your review was not approved for publishing.',
                        'hidden' => 'Your review is currently hidden by the store.',
                        'removed' => 'Your review was removed by the store.',
                    ][$myStatus] ?? '';
                ?>
                <div style="background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(74, 222, 128, 0.5); border-radius: 16px; padding: 1.1rem 1.25rem; margin-bottom: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <strong style="color: #064e3b;">Your review</strong>
                        <span style="font-size: 0.8rem; font-weight: 700; color: #166534;"><?= htmlspecialchars(reviewStatusLabel($myReview)) ?></span>
                    </div>
                    <?= renderStars($myReview['rating']) ?>
                    <p style="color: #1f2937; font-size: 0.92rem; line-height: 1.6; margin: 0.5rem 0;"><?= nl2br(htmlspecialchars($myReview['review_text'])) ?></p>
                    <div style="font-size: 0.82rem; color: #4b5563;"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($myStatusText) ?></div>
                </div>
            <?php elseif ($canReview): ?>
                <form method="POST" action="<?= htmlspecialchars($productUrl) ?>#reviews" style="background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(74, 222, 128, 0.5); border-radius: 16px; padding: 1.25rem; margin-bottom: 1.5rem;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="submit_review">
                    <input type="hidden" name="form_token" value="<?= htmlspecialchars($reviewToken) ?>">
                    <h3 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.75rem;">Write a Review</h3>
                    <?= alertBox('error', $reviewFormError) ?>
                    <div style="margin-bottom: 0.9rem;">
                        <span style="display: block; font-size: 0.85rem; font-weight: 600; color: #166534; margin-bottom: 0.3rem;" id="ratingLabel">Your Rating</span>
                        <div class="rating-input" id="ratingGroup" role="radiogroup" aria-labelledby="ratingLabel" data-validate-hidden>
                            <?php for ($star = 5; $star >= 1; $star--): ?>
                                <input type="radio" id="rating<?= $star ?>" name="rating" value="<?= $star ?>" required data-error-target="#ratingGroup" data-msg-required="Please select a valid rating." <?= (string)($reviewOld['rating'] ?? '') === (string)$star ? 'checked' : '' ?>>
                                <label for="rating<?= $star ?>" title="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>"><i class="fa-solid fa-star"></i></label>
                            <?php endfor; ?>
                        </div>
                        <?= fieldError($reviewErrors, 'rating') ?>
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <label for="review_text" style="display: block; font-size: 0.85rem; font-weight: 600; color: #166534; margin-bottom: 0.3rem;">Your Review</label>
                        <textarea id="review_text" name="review_text" rows="4" required minlength="<?= REVIEW_MIN_LENGTH ?>" maxlength="<?= REVIEW_MAX_LENGTH ?>" data-counter data-msg-required="Please enter your review before submitting." placeholder="What did you like or dislike about this product?" class="review-textarea<?= invalidClass($reviewErrors, 'review_text') ?>"<?= invalidAttr($reviewErrors, 'review_text') ?>><?= oldValue($reviewOld, 'review_text') ?></textarea>
                        <?= fieldError($reviewErrors, 'review_text') ?>
                    </div>
                    <button type="submit" class="hover-scale btn" style="padding: 0.75rem 1.5rem; background: #16a34a; color: white; border: 1px solid rgba(74, 222, 128, 0.8); border-radius: 14px; font-weight: 600;">
                        <i class="fa-solid fa-paper-plane"></i> Submit Review
                    </button>
                </form>
            <?php else: ?>
                <div style="background: rgba(255, 255, 255, 0.5); border: 1px dashed rgba(74, 222, 128, 0.6); border-radius: 16px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: #166534; font-size: 0.9rem; font-weight: 600;">
                    <i class="fa-solid fa-circle-info"></i> You can write a review once an order containing this product has been delivered to you.
                </div>
            <?php endif; ?>
        <?php elseif (!isAdminLoggedIn()): ?>
            <div style="background: rgba(255, 255, 255, 0.5); border: 1px dashed rgba(74, 222, 128, 0.6); border-radius: 16px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: #166534; font-size: 0.9rem; font-weight: 600;">
                <i class="fa-solid fa-right-to-bracket"></i> <a href="login.php" style="color: #15803d; font-weight: 700;">Log in</a> to review products you have received.
            </div>
        <?php endif; ?>

        <?php if (empty($approvedReviews)): ?>
            <p style="color: #166534; font-size: 0.95rem; text-align: center; padding: 1.5rem 0;">Be the first to share your thoughts about this product.</p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php foreach ($approvedReviews as $review): ?>
                    <article style="background: rgba(255, 255, 255, 0.55); border: 1px solid rgba(74, 222, 128, 0.35); border-radius: 16px; padding: 1rem 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.35rem;">
                            <strong style="color: #064e3b;"><?= htmlspecialchars($review['full_name']) ?></strong>
                            <span style="font-size: 0.8rem; color: #4b5563;"><?= htmlspecialchars(date('M j, Y', strtotime($review['created_at']))) ?></span>
                        </div>
                        <?= renderStars($review['rating']) ?>
                        <p style="color: #1f2937; font-size: 0.92rem; line-height: 1.6; margin-top: 0.4rem;"><?= nl2br(htmlspecialchars($review['review_text'])) ?></p>
                    </article>
                <?php endforeach; ?>
                <?php if ($reviewStats['count'] > count($approvedReviews)): ?>
                    <p style="color: #166534; font-size: 0.85rem; text-align: center;">Showing the <?= count($approvedReviews) ?> most recent of <?= $reviewStats['count'] ?> reviews.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
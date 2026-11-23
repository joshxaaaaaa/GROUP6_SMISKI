<?php
require_once __DIR__ . '/../config/db.php';
$db = getDB();

// Kunin ang mga random na produkto para sa featured list
$stmt = $db->query("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.status = 'active' ORDER BY RAND() LIMIT 8");
$featuredProducts = $stmt->fetchAll();

// Kunin ang mga active categories at isabay ang pagkuha ng isang product image mula sa category na yon para sa Explore by Series
$catStmt = $db->query("
    SELECT c.*, 
           (SELECT p.image FROM products p WHERE p.category_id = c.id AND p.status = 'active' AND p.image != '' LIMIT 1) AS sample_image
    FROM categories c 
    WHERE c.status = 'active' 
    ORDER BY LOWER(c.name) ASC
");
$categories = $catStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
        background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
        min-height: 100vh;
    }
    
    /* iOS Frosted Glass White Card Effect */
    .smiski-card {
        background: rgba(255, 255, 255, 0.78) !important;
        backdrop-filter: blur(30px) saturate(180%);
        -webkit-backdrop-filter: blur(30px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.5) !important;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35), 0 0 20px rgba(134, 239, 172, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s ease, border-color 0.4s ease;
    }
    .smiski-card:hover {
        transform: translateY(-8px) scale(1.01);
        border-color: #86efac !important;
        background: rgba(255, 255, 255, 0.88) !important;
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.45), 0 0 35px rgba(134, 239, 172, 0.6), inset 0 0 15px rgba(134, 239, 172, 0.3) !important;
    }

    .hover-scale {
        transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .hover-scale:hover {
        transform: translateY(-5px);
        border-color: #86efac !important;
        box-shadow: 0 15px 30px rgba(0,0,0,0.3), 0 0 20px rgba(74, 222, 128, 0.5) !important;
    }

    /* Clean Image Zoom on Card Hover */
    .product-img {
        transition: transform 0.35s ease;
    }
    .smiski-card:hover .product-img {
        transform: scale(1.1);
    }

    /* Green Glow-in-the-Dark Text Effect for Glass White Backgrounds */
    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }

    /* CAROUSEL STYLES */
    .glass-carousel {
        position: relative;
        overflow: hidden;
        border-radius: 24px;
        margin-bottom: 2.5rem;
    }
    .carousel-track {
        display: flex;
        transition: transform 0.5s ease-in-out;
    }
    .carousel-slide {
        min-width: 100%;
        box-sizing: border-box;
        padding: 3.5rem 2rem;
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .carousel-dots {
        position: absolute;
        bottom: 1.25rem;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 8px;
        z-index: 10;
    }
    .dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: rgba(6, 78, 59, 0.3);
        border: 1px solid rgba(74, 222, 128, 0.8);
        cursor: pointer;
        transition: background 0.3s ease, transform 0.3s ease;
    }
    .dot.active {
        background: #16a34a;
        transform: scale(1.2);
        box-shadow: 0 0 8px rgba(74, 222, 128, 0.8);
    }
</style>

<div class="container" style="padding-top: 1.5rem; padding-bottom: 3rem;">

    <!-- IOS GLASS FROSTED CAROUSEL -->
    <div class="smiski-card glass-carousel">
        <div class="carousel-track" id="carouselTrack">
            
            <!-- Slide 1 -->
            <div class="carousel-slide">
                <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; margin-bottom: 1rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                    ✨ Featured Collection
                </span>
                <h1 class="neon-glow-text" style="font-size: 2.5rem; font-weight: 700; margin-bottom: 1rem;">Welcome to PROJECT SMISKI!</h1>
                <p style="color: #166534; font-size: 1.05rem; margin-bottom: 1.75rem; max-width: 600px; line-height: 1.5; font-weight: 500;">
                    Discover cute, quirky, and mysterious glow-in-the-dark figures. <b style="color: #15803d; text-shadow: 0 0 6px rgba(74, 222, 128, 0.7);">Now available in complete series sets—no duplicates!</b>
                </p>
                <a href="index.php" class="hover-scale" style="display: inline-block; background: #16a34a; color: #ffffff; padding: 0.75rem 1.75rem; border-radius: 12px; font-weight: 600; text-decoration: none; box-shadow: 0 6px 20px rgba(22, 163, 74, 0.4), 0 0 15px rgba(74, 222, 128, 0.5);">
                    <i class="fa-solid fa-store" style="margin-right: 0.5rem;"></i> Shop All Products
                </a>
            </div>

            <!-- Slide 2 -->
            <div class="carousel-slide">
                <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; margin-bottom: 1rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                    📦 No Blind Box Gambles
                </span>
                <h1 class="neon-glow-text" style="font-size: 2.5rem; font-weight: 700; margin-bottom: 1rem;">Complete Series Bundles</h1>
                <p style="color: #166534; font-size: 1.05rem; margin-bottom: 1.75rem; max-width: 600px; line-height: 1.5; font-weight: 500;">
                    Buy whole series sets directly and complete your room shelf decoration effortlessly without getting repeats.
                </p>
                <a href="index.php?category=<?= htmlspecialchars($categories[0]['id'] ?? 1) ?>" class="hover-scale" style="display: inline-block; background: #16a34a; color: #ffffff; padding: 0.75rem 1.75rem; border-radius: 12px; font-weight: 600; text-decoration: none; box-shadow: 0 6px 20px rgba(22, 163, 74, 0.4), 0 0 15px rgba(74, 222, 128, 0.5);">
                    <i class="fa-solid fa-layer-group" style="margin-right: 0.5rem;"></i> Browse Sets Now
                </a>
            </div>

            <!-- Slide 3 -->
            <div class="carousel-slide">
                <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; margin-bottom: 1rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                    💡 Glow-in-the-Dark Vibe
                </span>
                <h1 class="neon-glow-text" style="font-size: 2.5rem; font-weight: 700; margin-bottom: 1rem;">Luminous Room Companions</h1>
                <p style="color: #166534; font-size: 1.05rem; margin-bottom: 1.75rem; max-width: 600px; line-height: 1.5; font-weight: 500;">
                    They absorb light during the day and emit a soft, comforting green glow when you turn off the lights at night.
                </p>
                <a href="index.php" class="hover-scale" style="display: inline-block; background: #16a34a; color: #ffffff; padding: 0.75rem 1.75rem; border-radius: 12px; font-weight: 600; text-decoration: none; box-shadow: 0 6px 20px rgba(22, 163, 74, 0.4), 0 0 15px rgba(74, 222, 128, 0.5);">
                    <i class="fa-solid fa-lightbulb" style="margin-right: 0.5rem;"></i> Explore Catalog
                </a>
            </div>

        </div>

        <!-- Carousel Navigation Dots -->
        <div class="carousel-dots" id="carouselDots">
            <span class="dot active" onclick="currentSlide(0)"></span>
            <span class="dot" onclick="currentSlide(1)"></span>
            <span class="dot" onclick="currentSlide(2)"></span>
        </div>
    </div>

    <!-- SCRIPT PARA SA CAROUSEL AUTO-SLIDE -->
    <script>
        let slideIndex = 0;
        const slides = document.querySelectorAll('.carousel-slide');
        const dots = document.querySelectorAll('.dot');
        const track = document.getElementById('carouselTrack');
        let slideInterval;

        function updateCarousel() {
            track.style.transform = `translateX(-${slideIndex * 100}%)`;
            dots.forEach((dot, index) => {
                dot.classList.toggle('active', index === slideIndex);
            });
        }

        function nextSlide() {
            slideIndex = (slideIndex + 1) % slides.length;
            updateCarousel();
        }

        function currentSlide(index) {
            slideIndex = index;
            updateCarousel();
            resetTimer();
        }

        function resetTimer() {
            clearInterval(slideInterval);
            slideInterval = setInterval(nextSlide, 5000);
        }

        slideInterval = setInterval(nextSlide, 5000);
    </script>

    <!-- 1. "WHY COLLECT SMISKIS?" FEATURE HIGHLIGHTS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
        
        <!-- Feature 1 -->
        <div class="smiski-card hover-scale" style="border-radius: 20px; padding: 1.75rem; text-align: center;">
            <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; border: 1px solid rgba(74, 222, 128, 0.6); box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);">
                <i class="fa-solid fa-lightbulb" style="color: #15803d; font-size: 1.25rem; filter: drop-shadow(0 0 6px rgba(74, 222, 128, 0.8));"></i>
            </div>
            <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem;">Glow-in-the-Dark</h4>
            <p style="color: #1f2937; font-size: 0.85rem; line-height: 1.4; margin: 0; font-weight: 500;">They absorb light and emit a soft, mysterious green glow in your dark room.</p>
        </div>

        <!-- Feature 2 -->
        <div class="smiski-card hover-scale" style="border-radius: 20px; padding: 1.75rem; text-align: center;">
            <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; border: 1px solid rgba(74, 222, 128, 0.6); box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);">
                <i class="fa-solid fa-box-archive" style="color: #15803d; font-size: 1.25rem; filter: drop-shadow(0 0 6px rgba(74, 222, 128, 0.8));"></i>
            </div>
            <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem;">Complete Set Available</h4>
            <p style="color: #1f2937; font-size: 0.85rem; line-height: 1.4; margin: 0; font-weight: 500;">Buy whole series bundles directly. No blind box gambling and zero duplicates!</p>
        </div>

        <!-- Feature 3 -->
        <div class="smiski-card hover-scale" style="border-radius: 20px; padding: 1.75rem; text-align: center;">
            <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; border: 1px solid rgba(74, 222, 128, 0.6); box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);">
                <i class="fa-solid fa-face-smile" style="color: #15803d; font-size: 1.25rem; filter: drop-shadow(0 0 6px rgba(74, 222, 128, 0.8));"></i>
            </div>
            <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem;">Cute & Quirky</h4>
            <p style="color: #1f2937; font-size: 0.85rem; line-height: 1.4; margin: 0; font-weight: 500;">Designed hiding in corners, shelves, and small spaces to keep you company.</p>
        </div>

    </div>

    <!-- 2. NO-BLIND-BOX HIGHLIGHT BANNER -->
    <div class="smiski-card hover-scale" style="border-radius: 20px; padding: 1.75rem 2rem; margin-bottom: 2.5rem; display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;">
        <div style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); padding: 1rem; border-radius: 50%; border: 1px solid rgba(74, 222, 128, 0.6); display: flex; align-items: center; justify-content: center; min-width: 60px; height: 60px; box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);">
            <i class="fa-solid fa-layer-group" style="color: #15803d; font-size: 1.5rem; filter: drop-shadow(0 0 6px rgba(74, 222, 128, 0.8));"></i>
        </div>
        <div style="flex: 1; min-width: 250px;">
            <h4 class="neon-glow-text" style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.3rem;">Want to complete your collection effortlessly?</h4>
            <p style="color: #1f2937; font-size: 0.9rem; margin: 0; line-height: 1.4; font-weight: 500;">
                You can now purchase the complete series directly as a set! Skip the blind box gamble and say goodbye to duplicate figures in your room collection.
            </p>
        </div>
        <a href="index.php?category=<?= htmlspecialchars($categories[0]['id'] ?? 1) ?>" style="background: #16a34a; color: #fff; border: 1px solid rgba(74, 222, 128, 0.6); padding: 0.65rem 1.25rem; border-radius: 12px; font-weight: 600; font-size: 0.85rem; text-decoration: none; white-space: nowrap; box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);">
            Browse Sets &rarr;
        </a>
    </div>

    <!-- 3. EXPLORE BY SERIES -->
    <?php if (!empty($categories)): ?>
    <div class="smiski-card" style="border-radius: 22px; padding: 1.75rem; margin-bottom: 2.5rem;">
        <div style="margin-bottom: 1.25rem;">
            <h3 class="neon-glow-text" style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.2rem;">Explore by Series</h3>
            <p style="font-size: 0.85rem; color: #1f2937; margin: 0; font-weight: 600;">Choose a series and purchase the complete set directly</p>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1.25rem;">
            <?php foreach ($categories as $cat): ?>
                <a href="index.php?category=<?= htmlspecialchars($cat['id']) ?>" class="hover-scale" style="background: rgba(255, 255, 255, 0.65); backdrop-filter: blur(15px); border: 1px solid rgba(255, 255, 255, 0.6); border-radius: 16px; padding: 1.25rem 1rem; text-align: center; text-decoration: none; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.75rem; box-shadow: 0 8px 20px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.8);">
                    
                    <!-- Image Container para sa Series Thumbnail -->
                    <div style="width: 110px; height: 110px; background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(10px); border-radius: 14px; display: flex; align-items: center; justify-content: center; overflow: hidden; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: inset 0 2px 5px rgba(255,255,255,0.8), 0 0 10px rgba(74, 222, 128, 0.25); padding: 0.5rem;">
                        <?php if (!empty($cat['sample_image'])): ?>
                            <img src="../<?= htmlspecialchars($cat['sample_image']) ?>" alt="<?= htmlspecialchars($cat['name']) ?>" class="product-img" style="width: 100%; height: 100%; object-fit: contain;">
                        <?php else: ?>
                            <i class="fa-solid fa-box" style="font-size: 2.2rem; color: #16a34a;"></i>
                        <?php endif; ?>
                    </div>

                    <div>
                        <h5 class="neon-glow-text" style="font-size: 0.95rem; font-weight: 700; margin: 0 0 0.2rem;"><?= htmlspecialchars($cat['name']) ?></h5>
                        <span style="font-size: 0.75rem; color: #15803d; font-weight: 700; text-shadow: 0 0 5px rgba(74, 222, 128, 0.4);">Complete Set Available</span>
                    </div>

                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- FEATURED PRODUCTS SECTION HEADER -->
    <div class="smiski-card" style="border-radius: 22px; padding: 1.5rem 1.75rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h3 class="neon-glow-text" style="font-size: 1.5rem; font-weight: 700; margin: 0 0 0.2rem;">Featured Smiskis</h3>
            <p style="font-size: 0.85rem; color: #1f2937; margin: 0; font-weight: 600;">Handpicked quirky glow-in-the-dark figures for your collection</p>
        </div>
        <a href="index.php" class="hover-scale" style="background: #16a34a; color: #fff; text-decoration: none; font-weight: 600; font-size: 0.85rem; padding: 0.55rem 1rem; border-radius: 10px; border: 1px solid rgba(74, 222, 128, 0.6); box-shadow: 0 4px 10px rgba(22, 163, 74, 0.3);">View All Catalog &rarr;</a>
    </div>

    <!-- FEATURED PRODUCTS GRID -->
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1.5rem;">
        <?php foreach ($featuredProducts as $product): ?>
            <?php 
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
            ?>
            <!-- iOS Glass Card with Neon Hover -->
            <div class="smiski-card" style="border-radius: 22px; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden;">
                
                <a href="product.php?id=<?= htmlspecialchars($product['id']) ?>" style="display: flex; align-items: center; justify-content: center; width: 100%; height: 180px; background: rgba(255, 255, 255, 0.45); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255, 255, 255, 0.4); padding: 1rem;">
                    <div style="width: 130px; height: 130px; background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(15px); <?= $imgContainerShape ?> display: flex; align-items: center; justify-content: center; overflow: hidden; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08), inset 0 2px 5px rgba(255, 255, 255, 0.9), 0 0 12px rgba(74, 222, 128, 0.25); padding: 0.75rem;">
                        
                        <?php if (!empty($product['image'])): ?>
                            <img src="../<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="product-img" style="width: 100%; height: 100%; object-fit: contain;">
                        <?php else: ?>
                            <i class="fa-solid fa-box-open" style="font-size: 2.5rem; color: #16a34a;"></i>
                        <?php endif; ?>

                    </div>
                </a>

                <div style="padding: 1.35rem; flex: 1; display: flex; flex-direction: column;">
                    <span style="background: rgba(22, 163, 74, 0.15); backdrop-filter: blur(10px); color: #15803d; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.7rem; border-radius: 10px; display: inline-block; margin-bottom: 0.6rem; width: fit-content; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: 0 0 6px rgba(74, 222, 128, 0.3);">
                        <?= htmlspecialchars($product['category_name'] ?? 'General') ?>
                    </span>

                    <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.4rem; line-height: 1.3;">
                        <?= htmlspecialchars($product['name']) ?>
                    </h4>

                    <div style="margin-top: auto;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.85rem;">
                            <span style="font-size: 1.25rem; font-weight: 700; color: #15803d; text-shadow: 0 0 8px rgba(74, 222, 128, 0.6);">
                                ₱<?= number_format($product['price'], 2) ?>
                            </span>
                        </div>

                        <a href="product.php?id=<?= htmlspecialchars($product['id']) ?>" class="hover-scale btn" style="display: block; width: 100%; padding: 0.55rem; font-size: 0.85rem; background: #16a34a; color: #fff; border: 1px solid rgba(74, 222, 128, 0.6); text-align: center; text-decoration: none; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3), 0 0 10px rgba(74, 222, 128, 0.4);">
                            View Details
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
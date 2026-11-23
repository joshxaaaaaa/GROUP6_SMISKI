<?php
require_once __DIR__ . '/../config/db.php';
$db = getDB();

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

    /* Green Glow-in-the-Dark Text Effect for Glass White Backgrounds */
    .neon-glow-text {
        color: #064e3b !important;
        text-shadow: 0 0 8px rgba(74, 222, 128, 0.8), 0 0 15px rgba(74, 222, 128, 0.5), 0 0 25px rgba(34, 197, 94, 0.3);
    }
</style>

<div class="container" style="padding-top: 1.5rem; padding-bottom: 3rem;">
    
    <!-- HERO BANNER -->
    <div class="smiski-card" style="border-radius: 24px; padding: 3.5rem 2rem; text-align: center; margin-bottom: 2.5rem;">
        <img src="../assets/ecommerce-logo.png" alt="Project Smiski Logo" style="width: 72px; height: 72px; object-fit: contain; margin-bottom: 1rem; filter: drop-shadow(0 0 8px rgba(22, 101, 52, 0.3));">
        <h1 class="neon-glow-text" style="font-size: 2.5rem; font-weight: 700; margin-bottom: 0.75rem;">About PROJECT SMISKI</h1>
        <p style="color: #166534; font-size: 1.05rem; max-width: 650px; margin: 0 auto; line-height: 1.6; font-weight: 500;">
            Bringing the quiet charm of curious little figures into your everyday spaces, one glow-in-the-dark surprise at a time.
        </p>
    </div>

    <!-- OUR STORY & SMISKI PHILOSOPHY -->
    <section class="smiski-card" style="border-radius: 22px; padding: 2.5rem; margin-bottom: 2.5rem;">
        <div style="display: flex; gap: 2rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 280px;">
                <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 1rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                    <i class="fa-solid fa-book-open"></i> OUR STORY & PHILOSOPHY
                </span>
                <h2 class="neon-glow-text" style="font-size: 1.8rem; font-weight: 700; margin-bottom: 1rem;">
                    Finding Joy in the Little Corners
                </h2>
                <p style="color: #1f2937; line-height: 1.7; font-size: 0.95rem; margin-bottom: 1rem; font-weight: 500;">
                    Project Smiski began as a personal fascination with the whimsical world of companion figures. Founded out of a deep love for things that peek out from bookshelves, desk corners, and potted plants, our store was created to be a safe haven for fellow collectors.
                </p>
                <p style="color: #1f2937; line-height: 1.7; font-size: 0.95rem; font-weight: 500;">
                    <strong class="neon-glow-text">The Smiski Philosophy:</strong> We believe that even in the quietest or darkest corners of life, there's always a little light and joy to be found. These phosphorescent creatures bring a subtle sense of comfort and magic to busy, modern spaces.
                </p>
            </div>
            <div style="flex: 1; min-width: 260px; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(15px); border-radius: 16px; padding: 2.5rem; text-align: center; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: inset 0 2px 5px rgba(255, 255, 255, 0.9), 0 0 12px rgba(74, 222, 128, 0.25);">
                <i class="fa-solid fa-store" style="font-size: 4rem; color: #15803d; margin-bottom: 1rem; filter: drop-shadow(0 0 8px rgba(74, 222, 128, 0.8));"></i>
                <h3 class="neon-glow-text" style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">The Smiski Sanctuary</h3>
                <p style="color: #1f2937; font-size: 0.9rem; line-height: 1.5; font-weight: 500;">
                    Curated with care for collectors who appreciate the quiet, quirky moments of everyday life.
                </p>
            </div>
        </div>
    </section>

    <!-- WHY COLLECT WITH US -->
    <section class="smiski-card" style="border-radius: 22px; padding: 2.5rem; margin-bottom: 2.5rem;">
        <div style="text-align: center; margin-bottom: 2rem;">
            <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.5rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                <i class="fa-solid fa-check-to-slot"></i> WHY CHOOSE US
            </span>
            <h2 class="neon-glow-text" style="font-size: 1.8rem; font-weight: 700;">Why Collect With Us?</h2>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem;">
            <div style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); padding: 1.5rem; border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div style="color: #15803d; font-size: 1.5rem; margin-bottom: 0.75rem;"><i class="fa-solid fa-shield-check"></i></div>
                <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem;">100% Authentic</h4>
                <p style="color: #1f2937; font-size: 0.85rem; line-height: 1.5; font-weight: 500;">Guaranteed original and top-quality figures delivered straight to your doorstep.</p>
            </div>
            <div style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); padding: 1.5rem; border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div style="color: #15803d; font-size: 1.5rem; margin-bottom: 0.75rem;"><i class="fa-solid fa-layer-group"></i></div>
                <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem;">Direct Collection</h4>
                <p style="color: #1f2937; font-size: 0.85rem; line-height: 1.5; font-weight: 500;">Complete and select your desired figures directly without relying entirely on blind box luck.</p>
            </div>
            <div style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); padding: 1.5rem; border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div style="color: #15803d; font-size: 1.5rem; margin-bottom: 0.75rem;"><i class="fa-solid fa-box-open"></i></div>
                <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem;">Collector-Safe Packaging</h4>
                <p style="color: #1f2937; font-size: 0.85rem; line-height: 1.5; font-weight: 500;">Packed carefully with protective layers to secure boxes and items against dents or damage.</p>
            </div>
            <div style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); padding: 1.5rem; border-radius: 16px; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div style="color: #15803d; font-size: 1.5rem; margin-bottom: 0.75rem;"><i class="fa-solid fa-comments"></i></div>
                <h4 class="neon-glow-text" style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem;">Collector Friendly</h4>
                <p style="color: #1f2937; font-size: 0.85rem; line-height: 1.5; font-weight: 500;">Reliable and approachable customer support ready to assist with series questions and updates.</p>
            </div>
        </div>
    </section>

    <!-- PURPOSE & PRINCIPLES -->
    <section style="margin-bottom: 2.5rem;">
        <div style="text-align: center; margin-bottom: 2rem;">
            <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.5rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                <i class="fa-solid fa-compass"></i> PURPOSE & PRINCIPLES
            </span>
            <h2 class="neon-glow-text" style="font-size: 1.8rem; font-weight: 700;">Mission & Core Values</h2>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
            
            <!-- Mission -->
            <div class="smiski-card hover-scale" style="border-radius: 20px; padding: 2rem;">
                <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 1.25rem; border: 1px solid rgba(74, 222, 128, 0.6); box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);">
                    <i class="fa-solid fa-bullseye" style="color: #15803d;"></i>
                </div>
                <h3 class="neon-glow-text" style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.6rem;">Our Mission</h3>
                <p style="color: #1f2937; font-size: 0.9rem; line-height: 1.6; font-weight: 500;">
                    To spread joy and spark imagination by providing accessible, authentic collectible figures that turn ordinary spaces into magical hiding spots.
                </p>
            </div>

            <!-- Customer First -->
            <div class="smiski-card hover-scale" style="border-radius: 20px; padding: 2rem;">
                <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 1.25rem; border: 1px solid rgba(74, 222, 128, 0.6); box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);">
                    <i class="fa-solid fa-heart" style="color: #15803d;"></i>
                </div>
                <h3 class="neon-glow-text" style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.6rem;">Customer First</h3>
                <p style="color: #1f2937; font-size: 0.9rem; line-height: 1.6; font-weight: 500;">
                    We treat every order with tender loving care, ensuring secure packaging and a delightful buying experience for fellow collectors.
                </p>
            </div>

            <!-- Transparency & Trust -->
            <div class="smiski-card hover-scale" style="border-radius: 20px; padding: 2rem;">
                <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 1.25rem; border: 1px solid rgba(74, 222, 128, 0.6); box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);">
                    <i class="fa-solid fa-shield-halved" style="color: #15803d;"></i>
                </div>
                <h3 class="neon-glow-text" style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.6rem;">Transparency & Trust</h3>
                <p style="color: #1f2937; font-size: 0.9rem; line-height: 1.6; font-weight: 500;">
                    Guaranteed authentic figures and honest service. No hidden gimmicks—just pure collecting passion you can rely on.
                </p>
            </div>

            <!-- Continuous Innovation -->
            <div class="smiski-card hover-scale" style="border-radius: 20px; padding: 2rem;">
                <div style="width: 50px; height: 50px; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 1.25rem; border: 1px solid rgba(74, 222, 128, 0.6); box-shadow: 0 0 12px rgba(74, 222, 128, 0.4);">
                    <i class="fa-solid fa-bolt" style="color: #15803d;"></i>
                </div>
                <h3 class="neon-glow-text" style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.6rem;">Continuous Innovation</h3>
                <p style="color: #1f2937; font-size: 0.9rem; line-height: 1.6; font-weight: 500;">
                    Constantly finding new ways to improve our platform, expand our catalog, and connect with a growing community of enthusiasts.
                </p>
            </div>
        </div>
    </section>

    <!-- LEADERSHIP SECTION -->
    <section class="smiski-card" style="border-radius: 22px; padding: 2.5rem;">
        <div style="text-align: center; margin-bottom: 2rem;">
            <span style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(10px); color: #064e3b; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.8rem; border-radius: 20px; display: inline-block; margin-bottom: 0.5rem; border: 1px solid rgba(74,222,128,0.6); box-shadow: 0 0 10px rgba(74,222,128,0.4);">
                <i class="fa-solid fa-user-tie"></i> LEADERSHIP & FOUNDER
            </span>
            <h2 class="neon-glow-text" style="font-size: 1.8rem; font-weight: 700;">Meet the Mind Behind Project Smiski</h2>
        </div>

        <div style="max-width: 650px; margin: 0 auto; background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(15px); border-radius: 16px; padding: 2.5rem; text-align: center; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: inset 0 2px 5px rgba(255, 255, 255, 0.9), 0 0 12px rgba(74, 222, 128, 0.25);">
            
            <div style="width: 110px; height: 110px; border-radius: 50%; overflow: hidden; margin: 0 auto 1.25rem auto; box-shadow: 0 0 15px rgba(74, 222, 128, 0.5); border: 3px solid #16a34a; background: #ffffff;">
                <img src="../assets/owner.jpg" alt="Anajoy Niones Abila" style="width: 100%; height: 100%; object-fit: cover;">
            </div>

            <h3 class="neon-glow-text" style="font-size: 1.4rem; font-weight: 700; margin-bottom: 0.25rem;">Anajoy Niones Abila</h3>
            <p style="color: #15803d; font-weight: 600; font-size: 0.9rem; margin-bottom: 1.25rem; text-shadow: 0 0 5px rgba(74, 222, 128, 0.4);">Founder & Chief Collector</p>
            
            <p style="color: #1f2937; font-size: 0.95rem; line-height: 1.7; margin-bottom: 1rem; font-weight: 500;">
                "Project Smiski was born from a genuine passion for collecting and the simple happiness of spotting a tiny figure glowing softly in the dark. As an avid collector herself, Anajoy understands the thrill of the hunt and the joy of completing a set."
            </p>

            <p style="color: #1f2937; font-size: 0.95rem; line-height: 1.7; margin-bottom: 1.5rem; font-weight: 500;">
                Her vision is to build a trusted, safe, and welcoming community where fellow enthusiasts can easily access 100% authentic figures without the worries of counterfeits or complicated sourcing.
            </p>

            <!-- Mini Info Badges -->
            <div style="display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
                <span style="background: rgba(255, 255, 255, 0.8); padding: 0.4rem 0.8rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; color: #166534; border: 1px solid rgba(74,222,128,0.4);">
                    <i class="fa-solid fa-heart"></i> Favorite Series: Smiski Toilet & Museum
                </span>
                <span style="background: rgba(255, 255, 255, 0.8); padding: 0.4rem 0.8rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; color: #166534; border: 1px solid rgba(74,222,128,0.4);">
                    <i class="fa-solid fa-shield-check"></i> Quality Assurance Lead
                </span>
            </div>

            <div style="display: inline-block; background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(10px); color: #15803d; padding: 0.4rem 1.2rem; border-radius: 999px; font-size: 0.85rem; font-weight: 600; border: 1px solid rgba(74, 222, 128, 0.5); box-shadow: 0 0 8px rgba(74, 222, 128, 0.3);">
                <i class="fa-solid fa-sparkles"></i> "Spreading glow-in-the-dark happiness, one corner at a time."
            </div>
        </div>
    </section>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

DROP DATABASE IF EXISTS ecommerce_activity_db;
CREATE DATABASE ecommerce_activity_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE ecommerce_activity_db;

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    role ENUM('admin', 'customer') DEFAULT 'customer',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category_id INT,
    price DECIMAL(10,2) NOT NULL,
    stock_quantity INT DEFAULT 0,
    image VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    delivery_address TEXT,
    payment_method VARCHAR(50) DEFAULT 'cod',
    payment_receipt VARCHAR(255) NULL,
    payment_status VARCHAR(50) DEFAULT 'unpaid',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_type VARCHAR(20) DEFAULT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0,
    shipping_cost DECIMAL(10,2) DEFAULT 0,
    tax_amount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_customer (customer_id),
    INDEX idx_order_number (order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    admin_reply TEXT NULL,
    replied_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_is_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER tr_orders_before_insert
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    IF NEW.order_number IS NULL OR NEW.order_number = '' THEN
        SET NEW.order_number = CONCAT('ORD-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(FLOOR(RAND() * 9999), 4, '0'));
    END IF;
END //

DELIMITER ;

-- Insert Default Users
INSERT INTO users (full_name, email, password, phone, role, status) VALUES
('Administrator', 'admin@admin.com', 'admin123', '09123456789', 'admin', 'active'),
('System Admin', 'admin@ecommerce.com', 'admin123', '09123456788', 'admin', 'active');

-- Insert 18 Categories
INSERT INTO categories (id, name, status) VALUES
(1, 'Construction Series', 'active'),
(2, 'Birthday Series', 'active'),
(3, 'Smiski Sunday', 'active'),
(4, 'Smiski Hippers', 'active'),
(5, 'Moving Series', 'active'),
(6, 'Exercising Series', 'active'),
(7, 'Dressing Series', 'active'),
(8, '@Work Series', 'active'),
(9, 'Museum Series', 'active'),
(10, 'Bed Series', 'active'),
(11, 'Living Series', 'active'),
(12, 'Bath Series', 'active'),
(13, 'Toilet Series', 'active'),
(14, 'Series 4', 'active'),
(15, 'Series 3', 'active'),
(16, 'Series 2', 'active'),
(17, 'Series 1', 'active'),
(18, 'Bundle Series', 'active');

-- Insert 108 Individual Products (18 Categories * 6 Items per Category)
INSERT INTO products (name, description, category_id, price, stock_quantity, status) VALUES

-- 1. Construction Series
('SMISKI Digging a Hole', 'SMISKI digs holes anywhere. Is it really okay to dig a hole there?', 1, 499.00, 20, 'active'),
('SMISKI Crushing Rock', 'SMISKI can break through hard ground. They are powerful. Can they make it to the other corner?', 1, 499.00, 20, 'active'),
('SMISKI Tightening Bolt', 'SMISKI tightens bolts. A diligent and anxious worker, they must make everything strong.', 1, 499.00, 20, 'active'),
('SMISKI Measuring', 'SMISKI is looking for a corner that is just the right size. This corner might be a little too narrow.', 1, 499.00, 20, 'active'),
('SMISKI Directing Traffic', 'SMISKI watches over the safety of the construction site. Please proceed with caution.', 1, 499.00, 20, 'active'),
('SMISKI Secretly Removes Bolt', 'They love mischief. Do your job properly.', 1, 499.00, 20, 'active'),

-- 2. Birthday Series
('SMISKI Giving a Bouquet', 'SMISKI is giving a flower bouquet. They are a bit of a cheesy romantic.', 2, 499.00, 20, 'active'),
('SMISKI Wrapped Up', 'SMISKI pretends to be a present. Does anyone want a gift?', 2, 499.00, 20, 'active'),
('SMISKI Popping Confetti', 'SMISKI is popping a confetti cannon. SMISKI is playful and loves to party.', 2, 499.00, 20, 'active'),
('SMISKI Birthday Message', 'SMISKI carries a birthday message conversation heart. They are shy and need a little help communicating!', 2, 499.00, 20, 'active'),
('Little SMISKI Decorating', 'Little SMISKI work together to decorate a birthday party. Where should they hang the banner?', 2, 499.00, 20, 'active'),
('SMISKI Tasting', 'SMISKI is tasting a birthday cake. Oh no... they are getting full and cannot eat any more.', 2, 499.00, 20, 'active'),

-- 3. Smiski Sunday
('SMISKI Blowing Bubbles', 'SMISKI is playing with soap and wants to fill the corner with bubbles!', 3, 499.00, 20, 'active'),
('SMISKI Paper Airplane', 'SMISKI flies paper airplanes and dreams of travelling far away someday.', 3, 499.00, 20, 'active'),
('SMISKI Sunbathing', 'SMISKI is taking a warm nap in the sun with Cat SMISKI sleeping on their tummy.', 3, 499.00, 20, 'active'),
('SMISKI Sing-Along', 'SMISKI sings while playing the guitar! SMISKI is spellbound by SMISKI is own song in the corner.', 3, 499.00, 20, 'active'),
('SMISKI Skateboarding', 'SMISKI is so cool riding a skateboard! Where should SMISKI go play today?', 3, 499.00, 20, 'active'),
('SMISKI Gardening', 'SMISKI loves flowers!! Did a sprout grow from SMISKI is head because they watered the plants too much?', 3, 499.00, 20, 'active'),

-- 4. Smiski Hippers
('SMISKI on His Smartphone', 'SMISKI cannot let go of his phone at any time.', 4, 599.00, 20, 'active'),
('SMISKI Trying to Climb', 'SMISKI wants to climb the wall but cannot.', 4, 599.00, 20, 'active'),
('SMISKI Looking Out', 'SMISKI looks into the distance. What do you see...?', 4, 599.00, 20, 'active'),
('SMISKI Sticking', 'SMISKI is stuck to the wall and trying hard not to slip down.', 4, 599.00, 20, 'active'),
('SMISKI Dozing', 'SMISKI dozes off on anything.', 4, 599.00, 20, 'active'),
('SMISKI Upside Down', 'SMISKI loves thrills. Be careful not to fall off.', 4, 599.00, 20, 'active'),

-- 5. Moving Series
('SMISKI Carrying Ladder', 'SMISKI is carrying a ladder. It is time to get to work.', 5, 499.00, 20, 'active'),
('SMISKI Balancing Boxes', 'SMISKI is carefully balancing packages. It is difficult to see what is in front of him.', 5, 499.00, 20, 'active'),
('SMISKI Decorating', 'SMISKI is holding his favorite lamp. How will he decorate his room?', 5, 499.00, 20, 'active'),
('Little SMISKI Teamwork', 'Little SMISKI need to move a large box. Can they work together to get the job done?', 5, 499.00, 20, 'active'),
('Green Thumb SMISKI', 'SMISKI is carrying a cactus. This plant will add life to his new corner!', 5, 499.00, 20, 'active'),
('SMISKI Falling Down', 'SMISKI trips and falls with his package. Is the package damaged?', 5, 499.00, 20, 'active'),

-- 6. Exercising Series
('SMISKI Doing Crunches', 'SMISKI is working his abs. Do you think his muffin top is cute?', 6, 499.00, 20, 'active'),
('SMISKI Aerobics', 'SMISKI is doing aerobics. Let is work out! One two, One two...', 6, 499.00, 20, 'active'),
('Little SMISKI Balance', 'Little SMISKI is on a balance ball. Do you want to train your core?', 6, 499.00, 20, 'active'),
('SMISKI Dumbbell', 'SMISKI is doing weight training. It is too heavy to lift!', 6, 499.00, 20, 'active'),
('SMISKI Hoop', 'SMISKI hooping. SMISKI is having fun and breaking a sweat.', 6, 499.00, 20, 'active'),
('SMISKI Stretch', 'SMISKI is doing stretches. Stretching is very important before exercise!', 6, 499.00, 20, 'active'),

-- 7. Dressing Series
('SMISKI Underpants', 'SMISKI putting on underpants. He is embarrassed, so he is sneaking around so he cannot be seen.', 7, 499.00, 20, 'active'),
('SMISKI Struggling', 'A sad SMISKI who is trying on a sweater but cannot get his head through.', 7, 499.00, 20, 'active'),
('SMISKI Loose Pants', 'SMISKI is at a loss because his pants are too big.', 7, 499.00, 20, 'active'),
('SMISKI Putting On Socks', 'SMISKI is putting on socks. He cannot wear them well because he is wobbling standing on one leg.', 7, 499.00, 20, 'active'),
('SMISKI Sweater', 'SMISKI is trying on a sweater. Will it fit?', 7, 499.00, 20, 'active'),
('SMISKI Tight Pants', 'SMISKI is trying on tight pants. He is desperately trying to get them on!', 7, 499.00, 20, 'active'),

-- 8. @Work Series
('SMISKI Approving', 'SMISKI praises your work from the corner. He is good at complimenting everything.', 8, 499.00, 20, 'active'),
('SMISKI Researching', 'SMISKI is searching for something on his computer. He has trouble hitting the keys.', 8, 499.00, 20, 'active'),
('SMISKI Presenting', 'SMISKI is presenting. He likes to explain to the group but he is always nervous.', 8, 499.00, 20, 'active'),
('SMISKI Good Idea', 'SMISKI is inspired. The light bulb means he has a good idea.', 8, 499.00, 20, 'active'),
('SMISKI On the Road', 'SMISKI hurries to work. He pretends to be an executive although he is often late.', 8, 499.00, 20, 'active'),
('Little SMISKI Group Think', 'SMISKI are deliberating in the meeting. They think that three heads are better than one!', 8, 499.00, 20, 'active'),

-- 9. Museum Series
('Smiski The Source', 'Inspired by classic art depicting the origin of life, lying down in an analytical pose.', 9, 499.00, 20, 'active'),
('Smiski Fuzin&Raijin', 'Bringing the legendary Japanese wind and thunder gods to life in a cute, glowing Smiski style.', 9, 499.00, 20, 'active'),
('Smiski Bacchus', 'Inspired by the god of wine and merrymaking, sitting relaxed with grape-like details.', 9, 499.00, 20, 'active'),
('Smiski Vela’zquez', 'Taking cues from Diego Velázquez is classic portrait style, holding a tiny art frame or palette.', 9, 499.00, 20, 'active'),
('Smiski Dali', 'Styled after the surrealist artist Salvador Dalí, featuring distinct imagery echoing his famous art.', 9, 499.00, 20, 'active'),
('Smiski Pearl Earring', 'Inspired by Johannes Vermeer is Girl with a Pearl Earring, wearing a pearl-like accessory while glancing sideways.', 9, 499.00, 20, 'active'),

-- 10. Bed Series
('SMISKI before rest', 'Stretching out and getting ready to rest after a long day.', 10, 499.00, 20, 'active'),
('SMISKI Sleepy', 'Getting drowsy and struggling to keep heavy eyes open near the bed.', 10, 499.00, 20, 'active'),
('SMISKI co-sleeping', 'Cuddled up closely and sleeping side by side with a buddy for a sound sleep.', 10, 499.00, 20, 'active'),
('SMISKI reading', 'Flipping through a favorite book right before sleep overtakes them.', 10, 499.00, 20, 'active'),
('SMISKI at sleep', 'Fast asleep and peacefully resting without a single care in the world.', 10, 499.00, 20, 'active'),
('SMISKI fussing', 'Restless and tossing in bed because they are having trouble falling asleep.', 10, 499.00, 20, 'active'),

-- 11. Living Series
('Smiski Daydreaming', 'Lost in thought with their mind wandering in the middle of the living room.', 11, 499.00, 20, 'active'),
('Smiski Playing', 'Joyfully occupied and playing with a tiny object inside the room.', 11, 499.00, 20, 'active'),
('Smiski Hiding', 'Expertly tucked away in a corner or under furniture so they will not be spotted.', 11, 499.00, 20, 'active'),
('Smiski Nap Time', 'Chilling and squeezing in a quick afternoon snooze in the living room.', 11, 499.00, 20, 'active'),
('Smiski Thinking', 'Resting their chin on their hand, deeply in thought while observing their corner.', 11, 499.00, 20, 'active'),
('Smiski Lifting', 'Pretending to lift something heavy even though they are just wrestling with a tiny object.', 11, 499.00, 20, 'active'),

-- 12. Bath Series
('Smiski Shampooing', 'Smiski trying to copy how to shampoo while sitting in the corner.', 12, 499.00, 20, 'active'),
('Smiski Not Looking', 'Very shy Smiski that does not know where to look but sometimes sneaks through the hands.', 12, 499.00, 20, 'active'),
('Smiski Scrubbing', 'Smiski that loves to scrub each other. It seems that it will continue until Smiski Little is satisfied.', 12, 499.00, 20, 'active'),
('Smiski With Duck', 'A Smiski that wants to play with Duck in the bathtub but is scared to do so. Looks a little bit sad.', 12, 499.00, 20, 'active'),
('Smiski Dazed', 'A Smiski relaxing and enjoying the bath. Maybe it stayed too much inside the hot tub and became slim.', 12, 499.00, 20, 'active'),
('Smiski Looking', 'A Smiski that saw bathroom for the first time and is very surprised. Thinks it is the biggest discovery of this century.', 12, 499.00, 20, 'active'),

-- 13. Toilet Series
('Smiski Peek-A-Boo', 'Smiski that likes to peek from the inside of the Toilet Paper roll.', 13, 499.00, 20, 'active'),
('Smiski Little (Smelly)', 'These Smiski Little are always holding breath because they are very sensitive to smell.', 13, 499.00, 20, 'active'),
('Smiski Squatting', 'This Smiski is always squatting but does not know why. It turns around too much that the eyes meet occasionally.', 13, 499.00, 20, 'active'),
('Smiski Helping Out', 'A Smiski that wants to help in case the paper runs out. But does not realize that the toilet paper is too tiny.', 13, 499.00, 20, 'active'),
('Smiski Resting', 'This Smiski is always thinking something. Maybe observing the restroom or copying the people who sit on the toilet.', 13, 499.00, 20, 'active'),
('Smiski Holding In', 'A Smiski that is holding in too long to even move and does not know what to do.', 13, 499.00, 20, 'active'),

-- 14. Series 4
('Smiski Sneaking', 'Always sneaking and secretly moving. The destination is unknown. Does not like to be found.', 14, 499.00, 20, 'active'),
('Smiski Scared', 'Smiski that wants to go down but is scared to. Always looking down to see what is there.', 14, 499.00, 20, 'active'),
('Smiski Relaxing', 'A Smiski that is full and cannot move. Loves to stretch legs while leaning against the wall.', 14, 499.00, 20, 'active'),
('Smiski Lazy', 'A Smiski that is always lazy. Too lazy to even notice Little Lazy Smiski on the top.', 14, 499.00, 20, 'active'),
('Smiski Stuck', 'A Smiski who got stuck on a way to somewhere. It seems that it cannot grasp its body size.', 14, 499.00, 20, 'active'),
('Smiski Defeated', 'A Smiski that is defeated because it cannot find any favorite corner. Sometimes used as a stepping stone for other Smiski.', 14, 499.00, 20, 'active'),

-- 15. Series 3
('Smiski Bridge', 'Always deep in thought. To think flexibly, one must act flexibly!... which is the reason for this pose.', 15, 499.00, 20, 'active'),
('Smiski Peeking', 'Finds comfort in high places and likes to look down at the scenery below. Checks things out from a safe distance.', 15, 499.00, 20, 'active'),
('Smiski Climbing', 'Curious of what is waiting at the top of the hanging string, this Smiski will climb up without hesitation.', 15, 499.00, 20, 'active'),
('Smiski Little (Series 3)', 'Little Smiski have made their appearance! For some reason these little ones like to line up against the wall in this pose.', 15, 499.00, 20, 'active'),
('Smiski Hiding', 'Likes to be in small, cosy places. Often found underneath towels and blankets, looking around its surroundings.', 15, 499.00, 20, 'active'),
('Smiski Handstand', 'Always found doing a handstand against the wall. The peeking Smiski from Series 2 is always trying to copy this.', 15, 499.00, 20, 'active'),

-- 16. Series 2
('Smiski Kneeling', 'Often sits with knees folded. Has a kind heart but is a little awkward showing it in front of other people.', 16, 499.00, 20, 'active'),
('Smiski Climbing (Series 2)', 'This Smiski is action is too slow. Often spotted trying to climb over things but never quite making it.', 16, 499.00, 20, 'active'),
('Smiski Daydreaming (Series 2)', 'Likes to daydream all day long. Most of the time, this Smiski is lost in its own world.', 16, 499.00, 20, 'active'),
('Smiski Pushing', 'Always pushing things together to create the perfect hiding spot.', 16, 499.00, 20, 'active'),
('Smiski Peeking (Series 2)', 'A playful Smiski who likes to look out at the world from an upside-down point of view.', 16, 499.00, 20, 'active'),
('Smiski Listening', 'Curious about the sounds coming in from next door, this Smiski is always listening in.', 16, 499.00, 20, 'active'),

-- 17. Series 1
('Smiski Hugging Knees', 'Always in the corner hugging onto the knees, staring out into the distance pensive in thought.', 17, 499.00, 20, 'active'),
('Smiski Sitting', 'Often found sitting silently and gazing down at something. Has a wary personality and prefers being alone.', 17, 499.00, 20, 'active'),
('Smiski Looking Back', 'A Smiski that scares easily. When found, it will turn back and stare at you in surprise.', 17, 499.00, 20, 'active'),
('Smiski Lounging', 'A lazy Smiski that likes to lie down and lounge about. Does not like anything that involves moving or exercise.', 17, 499.00, 20, 'active'),
('Smiski Hiding (Series 1)', 'Hiding is his specialty. Finds all sorts of spots to hide in. Feels most at home in small, narrow places.', 17, 499.00, 20, 'active'),
('Smiski Peeking (Series 1)', 'Always hunched over and peeking in from the corner. Needs to gather up courage before approaching anything.', 17, 499.00, 20, 'active'),


-- 18. Bundle Series
('Construction Bundle Series', 'Complete set of 6 Construction Series Smiski figures featuring digging, crushing, and site work.', 18, 2994.00, 5, 'active'),
('Birthday Bundle Series', 'Complete set of 6 Birthday Series Smiski figures featuring bouquets, presents, and party celebrations.', 18, 2994.00, 5, 'active'),
('Smiski Sunday Bundle Series', 'Complete set of 6 Smiski Sunday figures featuring bubbles, paper airplanes, and sunbathing.', 18, 2994.00, 5, 'active'),
('Smiski Hippers Bundle Series', 'Complete set of 6 Smiski Hippers figures designed to attach to phones, walls, and small ledges.', 18, 3594.00, 5, 'active'),
('Moving Bundle Series', 'Complete set of 6 Moving Series Smiski figures featuring boxes, ladders, and room decor.', 18, 2994.00, 5, 'active'),
('Exercising Bundle Series', 'Complete set of 6 Exercising Series Smiski figures featuring crunches, weights, and aerobics.', 18, 2994.00, 5, 'active'),
('Dressing Bundle Series', 'Complete set of 6 Dressing Series Smiski figures struggling with sweaters, pants, and socks.', 18, 2994.00, 5, 'active'),
('@Work Bundle Series', 'Complete set of 6 @Work Series Smiski figures featuring computers, presentations, and office tasks.', 18, 2994.00, 5, 'active'),
('Museum Bundle Series', 'Complete set of 6 Museum Series Smiski figures inspired by classical art and masterpieces.', 18, 2994.00, 5, 'active'),
('Bed Bundle Series', 'Complete set of 6 Bed Series Smiski figures featuring sleeping, reading, and bedtime rest.', 18, 2994.00, 5, 'active'),
('Living Bundle Series', 'Complete set of 6 Living Series Smiski figures featuring daydreaming, playing, and relaxing at home.', 18, 2994.00, 5, 'active'),
('Bath Bundle Series', 'Complete set of 6 Bath Series Smiski figures featuring shampooing, scrubbing, and bathtub fun.', 18, 2994.00, 5, 'active'),
('Toilet Bundle Series', 'Complete set of 6 Toilet Series Smiski figures featuring peek-a-boo and restroom antics.', 18, 2994.00, 5, 'active'),
('Series 4 Bundle Series', 'Complete set of 6 original Smiski Series 4 figures featuring sneaking and lazy poses.', 18, 2994.00, 5, 'active'),
('Series 3 Bundle Series', 'Complete set of 6 original Smiski Series 3 figures featuring handstands and bridging.', 18, 2994.00, 5, 'active'),
('Series 2 Bundle Series', 'Complete set of 6 Smiski Series 2 figures featuring kneeling, daydreaming, and pushing poses.', 18, 2994.00, 5, 'active'),
('Series 1 Bundle Series', 'Complete set of 6 original Smiski Series 1 figures featuring hugging knees and classic hiding poses.', 18, 2994.00, 5, 'active');

-- Insert System Settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('shipping_cost', '120'),
('tax_rate', '12'),
('pwd_senior_discount', '20');

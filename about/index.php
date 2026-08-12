<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pageTitle = 'About Us | ' . SITE_NAME;
$bodyClass = 'about-page';

require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="about-shell" style="padding-bottom: 60px;">
  <section class="legal-hero" style="text-align: center; padding: 80px 20px; background-color: #fcfbf9;">
    <p class="legal-kicker" style="color: #c9a96e; font-weight: 600; text-transform: uppercase; letter-spacing: 2px;">Our Story</p>
    <h1 style="font-family: 'Playfair Display', serif; font-size: 3rem; margin-top: 10px; margin-bottom: 20px; color: #222;">About Azuronn</h1>
    <p class="legal-intro" style="max-width: 800px; margin: 0 auto; color: #555; font-size: 1.1rem; line-height: 1.8;">
      Timeless elegance, ethically crafted jewelry designed to celebrate life's most precious moments. We believe that true luxury lies in bespoke craftsmanship and sustainable sourcing.
    </p>
  </section>

  <div class="container-fluid" style="padding: 60px 4%; max-width: 1200px; margin: 0 auto;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 40px;">
      
      <div style="text-align: center; padding: 40px; background: #fff; border: 1px solid #eee; border-radius: 8px;">
        <i class="fas fa-gem" style="font-size: 2.5rem; color: #c9a96e; margin-bottom: 20px;"></i>
        <h2 style="font-family: 'Playfair Display', serif; font-size: 1.5rem; margin-bottom: 15px;">Bespoke Craftsmanship</h2>
        <p style="color: #666; line-height: 1.6;">
          Work one-on-one with our master jewelers to bring your dream design to life. Our pieces are crafted with certified precision, ensuring every facet reflects perfection.
        </p>
      </div>

      <div style="text-align: center; padding: 40px; background: #fff; border: 1px solid #eee; border-radius: 8px;">
        <i class="fas fa-leaf" style="font-size: 2.5rem; color: #c9a96e; margin-bottom: 20px;"></i>
        <h2 style="font-family: 'Playfair Display', serif; font-size: 1.5rem; margin-bottom: 15px;">Ethical Sourcing</h2>
        <p style="color: #666; line-height: 1.6;">
          Our lab-grown diamonds avoid the land disruption and the conflict concerns historically associated with some diamond mining regions. We partner exclusively with verified growers.
        </p>
      </div>

      <div style="text-align: center; padding: 40px; background: #fff; border: 1px solid #eee; border-radius: 8px;">
        <i class="fas fa-shield-alt" style="font-size: 2.5rem; color: #c9a96e; margin-bottom: 20px;"></i>
        <h2 style="font-family: 'Playfair Display', serif; font-size: 1.5rem; margin-bottom: 15px;">Client Services</h2>
        <p style="color: #666; line-height: 1.6;">
          From complimentary ring sizing to comprehensive cleaning and maintenance, our aftercare ensures your jewelry remains as brilliant as the day you received it.
        </p>
      </div>

    </div>

    <div style="margin-top: 80px; text-align: center;">
      <h2 style="font-family: 'Playfair Display', serif; font-size: 2.5rem; margin-bottom: 20px; color: #222;">Experience Azuronn</h2>
      <p style="color: #555; max-width: 600px; margin: 0 auto 30px; line-height: 1.6;">
        Book a private consultation with our specialists or explore our curated collection of fine jewelry.
      </p>
      <a href="<?= h(resolve_link('/shop/')) ?>" style="display: inline-block; padding: 12px 30px; background-color: #222; color: #fff; text-decoration: none; text-transform: uppercase; letter-spacing: 1px; font-size: 0.9rem; transition: background-color 0.3s;" onmouseover="this.style.backgroundColor='#c9a96e'" onmouseout="this.style.backgroundColor='#222'">Explore Collection</a>
      <a href="<?= h(resolve_link('/appointment/')) ?>" style="display: inline-block; padding: 12px 30px; background-color: transparent; border: 1px solid #222; color: #222; text-decoration: none; text-transform: uppercase; letter-spacing: 1px; font-size: 0.9rem; margin-left: 10px; transition: all 0.3s;" onmouseover="this.style.backgroundColor='#222'; this.style.color='#fff';" onmouseout="this.style.backgroundColor='transparent'; this.style.color='#222';">Book Appointment</a>
    </div>
  </div>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>

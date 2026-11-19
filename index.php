<?php
require __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'Tutoring & trusted home services';
$activePage = 'home';
require __DIR__ . '/includes/header.php';
?>
<main class="landing">
    <div class="landing-copy">
        <div class="eyebrow"><span class="eyebrow-dot"></span> GOOD PEOPLE. GOOD WORK. RIGHT HERE.</div>
        <h1>Make more room<br>for <span>better living.</span></h1>
        <p class="landing-description">Expert tutors for brighter futures. Trusted professionals for happier homes. Find the right help, all in one place.</p>
        <div class="landing-actions">
            <a class="button button-primary button-large" href="<?= e(url('Pages/services.php?module=tutoring')) ?>">Find a tutor</a>
            <a class="button button-white button-large" href="<?= e(url('Pages/services.php?module=home_services')) ?>">Book home services</a>
        </div>
        <div class="trust-line"><span>Tutoring and trusted home help, together.</span></div>
    </div>
    <div class="landing-visual" aria-label="Tutoring and home services">
        <div class="visual-orbit orbit-one"></div><div class="visual-orbit orbit-two"></div>
        <div class="visual-topline"><span>YOUR EVERYDAY, ELEVATED</span><span class="visual-live"><i></i> MADE FOR KENYA</span></div>
        <div class="visual-main" role="region" aria-label="LertFind services: tutoring, home help, secure booking and local support">
            <div class="visual-carousel">
                <article class="showcase-slide">
                    <div class="showcase-content showcase-content-front">
                        <span class="mini-label">THE RIGHT SUPPORT</span>
                        <div class="showcase-photo"><img class="landing-photo" src="<?= e(url('images/landing-tutoring.jpg')) ?>" alt="African tutor supporting students in a classroom"></div>
                        <h2>Learning support that fits your child.</h2>
                        <p>Explore tutors by subject, experience and learning format.</p>
                        <div class="visual-tags"><span>Subject-focused</span><span>Online or in person</span></div>
                    </div>
                </article>
                <article class="showcase-slide">
                    <div class="showcase-content showcase-content-back">
                        <span class="mini-label">MORE TIME FOR LIFE</span>
                        <div class="showcase-photo"><img class="landing-photo" src="<?= e(url('images/landing-home.jpg')) ?>" alt="African home-service professional preparing a meal"></div>
                        <h2>Reliable help for your home.</h2>
                        <p>Compare local professionals and find support that suits your needs.</p>
                        <div class="visual-tags"><span>Local professionals</span><span>Reviewed profiles</span></div>
                    </div>
                </article>
            </div>
            <button class="showcase-toggle" type="button" data-showcase-toggle aria-pressed="false">Pause showcase</button>
        </div>
        <div class="visual-caption"><span class="caption-line"></span><span>LEARN. BOOK. LIVE BETTER.</span><span class="caption-line"></span></div>
    </div>
    <div class="landing-bottom">
        <span><b>01</b> Find your fit</span><span><b>02</b> Choose with confidence</span><span><b>03</b> Get on with living</span>
    </div>
</main>
<section class="home-services-preview" aria-labelledby="home-services-heading">
    <div class="home-services-inner">
        <div class="home-services-heading">
            <div><div class="eyebrow">A GOOD PLACE TO START</div><h2 id="home-services-heading">What can we help with?</h2></div>
            <p>Choose the support you need and take the next step with confidence.</p>
        </div>
        <div class="home-service-options">
            <article class="home-service-option">
                <img src="<?= e(url('images/landing-tutoring.jpg')) ?>" alt="African tutor helping students learn in a classroom">
                <div class="home-service-option-copy"><span>LEARNING & GROWTH</span><h3>Find a tutor</h3><p>Explore tutors by subject and learning format to find the right support for your child.</p><a href="<?= e(url('Pages/services.php?module=tutoring')) ?>">Explore tutoring <span aria-hidden="true">→</span></a></div>
            </article>
            <article class="home-service-option">
                <img src="<?= e(url('images/landing-home.jpg')) ?>" alt="African home-service professional preparing a meal">
                <div class="home-service-option-copy"><span>HELP AROUND HOME</span><h3>Book a home service</h3><p>Browse local professionals, compare their profiles and request the help you need.</p><a href="<?= e(url('Pages/services.php?module=home_services')) ?>">Explore home services <span aria-hidden="true">→</span></a></div>
            </article>
        </div>
    </div>
</section>
<section class="home-info" id="about">
    <div class="home-info-inner">
        <div class="eyebrow">ABOUT LERTFIND</div>
        <h2>Good support makes a difference.</h2>
        <p>LertFind connects families in Kenya with tutors and home-service professionals, making it easier to find the right help for everyday needs.</p>
        <div class="home-info-points">
            <span><b>Learning</b> Find tutors by subject and learning level.</span>
            <span><b>Home</b> Discover local help for your home.</span>
            <span><b>Confidence</b> Review provider profiles before you request a booking.</span>
        </div>
    </div>
</section>
<section class="home-info how-it-works" id="how-it-works">
    <div class="home-info-inner">
        <div class="eyebrow">A SIMPLE WAY TO GET STARTED</div>
        <h2>How it works</h2>
        <div class="home-steps">
            <article><span>01</span><h3>Choose what you need</h3><p>Browse tutoring subjects or home services to find the right category.</p></article>
            <article><span>02</span><h3>Compare providers</h3><p>Explore available, approved profiles, their experience, location and service mode.</p></article>
            <article><span>03</span><h3>Send a request</h3><p>Choose a provider and share your details. They’ll respond to your request through LertFind.</p></article>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

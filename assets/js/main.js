// Mobile nav toggle
(function () {
    var toggle = document.getElementById('nav-toggle');
    var links = document.getElementById('nav-links');
    if (!toggle || !links) return;

    toggle.addEventListener('click', function () {
        var open = links.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    links.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') links.classList.remove('open');
    });
})();

// Reveal-on-scroll for cards, steps and screenshots
(function () {
    var els = document.querySelectorAll('.card, .step, .shot, .cron-card');
    if (!('IntersectionObserver' in window) || !els.length) return;

    els.forEach(function (el) { el.classList.add('reveal'); });

    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                io.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    els.forEach(function (el) { io.observe(el); });
})();

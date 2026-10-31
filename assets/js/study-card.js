/* ============================================================
   Web3edu — AI Study Card Controller
   3D-flip flashcard modal: generate, flip, countdown auto-close.
   Shared by /learn/lesson.php and /guides/lesson.php.
   ============================================================ */
(function () {
    'use strict';

    var genBtn       = document.getElementById('aiScGenerateBtn');
    var overlay      = document.getElementById('aiScOverlay');
    var closeBtn     = document.getElementById('aiScClose');
    var flip         = document.getElementById('aiScFlip');
    var frontContent = document.getElementById('aiScFrontContent');
    var frontLoading = document.getElementById('aiScFrontLoading');
    var frontError   = document.getElementById('aiScFrontError');
    var bulletsList  = document.getElementById('aiScBullets');
    var countdownEl  = document.getElementById('aiScCountdown');
    var countdownCircle = document.getElementById('aiScCountdownCircle');

    if (!genBtn || !overlay) return;

    var AUTO_CLOSE_SECONDS = 60;
    var CIRCUMFERENCE = 2 * Math.PI * 15.5; // matches r=15.5 in the SVG
    var countdownTimer = null;
    var countdownRemaining = AUTO_CLOSE_SECONDS;
    var cardData = null;
    var loaded = false;

    function resetCard() {
        flip.classList.remove('flipped');
        frontContent.style.display = '';
        frontLoading.style.display = 'none';
        frontError.style.display = 'none';
        frontError.textContent = '';
        bulletsList.innerHTML = '';
        countdownEl.style.display = 'none';
        loaded = false;
        cardData = null;
        clearInterval(countdownTimer);
    }

    function openModal() {
        overlay.classList.add('show');
        // next frame -> trigger 'in' for fade/scale transition
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { overlay.classList.add('in'); });
        });
        document.addEventListener('keydown', onEsc);
        fetchCard();
    }

    function closeModal() {
        overlay.classList.remove('in');
        clearInterval(countdownTimer);
        setTimeout(function () {
            overlay.classList.remove('show');
            resetCard();
        }, 280);
        document.removeEventListener('keydown', onEsc);
    }

    function onEsc(e) {
        if (e.key === 'Escape') closeModal();
    }

    genBtn.addEventListener('click', function () {
        resetCard();
        openModal();
    });
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });

    function fetchCard() {
        frontContent.style.display = 'none';
        frontLoading.style.display = 'flex';
        frontError.style.display = 'none';

        var formData = new FormData();
        formData.append('lesson_id', genBtn.getAttribute('data-lesson-id'));
        formData.append('lesson_type', genBtn.getAttribute('data-lesson-type'));

        fetch('/api/ai_study_card.php', { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                frontLoading.style.display = 'none';
                if (!data.success) {
                    frontContent.style.display = '';
                    frontError.style.display = 'block';
                    frontError.textContent = '';
                    var mainMsg = document.createElement('div');
                    mainMsg.textContent = data.error || 'Could not generate study card.';
                    frontError.appendChild(mainMsg);
                    // Technical detail (server logs this too, but showing it
                    // here means anyone hitting the bug — not just someone
                    // with error_log access — can see and report it).
                    if (data.debug) {
                        var detail = document.createElement('div');
                        detail.className = 'ai-sc-error-detail';
                        detail.textContent = data.debug;
                        frontError.appendChild(detail);
                    }
                    return;
                }
                cardData = data;
                bulletsList.innerHTML = '';
                data.bullets.forEach(function (b) {
                    var li = document.createElement('li');
                    li.textContent = b;
                    bulletsList.appendChild(li);
                });
                loaded = true;
                frontContent.style.display = '';
            })
            .catch(function () {
                frontLoading.style.display = 'none';
                frontContent.style.display = '';
                frontError.style.display = 'block';
                frontError.textContent = 'Network error. Please try again.';
            });
    }

    flip.addEventListener('click', function () {
        if (!loaded) return; // don't flip onto an empty back
        var willFlip = !flip.classList.contains('flipped');
        flip.classList.toggle('flipped');
        if (willFlip) {
            startCountdown();
        } else {
            countdownEl.style.display = 'none';
            clearInterval(countdownTimer);
        }
    });

    function startCountdown() {
        countdownRemaining = AUTO_CLOSE_SECONDS;
        countdownEl.style.display = 'block';
        countdownCircle.style.strokeDasharray = CIRCUMFERENCE;
        countdownCircle.style.strokeDashoffset = 0;
        clearInterval(countdownTimer);
        countdownTimer = setInterval(function () {
            countdownRemaining -= 1;
            var fraction = countdownRemaining / AUTO_CLOSE_SECONDS;
            countdownCircle.style.strokeDashoffset = (CIRCUMFERENCE * (1 - fraction));
            if (countdownRemaining <= 0) {
                clearInterval(countdownTimer);
                closeModal();
            }
        }, 1000);
    }
})();

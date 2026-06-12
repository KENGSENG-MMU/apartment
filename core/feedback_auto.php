<?php
/**
 * SmartVMS Auto Feedback Popup
 * Put this file in: core/feedback_auto.php
 *
 * How it works:
 * - JavaScript records resident function usage in localStorage.
 * - After the same function is used 3 times, a feedback popup appears automatically.
 * - Submitted feedback is saved into the existing resident_feedback table.
 */

if (!function_exists('smartvms_render_auto_feedback')) {
    function smartvms_render_auto_feedback(string $defaultFunctionKey = 'resident_dashboard', string $defaultFunctionName = 'Resident Dashboard', int $threshold = 3): void
    {
        $uid = (int)($_SESSION['uid'] ?? 0);
        $role = (string)($_SESSION['role'] ?? 'resident');
        $csrf = (string)($_SESSION['csrf_token'] ?? '');

        $payload = [
            'userId' => $uid,
            'role' => $role,
            'csrf' => $csrf,
            'defaultFunctionKey' => $defaultFunctionKey,
            'defaultFunctionName' => $defaultFunctionName,
            'threshold' => max(2, $threshold),
            'endpoint' => 'feedback_auto_submit.php'
        ];
        ?>

<style>
.smartvms-feedback-overlay {
    position: fixed;
    inset: 0;
    z-index: 999999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 22px;
    background: rgba(3, 7, 18, 0.68);
    backdrop-filter: blur(10px);
}

.smartvms-feedback-overlay.show {
    display: flex;
}

.smartvms-feedback-modal {
    width: min(520px, 100%);
    border-radius: 28px;
    padding: 26px;
    color: #f8fafc;
    background:
        linear-gradient(135deg, rgba(15, 23, 42, 0.96), rgba(37, 99, 235, 0.92)),
        rgba(15, 23, 42, 0.94);
    border: 1px solid rgba(255, 255, 255, 0.18);
    box-shadow: 0 28px 85px rgba(2, 6, 23, 0.62);
    transform: translateY(18px) scale(0.98);
    opacity: 0;
    transition: 0.28s ease;
    position: relative;
    overflow: hidden;
}

.smartvms-feedback-overlay.show .smartvms-feedback-modal {
    transform: translateY(0) scale(1);
    opacity: 1;
}

.smartvms-feedback-modal::after {
    content: "";
    position: absolute;
    width: 210px;
    height: 210px;
    right: -80px;
    top: -80px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.10);
    pointer-events: none;
}

.smartvms-feedback-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 13px;
    border-radius: 999px;
    color: #dbeafe;
    background: rgba(255, 255, 255, 0.10);
    border: 1px solid rgba(255, 255, 255, 0.18);
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 16px;
}

.smartvms-feedback-modal h3 {
    margin: 0 0 10px;
    font-size: 1.65rem;
    line-height: 1.1;
    font-weight: 900;
    letter-spacing: -0.04em;
}

.smartvms-feedback-modal p {
    margin: 0;
    color: rgba(241, 245, 249, 0.82);
    line-height: 1.6;
    font-size: 0.96rem;
}

.smartvms-feedback-feature {
    display: inline-block;
    margin-top: 10px;
    padding: 8px 12px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.10);
    color: #ffffff;
    font-weight: 900;
    font-size: 0.88rem;
}

.smartvms-feedback-stars {
    display: flex;
    gap: 9px;
    margin: 22px 0 16px;
}

.smartvms-feedback-star {
    width: 48px;
    height: 48px;
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    background: rgba(255, 255, 255, 0.10);
    color: rgba(255, 255, 255, 0.74);
    font-size: 1.55rem;
    cursor: pointer;
    transition: 0.2s ease;
}

.smartvms-feedback-star:hover,
.smartvms-feedback-star.active {
    background: rgba(255, 255, 255, 0.92);
    color: #f59e0b;
    transform: translateY(-3px);
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.28);
}

.smartvms-feedback-modal textarea {
    width: 100%;
    min-height: 105px;
    resize: vertical;
    border-radius: 18px;
    padding: 14px 16px;
    color: #f8fafc;
    background: rgba(15, 23, 42, 0.42);
    border: 1px solid rgba(255, 255, 255, 0.18);
    outline: none;
    font-family: inherit;
    font-size: 0.95rem;
}

.smartvms-feedback-modal textarea::placeholder {
    color: rgba(226, 232, 240, 0.55);
}

.smartvms-feedback-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 18px;
    flex-wrap: wrap;
}

.smartvms-feedback-btn {
    border: 0;
    border-radius: 999px;
    padding: 12px 20px;
    font-weight: 900;
    cursor: pointer;
    transition: 0.2s ease;
}

.smartvms-feedback-btn.primary {
    color: #ffffff;
    background: linear-gradient(135deg, #22c1e8, #2563eb);
    box-shadow: 0 15px 34px rgba(37, 99, 235, 0.28);
}

.smartvms-feedback-btn.ghost {
    color: rgba(255, 255, 255, 0.88);
    background: rgba(255, 255, 255, 0.11);
    border: 1px solid rgba(255, 255, 255, 0.14);
}

.smartvms-feedback-btn:hover {
    transform: translateY(-2px);
}

.smartvms-feedback-error {
    display: none;
    margin-top: 10px;
    color: #fecaca;
    font-weight: 800;
    font-size: 0.88rem;
}

@media (max-width: 560px) {
    .smartvms-feedback-modal { padding: 22px; }
    .smartvms-feedback-stars { gap: 6px; }
    .smartvms-feedback-star { width: 42px; height: 42px; border-radius: 14px; }
}
</style>

<div class="smartvms-feedback-overlay" id="svmsFeedbackOverlay" aria-hidden="true">
    <div class="smartvms-feedback-modal" role="dialog" aria-modal="true" aria-labelledby="svmsFeedbackTitle">
        <div class="smartvms-feedback-pill">SmartVMS Feedback</div>
        <h3 id="svmsFeedbackTitle">How was your experience?</h3>
        <p>
            You have used this function a few times. Please rate your experience so the system can improve.
            <br>
            <span class="smartvms-feedback-feature" id="svmsFeedbackFeatureName">SmartVMS Function</span>
        </p>

        <div class="smartvms-feedback-stars" id="svmsFeedbackStars" aria-label="Choose rating">
            <button type="button" class="smartvms-feedback-star" data-rating="1" aria-label="1 star">★</button>
            <button type="button" class="smartvms-feedback-star" data-rating="2" aria-label="2 stars">★</button>
            <button type="button" class="smartvms-feedback-star" data-rating="3" aria-label="3 stars">★</button>
            <button type="button" class="smartvms-feedback-star" data-rating="4" aria-label="4 stars">★</button>
            <button type="button" class="smartvms-feedback-star" data-rating="5" aria-label="5 stars">★</button>
        </div>

        <textarea id="svmsFeedbackComment" maxlength="1200" placeholder="Optional comment, for example: the booking step is clear, or the page can be faster..."></textarea>
        <div class="smartvms-feedback-error" id="svmsFeedbackError">Please choose a rating first.</div>

        <div class="smartvms-feedback-actions">
            <button type="button" class="smartvms-feedback-btn ghost" id="svmsFeedbackLater">Maybe Later</button>
            <button type="button" class="smartvms-feedback-btn primary" id="svmsFeedbackSubmit">Submit Feedback</button>
        </div>
    </div>
</div>

<script>
(function() {
    const config = <?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const storageKey = 'svms_auto_feedback_v1_' + config.role + '_' + config.userId;
    let selectedRating = 0;
    let activePrompt = null;

    function readState() {
        try {
            return JSON.parse(localStorage.getItem(storageKey)) || { counts: {}, due: null, submitted: {} };
        } catch (error) {
            return { counts: {}, due: null, submitted: {} };
        }
    }

    function saveState(state) {
        localStorage.setItem(storageKey, JSON.stringify(state));
    }

    function safeText(value) {
        return String(value || '').replace(/[<>&"']/g, function(char) {
            return {
                '<': '&lt;',
                '>': '&gt;',
                '&': '&amp;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function openFeedbackModal(prompt) {
        activePrompt = prompt;
        selectedRating = 0;

        const overlay = document.getElementById('svmsFeedbackOverlay');
        const featureName = document.getElementById('svmsFeedbackFeatureName');
        const comment = document.getElementById('svmsFeedbackComment');
        const errorBox = document.getElementById('svmsFeedbackError');

        if (!overlay || !featureName || !comment || !errorBox) {
            return;
        }

        featureName.innerHTML = safeText(prompt.functionName || config.defaultFunctionName);
        comment.value = '';
        errorBox.style.display = 'none';
        document.querySelectorAll('.smartvms-feedback-star').forEach(function(btn) {
            btn.classList.remove('active');
        });

        overlay.classList.add('show');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeFeedbackModal() {
        const overlay = document.getElementById('svmsFeedbackOverlay');
        if (overlay) {
            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden', 'true');
        }
    }

    function checkDueFeedback() {
        const state = readState();
        if (!state.due) {
            return;
        }

        const now = Date.now();
        if (state.snoozeUntil && now < state.snoozeUntil) {
            return;
        }

        setTimeout(function() {
            openFeedbackModal(state.due);
        }, 650);
    }

    window.smartvmsRecordFeatureUse = function(functionKey, functionName) {
        const key = String(functionKey || config.defaultFunctionKey).trim();
        const name = String(functionName || config.defaultFunctionName).trim();

        if (!key) {
            return;
        }

        const state = readState();
        state.counts = state.counts || {};
        state.submitted = state.submitted || {};
        state.counts[key] = (parseInt(state.counts[key] || 0, 10) + 1);

        if (state.counts[key] >= config.threshold) {
            state.due = {
                functionKey: key,
                functionName: name,
                reachedAt: Date.now()
            };
            state.counts[key] = 0;
        }

        saveState(state);
    };

    function clearDueAndReset(snooze) {
        const state = readState();
        state.due = null;
        if (snooze) {
            // Ask again after the user uses the system a few more times, not immediately.
            state.snoozeUntil = Date.now() + (60 * 1000);
        } else {
            state.snoozeUntil = 0;
        }
        saveState(state);
    }

    function submitFeedback() {
        const errorBox = document.getElementById('svmsFeedbackError');
        const commentBox = document.getElementById('svmsFeedbackComment');
        const submitBtn = document.getElementById('svmsFeedbackSubmit');

        if (!selectedRating) {
            if (errorBox) errorBox.style.display = 'block';
            return;
        }

        if (!activePrompt) {
            closeFeedbackModal();
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';
        }

        fetch(config.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                csrf_token: config.csrf,
                function_key: activePrompt.functionKey,
                function_name: activePrompt.functionName,
                rating: selectedRating,
                comment: commentBox ? commentBox.value.trim() : '',
                page_url: window.location.pathname
            })
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (!data || !data.ok) {
                throw new Error(data && data.message ? data.message : 'Unable to save feedback.');
            }

            clearDueAndReset(false);
            closeFeedbackModal();
        })
        .catch(function(error) {
            if (errorBox) {
                errorBox.textContent = error.message || 'Unable to save feedback.';
                errorBox.style.display = 'block';
            }
        })
        .finally(function() {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Feedback';
            }
        });
    }

    document.querySelectorAll('.smartvms-feedback-star').forEach(function(btn) {
        btn.addEventListener('click', function() {
            selectedRating = parseInt(btn.getAttribute('data-rating') || '0', 10);
            document.querySelectorAll('.smartvms-feedback-star').forEach(function(star) {
                const value = parseInt(star.getAttribute('data-rating') || '0', 10);
                star.classList.toggle('active', value <= selectedRating);
            });

            const errorBox = document.getElementById('svmsFeedbackError');
            if (errorBox) errorBox.style.display = 'none';
        });
    });

    const laterBtn = document.getElementById('svmsFeedbackLater');
    if (laterBtn) {
        laterBtn.addEventListener('click', function() {
            clearDueAndReset(true);
            closeFeedbackModal();
        });
    }

    const submitBtn = document.getElementById('svmsFeedbackSubmit');
    if (submitBtn) {
        submitBtn.addEventListener('click', submitFeedback);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkDueFeedback);
    } else {
        checkDueFeedback();
    }
})();
</script>
        <?php
    }
}

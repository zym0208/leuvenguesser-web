(function () {
    const config = window.ChallengeHubConfig || {};
    const listEl = document.querySelector("[data-hub-list]");
    const searchEl = document.querySelector("[data-hub-search]");
    const authorEl = document.querySelector("[data-hub-author]");
    const regionEl = document.querySelector("[data-hub-region]");
    const sortEl = document.querySelector("[data-hub-sort]");
    const challengeCache = new Map();
    const CACHE_TTL_MS = 60_000;
    const apiUrl = (path) => window.apiUrl ? window.apiUrl(path) : `${window.Leuvenguesser?.basePath || ""}${path}`;
    const USER_KEY = "lg_user";
    let activeModal = null;

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function playUrl(id) {
        const base = config.playUrl || "/play";
        return `${base}?mode=classic&challengeSetId=${encodeURIComponent(id)}`;
    }

    function getStoredUser() {
        try {
            const raw = localStorage.getItem(USER_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    function commentAuthorName() {
        const user = getStoredUser();
        return user && user.name ? String(user.name) : "Player";
    }

    function openModal(title, body, afterOpen) {
        closeModal();
        const modal = document.createElement("div");
        modal.className = "modal-backdrop";
        modal.innerHTML = `<section class="challenge-modal" role="dialog" aria-modal="true">
            <div class="modal-head">
                <h2>${escapeHtml(title)}</h2>
                <button class="icon-button" type="button" data-modal-close aria-label="Close">x</button>
            </div>
            <div class="modal-body">${body}</div>
        </section>`;
        document.body.appendChild(modal);
        activeModal = modal;
        modal.addEventListener("click", (event) => {
            if (event.target === modal || event.target.closest("[data-modal-close]")) {
                closeModal();
            }
        });
        if (afterOpen) {
            afterOpen(modal);
        }
    }

    function closeModal() {
        if (activeModal) {
            activeModal.remove();
            activeModal = null;
        }
    }

    async function loadRegions() {
        if (!regionEl) {
            return;
        }

        const res = await fetch(apiUrl("/api/challenges/regions"), { cache: "no-store" });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            return;
        }

        regionEl.innerHTML = '<option value="">All regions</option>' + data.regions.map((region) => {
            return `<option value="${escapeHtml(region.slug)}">${escapeHtml(region.name)}</option>`;
        }).join("");
    }

    function renderChallenges(challenges) {
        if (!listEl) {
            return;
        }

        if (challenges.length === 0) {
            listEl.innerHTML = '<div class="empty-state">No challenge sets match this search.</div>';
            return;
        }

        listEl.innerHTML = challenges.map((challenge) => {
            return `<article class="challenge-card">
                <div class="challenge-meta">
                    <span class="metric-pill">${escapeHtml(challenge.region_name)}</span>
                    <span class="metric-pill">${Number(challenge.round_count || 0)} rounds</span>
                </div>
                <h2>${escapeHtml(challenge.title)}</h2>
                <p>${escapeHtml(challenge.description || "No description yet.")}</p>
                <div class="challenge-meta">By ${escapeHtml(challenge.author_name)}</div>
                <div class="metric-row">
                    <span class="metric-pill">${Number(challenge.play_count || 0)} plays</span>
                    <button class="metric-pill like-button${challenge.liked_by_viewer ? " is-liked" : ""}" type="button" data-like-id="${challenge.id}">
                        ${Number(challenge.like_count || 0)} likes
                    </button>
                    <span class="metric-pill">${Number(challenge.comment_count || 0)} comments</span>
                </div>
                <div class="card-actions">
                    <a class="primary-action" href="${playUrl(challenge.id)}">Play Classic</a>
                    <button class="secondary-action" type="button" data-detail-id="${challenge.id}">Details</button>
                    <button class="secondary-action" type="button" data-comment-id="${challenge.id}">Comment</button>
                    <button class="secondary-action" type="button" data-share-id="${challenge.id}">Share</button>
                </div>
            </article>`;
        }).join("");

        bindCardActions();
    }

    async function loadChallenges() {
        if (!listEl) {
            return;
        }

        const params = new URLSearchParams({
            sort: sortEl ? sortEl.value : "recommended",
            q: searchEl ? searchEl.value.trim() : "",
            author: authorEl ? authorEl.value.trim() : "",
            region: regionEl ? regionEl.value : ""
        });
        const cacheKey = params.toString();
        const cached = challengeCache.get(cacheKey);

        if (cached && Date.now() - cached.savedAt < CACHE_TTL_MS) {
            renderChallenges(cached.challenges);
            return;
        }

        listEl.innerHTML = '<div class="empty-state">Loading challenge sets...</div>';
        const res = await fetch(apiUrl(`/api/challenges?${cacheKey}`), { cache: "no-store" });
        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.success || !Array.isArray(data.challenges)) {
            listEl.innerHTML = '<div class="empty-state">Failed to load challenge sets.</div>';
            return;
        }

        challengeCache.set(cacheKey, {
            savedAt: Date.now(),
            challenges: data.challenges
        });
        renderChallenges(data.challenges);
    }

    function bindCardActions() {
        document.querySelectorAll("[data-like-id]").forEach((button) => {
            button.addEventListener("click", async () => {
                const id = button.dataset.likeId;
                const res = await fetch(apiUrl(`/api/challenges/${id}/like`), { method: "POST", cache: "no-store" });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.success) {
                    button.classList.toggle("is-liked", Boolean(data.liked));
                    button.textContent = `${Number(data.like_count || 0)} likes`;
                    challengeCache.clear();
                }
            });
        });

        document.querySelectorAll("[data-detail-id]").forEach((button) => {
            button.addEventListener("click", async () => {
                const id = button.dataset.detailId;
                const res = await fetch(apiUrl(`/api/challenges/${id}`), { cache: "no-store" });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.success) {
                    return;
                }
                openChallengeDetail(data.challenge);
            });
        });

        document.querySelectorAll("[data-comment-id]").forEach((button) => {
            button.addEventListener("click", () => openCommentModal(button.dataset.commentId));
        });

        const shareCache = new Map();

        async function preloadShareText(id) {
            const res = await fetch(apiUrl(`/api/challenges/${id}`), { cache: "no-store" });
            const data = await res.json();

            const plural = data.challenge.play_count > 1 ? "s" : "";
            return `I've found a Leuvenguessr challenge set made by ${data.challenge.author_name} and played ${data.challenge.play_count} time${plural}. Come join me at https://a25sewt408.studev.groept.be/public/play?mode=classic&challengeSetId=${id}`;
        }

        document.querySelectorAll("[data-share-id]").forEach((button) => {
            const id = button.dataset.shareId;

            // preload
            preloadShareText(id).then((text) => {
                shareCache.set(id, text);
            });

            button.addEventListener("click", async () => {
                const text = shareCache.get(id);
                if (!text) {
                    button.textContent = "Still loading...";
                    return;
                }

                try {
                    await navigator.clipboard.writeText(text);
                    button.textContent = "Copied to clipboard";
                    setTimeout(() => (button.textContent = "Share"), 1000);
                } catch (e) {
                    console.error(e);
                    button.textContent = "Error copying";
                    setTimeout(() => (button.textContent = "Share"), 1000);
                }
            });
        });

    }

    function openChallengeDetail(challenge) {
        const comments = (challenge.comments || []).map((comment) => {
            return `<div class="comment-row">
                <strong>${escapeHtml(comment.author_name || "Player")}</strong>
                <span>${escapeHtml(comment.body)}</span>
            </div>`;
        }).join("") || '<div class="empty-state">No comments yet.</div>';

        openModal(challenge.title, `<div class="modal-stack">
            <p class="modal-description">${escapeHtml(challenge.description || "No description.")}</p>
            <div class="metric-row">
                <span class="metric-pill">${escapeHtml(challenge.region_name)}</span>
                <span class="metric-pill">${Number((challenge.locations || []).length)} images</span>
                <span class="metric-pill">${Number(challenge.play_count || 0)} plays</span>
                <span class="metric-pill">${Number(challenge.like_count || 0)} likes</span>
                <span class="metric-pill">${Number(challenge.comment_count || 0)} comments</span>
            </div>
            <h3>Comments</h3>
            <div class="comment-list">${comments}</div>
            <div class="modal-actions">
                <a class="primary-action" href="${playUrl(challenge.id)}">Play Classic</a>
                <button class="secondary-action" type="button" data-comment-from-detail="${challenge.id}">Comment</button>
            </div>
        </div>`, (modal) => {
            const commentButton = modal.querySelector("[data-comment-from-detail]");
            if (commentButton) {
                commentButton.addEventListener("click", () => openCommentModal(challenge.id));
            }
        });
    }

    function openCommentModal(id) {
        const authorName = commentAuthorName();
        openModal("Add Comment", `<form class="modal-form" data-comment-form>
            <p class="modal-description">Posting as ${escapeHtml(authorName)}</p>
            <textarea name="body" maxlength="800" placeholder="Comment" required></textarea>
            <div class="modal-actions">
                <span data-modal-status></span>
                <button class="secondary-action" type="button" data-modal-close>Cancel</button>
                <button class="primary-action" type="submit">Post Comment</button>
            </div>
        </form>`, (modal) => {
            modal.querySelector("[data-comment-form]").addEventListener("submit", async (event) => {
                event.preventDefault();
                const form = event.currentTarget;
                const body = form.elements.body.value.trim();
                if (!body) {
                    return;
                }
                const res = await fetch(apiUrl(`/api/challenges/${id}/comments`), {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ authorName, body }),
                    cache: "no-store"
                });
                if (res.ok) {
                    challengeCache.clear();
                    closeModal();
                    loadChallenges();
                } else {
                    modal.querySelector("[data-modal-status]").textContent = "Comment failed.";
                }
            });
        });
    }

    let debounceTimer = null;
    function queueLoad() {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(loadChallenges, 220);
    }

    [searchEl, authorEl, regionEl, sortEl].forEach((element) => {
        if (element) {
            element.addEventListener("input", queueLoad);
            element.addEventListener("change", queueLoad);
        }
    });

    loadRegions().then(loadChallenges);
})();

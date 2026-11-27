(function () {
    const config = window.AdminConfig || {};
    const sessionEl = document.querySelector("[data-admin-session]");
    const loginPanel = document.querySelector("[data-admin-login]");
    const loginForm = document.querySelector("[data-admin-login-form]");
    const loginStatus = document.querySelector("[data-admin-login-status]");
    const controls = document.querySelector("[data-admin-controls]");
    const summary = document.querySelector("[data-admin-summary]");
    const listEl = document.querySelector("[data-admin-list]");
    const refreshButton = document.querySelector("[data-admin-refresh]");
    const searchEl = document.querySelector("[data-admin-search]");
    const authorEl = document.querySelector("[data-admin-author]");
    const regionEl = document.querySelector("[data-admin-region]");
    const statusEl = document.querySelector("[data-admin-status]");
    const sortEl = document.querySelector("[data-admin-sort]");
    const challengeCountEl = document.querySelector("[data-admin-challenge-count]");
    const hiddenCountEl = document.querySelector("[data-admin-hidden-count]");
    const userCountEl = document.querySelector("[data-admin-user-count]");
    const USER_KEY = "lg_user";
    const api = (path) => window.apiUrl ? window.apiUrl(path) : `${window.Leuvenguesser?.basePath || ""}${path}`;
    let activeModal = null;
    let debounceTimer = null;

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function setHidden(element, hidden) {
        if (element) {
            element.hidden = hidden;
        }
    }

    function playUrl(id) {
        const base = config.playUrl || "/play";
        return `${base}?mode=classic&challengeSetId=${encodeURIComponent(id)}`;
    }

    async function parseJson(res) {
        return window.parseJsonResponse ? window.parseJsonResponse(res) : res.json().catch(() => ({}));
    }

    async function checkAdmin() {
        const res = await fetch(api("/api/admin/me"), { cache: "no-store" });
        const data = await parseJson(res);
        if (!res.ok || !data.success) {
            setHidden(loginPanel, false);
            setHidden(controls, true);
            setHidden(summary, true);
            if (sessionEl) {
                sessionEl.textContent = "Log in with an admin account.";
            }
            if (listEl) {
                listEl.innerHTML = '<div class="empty-state">Admin login required.</div>';
            }
            return false;
        }

        setHidden(loginPanel, true);
        setHidden(controls, false);
        setHidden(summary, false);
        if (sessionEl) {
            sessionEl.textContent = `Signed in as ${data.user.name}.`;
        }
        return true;
    }

    async function submitLogin(event) {
        event.preventDefault();
        const submit = loginForm.querySelector("[type=submit]");
        if (submit) {
            submit.disabled = true;
        }
        if (loginStatus) {
            loginStatus.textContent = "";
        }

        const name = loginForm.elements.name.value.trim();
        const password = loginForm.elements.password.value;
        try {
            const res = await fetch(api("/api/auth/login"), {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ name, password }),
                cache: "no-store"
            });
            const data = await parseJson(res);
            if (!res.ok || !data.success || !data.user || !data.user.isAdmin) {
                if (loginStatus) {
                    loginStatus.textContent = data.error || "This account is not an admin.";
                }
                return;
            }

            localStorage.setItem(USER_KEY, JSON.stringify(data.user));
            loginForm.reset();
            await loadAdmin();
        } catch {
            if (loginStatus) {
                loginStatus.textContent = "Network error. Please try again.";
            }
        } finally {
            if (submit) {
                submit.disabled = false;
            }
        }
    }

    function currentParams() {
        return new URLSearchParams({
            q: searchEl ? searchEl.value.trim() : "",
            author: authorEl ? authorEl.value.trim() : "",
            region: regionEl ? regionEl.value.trim() : "",
            status: statusEl ? statusEl.value : "all",
            sort: sortEl ? sortEl.value : "newest"
        });
    }

    async function loadAdmin() {
        if (!await checkAdmin()) {
            return;
        }
        await Promise.all([loadChallenges(), loadUsers()]);
    }

    async function loadChallenges() {
        if (!listEl) {
            return;
        }
        listEl.innerHTML = '<div class="empty-state">Loading challenge sets...</div>';
        const res = await fetch(api(`/api/admin/challenges?${currentParams().toString()}`), { cache: "no-store" });
        const data = await parseJson(res);
        if (!res.ok || !data.success || !Array.isArray(data.challenges)) {
            listEl.innerHTML = `<div class="empty-state">${escapeHtml(data.error || "Failed to load challenge sets.")}</div>`;
            return;
        }

        renderChallenges(data.challenges);
    }

    async function loadUsers() {
        const res = await fetch(api("/api/admin/users"), { cache: "no-store" });
        const data = await parseJson(res);
        if (res.ok && data.success && Array.isArray(data.users) && userCountEl) {
            userCountEl.textContent = String(data.users.length);
        }
    }

    function renderChallenges(challenges) {
        if (challengeCountEl) {
            challengeCountEl.textContent = String(challenges.length);
        }
        if (hiddenCountEl) {
            hiddenCountEl.textContent = String(challenges.filter((challenge) => !challenge.published).length);
        }

        if (challenges.length === 0) {
            listEl.innerHTML = '<div class="empty-state">No challenge sets match this filter.</div>';
            return;
        }

        listEl.innerHTML = challenges.map((challenge) => {
            const published = Boolean(challenge.published);
            return `<article class="challenge-card admin-card${published ? "" : " is-hidden-set"}">
                <div class="challenge-meta">
                    <span class="metric-pill">${published ? "Published" : "Hidden"}</span>
                    <span class="metric-pill">${escapeHtml(challenge.region_name)}</span>
                    <span class="metric-pill">${Number(challenge.round_count || 0)} rounds</span>
                </div>
                <h2>${escapeHtml(challenge.title)}</h2>
                <p>${escapeHtml(challenge.description || "No description yet.")}</p>
                <div class="challenge-meta">By ${escapeHtml(challenge.author_name)}${challenge.author_user_id ? ` · user #${Number(challenge.author_user_id)}` : ""}</div>
                <div class="metric-row">
                    <span class="metric-pill">${Number(challenge.play_count || 0)} plays</span>
                    <span class="metric-pill">${Number(challenge.like_count || 0)} likes</span>
                    <span class="metric-pill">${Number(challenge.comment_count || 0)} comments</span>
                </div>
                <div class="card-actions">
                    <a class="primary-action" href="${playUrl(challenge.id)}">Play</a>
                    <button class="secondary-action" type="button" data-admin-detail="${challenge.id}">Details</button>
                    <button class="secondary-action" type="button" data-admin-toggle="${challenge.id}" data-next="${published ? "unpublish" : "publish"}">${published ? "Hide" : "Publish"}</button>
                    <button class="secondary-action danger-action" type="button" data-admin-delete="${challenge.id}" data-title="${escapeHtml(challenge.title)}">Delete</button>
                </div>
            </article>`;
        }).join("");

        bindChallengeActions();
    }

    function bindChallengeActions() {
        listEl.querySelectorAll("[data-admin-detail]").forEach((button) => {
            button.addEventListener("click", () => openDetailModal(Number(button.dataset.adminDetail)));
        });
        listEl.querySelectorAll("[data-admin-toggle]").forEach((button) => {
            button.addEventListener("click", () => togglePublished(Number(button.dataset.adminToggle), button.dataset.next));
        });
        listEl.querySelectorAll("[data-admin-delete]").forEach((button) => {
            button.addEventListener("click", () => confirmDelete(Number(button.dataset.adminDelete), button.dataset.title || "this set"));
        });
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

    async function openDetailModal(id) {
        const res = await fetch(api(`/api/admin/challenges/${id}`), { cache: "no-store" });
        const data = await parseJson(res);
        if (!res.ok || !data.success || !data.challenge) {
            openModal("Details", `<div class="empty-state">${escapeHtml(data.error || "Unable to load challenge set.")}</div>`);
            return;
        }

        const challenge = data.challenge;
        const images = (challenge.locations || []).map((location, index) => `<div class="image-manage-row">
            <img src="${api(`/api/game/locations/${location.id}/photo`)}" alt="" onerror="this.style.display='none'">
            <div>
                <strong>Image ${index + 1}</strong>
                <span>${Number(location.lat).toFixed(5)}, ${Number(location.lng).toFixed(5)}</span>
            </div>
        </div>`).join("") || '<div class="empty-state">No images in this set.</div>';

        const comments = (challenge.comments || []).map((comment) => `<div class="comment-row admin-comment-row">
            <strong>${escapeHtml(comment.author_name || "Player")}</strong>
            <span>${escapeHtml(comment.body)}</span>
            <button class="secondary-action danger-action" type="button" data-admin-comment-delete="${comment.id}">Delete</button>
        </div>`).join("") || '<div class="empty-state">No comments yet.</div>';

        openModal(challenge.title, `<div class="modal-stack">
            <p class="modal-description">${escapeHtml(challenge.description || "No description.")}</p>
            <div class="metric-row">
                <span class="metric-pill">${challenge.published ? "Published" : "Hidden"}</span>
                <span class="metric-pill">${escapeHtml(challenge.region_name)}</span>
                <span class="metric-pill">${Number((challenge.locations || []).length)} images</span>
                <span class="metric-pill">${Number(challenge.play_count || 0)} plays</span>
                <span class="metric-pill">${Number(challenge.like_count || 0)} likes</span>
                <span class="metric-pill">${Number(challenge.comment_count || 0)} comments</span>
            </div>
            <h3>Images</h3>
            <div class="image-manage-list">${images}</div>
            <h3>Comments</h3>
            <div class="comment-list">${comments}</div>
        </div>`, (modal) => {
            modal.querySelectorAll("[data-admin-comment-delete]").forEach((button) => {
                button.addEventListener("click", () => deleteComment(Number(button.dataset.adminCommentDelete), id));
            });
        });
    }

    async function togglePublished(id, nextAction) {
        const res = await fetch(api(`/api/admin/challenges/${id}/${nextAction}`), { method: "POST", cache: "no-store" });
        const data = await parseJson(res);
        if (!res.ok || !data.success) {
            openModal("Update Failed", `<div class="empty-state">${escapeHtml(data.error || "Unable to update publish status.")}</div>`);
            return;
        }
        await loadChallenges();
    }

    function confirmDelete(id, title) {
        openModal("Delete Challenge Set", `<div class="modal-stack">
            <p>Delete <strong>${escapeHtml(title)}</strong>? This removes the set and its uploaded images.</p>
            <div class="modal-actions">
                <button class="secondary-action" type="button" data-modal-close>Cancel</button>
                <button class="primary-action danger-action" type="button" data-confirm-delete>Delete</button>
            </div>
            <span data-modal-status></span>
        </div>`, (modal) => {
            modal.querySelector("[data-confirm-delete]").addEventListener("click", async () => {
                const res = await fetch(api(`/api/admin/challenges/${id}`), { method: "DELETE", cache: "no-store" });
                const data = await parseJson(res);
                if (!res.ok || !data.success) {
                    modal.querySelector("[data-modal-status]").textContent = data.error || "Delete failed.";
                    return;
                }
                closeModal();
                await loadChallenges();
            });
        });
    }

    async function deleteComment(commentId, challengeId) {
        const res = await fetch(api(`/api/admin/comments/${commentId}`), { method: "DELETE", cache: "no-store" });
        const data = await parseJson(res);
        if (!res.ok || !data.success) {
            openModal("Delete Failed", `<div class="empty-state">${escapeHtml(data.error || "Unable to delete comment.")}</div>`);
            return;
        }
        openDetailModal(challengeId);
        await loadChallenges();
    }

    function queueLoad() {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(loadChallenges, 220);
    }

    [searchEl, authorEl, regionEl, statusEl, sortEl].forEach((element) => {
        if (element) {
            element.addEventListener("input", queueLoad);
            element.addEventListener("change", queueLoad);
        }
    });

    if (loginForm) {
        loginForm.addEventListener("submit", submitLogin);
    }
    if (refreshButton) {
        refreshButton.addEventListener("click", loadAdmin);
    }

    loadAdmin();
})();

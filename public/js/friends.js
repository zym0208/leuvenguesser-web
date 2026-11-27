(function () {
    const config = window.FriendsConfig || {};
    const USER_KEY = "lg_user";

    const invitationsPanel = document.querySelector("[data-invitations-panel]");
    const invitationsListEl = document.querySelector("[data-invitations-list]");
    const friendsListEl = document.querySelector("[data-friends-list]");
    const friendsCountEl = document.querySelector("[data-friends-count]");
    const searchEl = document.querySelector("[data-friends-search]");
    const discoverListEl = document.querySelector("[data-discover-list]");
    const discoverTitleEl = document.querySelector("[data-discover-title]");

    const apiUrl = (path) => {
        if (typeof window.apiUrl === "function") {
            return window.apiUrl(path);
        }
        return `${window.Leuvenguesser?.basePath || ""}${path}`;
    };

    function getStoredUser() {
        try {
            const raw = localStorage.getItem(USER_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function formatScore(value) {
        return Number(value || 0).toLocaleString();
    }

    function formatScoreLine(entry) {
        return `Leuven ${formatScore(entry.leuven_score)} · Challenge ${formatScore(entry.challenge_score)} · Duel ${formatScore(entry.duel_score)}`;
    }

    function requireUser() {
        const user = getStoredUser();
        if (user && user.id) {
            return user;
        }

        window.alert("Please log in to view and manage your friends.");
        window.location.href = config.homeUrl || "/";
        return null;
    }

    function userQuery(user) {
        return `userId=${encodeURIComponent(user.id)}`;
    }

    function acceptInviteUrl(invitationId) {
        const template = config.acceptInviteUrl || "/api/friends/invitations/0/accept";
        return template.replace("/0/", `/${invitationId}/`);
    }

    function renderInvitations(incoming, outgoing) {
        if (!invitationsPanel || !invitationsListEl) {
            return;
        }

        const hasIncoming = Array.isArray(incoming) && incoming.length > 0;
        const hasOutgoing = Array.isArray(outgoing) && outgoing.length > 0;

        if (!hasIncoming && !hasOutgoing) {
            invitationsPanel.hidden = true;
            invitationsListEl.innerHTML = "";
            return;
        }

        invitationsPanel.hidden = false;
        const parts = [];

        if (hasIncoming) {
            parts.push('<div class="invitation-group"><h3>Received</h3>');
            incoming.forEach((invite) => {
                parts.push(`<article class="invitation-row incoming">
                    <div class="friend-meta">
                        <strong>${escapeHtml(invite.name)}</strong>
                        <span>${formatScoreLine(invite)} · wants to be your friend</span>
                    </div>
                    <div class="invitation-actions">
                        <button class="icon-action remove" type="button" data-decline-id="${invite.id}" aria-label="Decline">−</button>
                        <button class="icon-action add" type="button" data-accept-id="${invite.id}" aria-label="Accept">+</button>
                    </div>
                </article>`);
            });
            parts.push("</div>");
        }

        if (hasOutgoing) {
            parts.push('<div class="invitation-group"><h3>Sent</h3>');
            outgoing.forEach((invite) => {
                parts.push(`<article class="invitation-row outgoing">
                    <div class="friend-meta">
                        <strong>${escapeHtml(invite.name)}</strong>
                        <span>Invitation pending</span>
                    </div>
                    <div class="invitation-actions">
                        <button class="icon-action remove" type="button" data-cancel-id="${invite.id}" aria-label="Cancel invitation">−</button>
                    </div>
                </article>`);
            });
            parts.push("</div>");
        }

        invitationsListEl.innerHTML = parts.join("");

        invitationsListEl.querySelectorAll("[data-accept-id]").forEach((button) => {
            button.addEventListener("click", () => acceptInvitation(user, Number(button.dataset.acceptId)));
        });
        invitationsListEl.querySelectorAll("[data-decline-id], [data-cancel-id]").forEach((button) => {
            button.addEventListener("click", () => cancelInvitation(user, Number(button.dataset.declineId || button.dataset.cancelId)));
        });
    }

    function renderFriends(friends) {
        if (!friendsListEl) {
            return;
        }

        if (friendsCountEl) {
            friendsCountEl.textContent = String(friends.length);
        }

        if (friends.length === 0) {
            friendsListEl.innerHTML = '<div class="empty-state">No friends yet. Search for players on the right.</div>';
            return;
        }

        friendsListEl.innerHTML = friends.map((friend) => {
            const topClass = friend.rank <= 3 ? " is-top" : "";
            return `<article class="friend-row${topClass}">
                <span class="friend-rank">#${friend.rank}</span>
                <div class="friend-meta">
                    <strong>${escapeHtml(friend.name)}</strong>
                    <span>Ranked by Leuven score</span>
                </div>
                <div class="friend-scores">${formatScoreLine(friend)}</div>
                <button class="icon-action remove" type="button" data-remove-id="${friend.id}" aria-label="Remove ${escapeHtml(friend.name)}">−</button>
            </article>`;
        }).join("");

        friendsListEl.querySelectorAll("[data-remove-id]").forEach((button) => {
            button.addEventListener("click", () => removeFriend(user, Number(button.dataset.removeId)));
        });
    }

    async function loadOverview(currentUser) {
        if (friendsListEl) {
            friendsListEl.innerHTML = '<div class="empty-state">Loading friends…</div>';
        }

        try {
            const res = await fetch(`${apiUrl("/api/friends")}?${userQuery(currentUser)}`, { cache: "no-store" });
            const data = await parseJsonResponse(res);
            if (!res.ok || !data.success) {
                throw new Error(data.error || "Failed to load friends");
            }

            renderInvitations(data.incomingInvitations, data.outgoingInvitations);
            renderFriends(Array.isArray(data.friends) ? data.friends : []);

            if (window.FriendsNav && typeof window.FriendsNav.refreshBadge === "function") {
                window.FriendsNav.refreshBadge();
            }
        } catch (error) {
            if (friendsListEl) {
                friendsListEl.innerHTML = `<div class="empty-state is-error">${escapeHtml(error.message || "Failed to load friends.")}</div>`;
            }
        }
    }

    function renderDiscover(users, emptyMessage) {
        if (!discoverListEl) {
            return;
        }

        if (!Array.isArray(users) || users.length === 0) {
            discoverListEl.innerHTML = `<div class="empty-state">${escapeHtml(emptyMessage)}</div>`;
            return;
        }

        discoverListEl.innerHTML = users.map((entry) => {
            return `<article class="discover-row">
                <div class="friend-meta">
                    <strong>${escapeHtml(entry.name)}</strong>
                    <span>${formatScoreLine(entry)}</span>
                </div>
                <button class="icon-action add" type="button" data-invite-id="${entry.id}" aria-label="Invite ${escapeHtml(entry.name)}">+</button>
            </article>`;
        }).join("");

        discoverListEl.querySelectorAll("[data-invite-id]").forEach((button) => {
            button.addEventListener("click", () => sendInvitation(user, Number(button.dataset.inviteId)));
        });
    }

    let user;
    let searchTimer = null;

    async function loadSuggestions() {
        if (!discoverListEl || !user) {
            return;
        }

        discoverListEl.innerHTML = '<div class="empty-state">Loading suggestions…</div>';
        if (discoverTitleEl) {
            discoverTitleEl.textContent = "Suggested players";
        }

        try {
            const res = await fetch(`${apiUrl("/api/friends/suggestions")}?${userQuery(user)}`, { cache: "no-store" });
            const data = await parseJsonResponse(res);
            if (!res.ok || !data.success) {
                throw new Error(data.error || "Failed to load suggestions");
            }

            renderDiscover(data.users, "No suggestions right now.");
        } catch (error) {
            discoverListEl.innerHTML = `<div class="empty-state is-error">${escapeHtml(error.message)}</div>`;
        }
    }

    async function runSearch(query) {
        if (!discoverListEl || !user) {
            return;
        }

        const trimmed = query.trim();
        if (trimmed === "") {
            loadSuggestions();
            return;
        }

        if (discoverTitleEl) {
            discoverTitleEl.textContent = "Search results";
        }

        discoverListEl.innerHTML = '<div class="empty-state">Searching…</div>';

        try {
            const params = new URLSearchParams({ userId: String(user.id), q: trimmed });
            const res = await fetch(`${apiUrl("/api/friends/search")}?${params.toString()}`, { cache: "no-store" });
            const data = await parseJsonResponse(res);
            if (!res.ok || !data.success) {
                throw new Error(data.error || "Search failed");
            }

            renderDiscover(data.users, "No players matched that search.");
        } catch (error) {
            discoverListEl.innerHTML = `<div class="empty-state is-error">${escapeHtml(error.message)}</div>`;
        }
    }

    async function sendInvitation(currentUser, friendId) {
        try {
            const res = await fetch(config.inviteUrl || apiUrl("/api/friends/invitations"), {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ userId: currentUser.id, friendId }),
                cache: "no-store"
            });
            const data = await parseJsonResponse(res);
            if (!res.ok || !data.success) {
                throw new Error(data.error || "Could not send invitation");
            }

            await loadOverview(currentUser);
            if (searchEl && searchEl.value.trim() !== "") {
                await runSearch(searchEl.value);
            } else {
                await loadSuggestions();
            }
        } catch (error) {
            window.alert(error.message || "Could not send invitation.");
        }
    }

    async function acceptInvitation(currentUser, invitationId) {
        try {
            const res = await fetch(`${acceptInviteUrl(invitationId)}?${userQuery(currentUser)}`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ userId: currentUser.id }),
                cache: "no-store"
            });
            const data = await parseJsonResponse(res);
            if (!res.ok || !data.success) {
                throw new Error(data.error || "Could not accept invitation");
            }

            await loadOverview(currentUser);
            if (searchEl && searchEl.value.trim() !== "") {
                await runSearch(searchEl.value);
            } else {
                await loadSuggestions();
            }
        } catch (error) {
            window.alert(error.message || "Could not accept invitation.");
        }
    }

    async function cancelInvitation(currentUser, invitationId) {
        try {
            const res = await fetch(`${apiUrl(`/api/friends/invitations/${invitationId}`)}?${userQuery(currentUser)}`, {
                method: "DELETE",
                cache: "no-store"
            });
            const data = await parseJsonResponse(res);
            if (!res.ok || !data.success) {
                throw new Error(data.error || "Could not cancel invitation");
            }

            await loadOverview(currentUser);
            if (searchEl && searchEl.value.trim() !== "") {
                await runSearch(searchEl.value);
            } else {
                await loadSuggestions();
            }
        } catch (error) {
            window.alert(error.message || "Could not cancel invitation.");
        }
    }

    async function removeFriend(currentUser, friendId) {
        try {
            const res = await fetch(`${apiUrl(`/api/friends/${friendId}`)}?${userQuery(currentUser)}`, {
                method: "DELETE",
                cache: "no-store"
            });
            const data = await parseJsonResponse(res);
            if (!res.ok || !data.success) {
                throw new Error(data.error || "Could not remove friend");
            }

            await loadOverview(currentUser);
            if (searchEl && searchEl.value.trim() !== "") {
                await runSearch(searchEl.value);
            } else {
                await loadSuggestions();
            }
        } catch (error) {
            window.alert(error.message || "Could not remove friend.");
        }
    }

    user = requireUser();
    if (!user) {
        return;
    }

    if (searchEl) {
        searchEl.addEventListener("input", () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => runSearch(searchEl.value), 280);
        });
    }

    loadOverview(user);
    loadSuggestions();
})();

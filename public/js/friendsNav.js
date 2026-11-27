(function () {
    const config = window.FriendsNavConfig || window.HomeConfig || {};
    const USER_KEY = "lg_user";

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

    function openFriends(event) {
        if (event) {
            event.preventDefault();
        }

        const user = getStoredUser();
        if (!user || !user.id) {
            window.alert("Please log in to view your friends.");
            const authTrigger = document.querySelector("[data-open-auth-modal]");
            if (authTrigger) {
                authTrigger.click();
            }
            return;
        }

        window.location.href = config.friendsUrl || "/friends";
    }

    function ensureBadgeElement(link) {
        let badge = link.querySelector(".friends-nav-badge");
        if (!badge) {
            badge = document.createElement("span");
            badge.className = "friends-nav-badge";
            badge.hidden = true;
            link.appendChild(badge);
        }
        return badge;
    }

    async function refreshBadge() {
        const user = getStoredUser();
        const links = document.querySelectorAll("[data-friends-nav], [data-open-friends]");

        if (!user || !user.id) {
            links.forEach((link) => {
                const badge = link.querySelector(".friends-nav-badge");
                if (badge) {
                    badge.hidden = true;
                }
            });
            return;
        }

        try {
            const url = config.notificationsUrl || apiUrl("/api/friends/notifications/count");
            const res = await fetch(`${url}?userId=${encodeURIComponent(user.id)}`, { cache: "no-store" });
            const data = await parseJsonResponse(res);
            const count = res.ok && data.success ? Number(data.count || 0) : 0;

            links.forEach((link) => {
                const badge = ensureBadgeElement(link);
                if (count > 0) {
                    badge.textContent = count > 9 ? "9+" : String(count);
                    badge.hidden = false;
                } else {
                    badge.hidden = true;
                }
            });
        } catch {
            // Ignore badge errors on secondary pages.
        }
    }

    document.querySelectorAll("[data-open-friends]").forEach((button) => {
        if (button.tagName === "A" && button.getAttribute("href")) {
            button.addEventListener("click", openFriends);
        } else {
            button.addEventListener("click", openFriends);
        }
    });

    document.querySelectorAll("[data-friends-nav]").forEach((link) => {
        if (!link.hasAttribute("data-open-friends")) {
            link.addEventListener("click", (event) => {
                const user = getStoredUser();
                if (!user || !user.id) {
                    event.preventDefault();
                    window.alert("Please log in to view your friends.");
                }
            });
        }
    });

    refreshBadge();
    window.setInterval(refreshBadge, 30000);

    window.FriendsNav = {
        refreshBadge,
        openFriends
    };
})();

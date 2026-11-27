(function () {
    const USER_KEY = "lg_user";
    const links = Array.from(document.querySelectorAll("[data-admin-nav]"));

    function applyAdminVisible(visible) {
        links.forEach((link) => {
            link.hidden = !visible;
        });
    }

    function notifyAdminState(isAdmin) {
        document.dispatchEvent(new CustomEvent("adminStateChange", { detail: { isAdmin } }));
    }

    function storedAdminState() {
        try {
            const raw = localStorage.getItem(USER_KEY);
            const user = raw ? JSON.parse(raw) : null;
            return Boolean(user && user.isAdmin);
        } catch {
            return false;
        }
    }

    function saveUser(user) {
        try {
            localStorage.setItem(USER_KEY, JSON.stringify(user));
        } catch {
            // Session state is still authoritative.
        }
    }

    async function refreshFromSession() {
        const basePath = window.Leuvenguesser && typeof window.Leuvenguesser.basePath === "string"
            ? window.Leuvenguesser.basePath
            : "";

        try {
            const res = await fetch(`${basePath}/api/auth/me`, { cache: "no-store" });
            const data = await res.json().catch(() => ({}));
            const isAdmin = Boolean(res.ok && data.success && data.user && data.user.isAdmin);
            if (isAdmin) {
                saveUser(data.user);
            }
            applyAdminVisible(isAdmin);
            notifyAdminState(isAdmin);
        } catch {
            const isAdmin = storedAdminState();
            applyAdminVisible(isAdmin);
            notifyAdminState(isAdmin);
        }
    }

    applyAdminVisible(storedAdminState());
    refreshFromSession();
})();
